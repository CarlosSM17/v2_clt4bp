"""Conector del agente local con la plataforma en la nube (ADR 0008).

Con Laravel en la nube (Railway) y el agente en el equipo del instructor, la plataforma no puede abrir una conexión
hacia el agente (NAT, cortafuegos, sin IP pública). El conector la abre al revés, siempre de salida y por HTTPS:
pregunta por trabajo con un sondeo largo (`GET /api/v1/relevo/siguiente`), reenvía cada solicitud al agente de este
equipo tal cual y devuelve su respuesta (`POST /api/v1/relevo/{id}/respuesta`). Funciona en cualquier red con salida
a internet y no necesita túneles ni puertos abiertos.

    uv run python -m app.relevo
    variables: RELEVO_URL (https://tu-app.up.railway.app), AGENTE_TOKEN (el mismo de la plataforma y del agente),
               AGENTE_LOCAL_URL (http://127.0.0.1:8100), RELEVO_HILOS (3)

Varios hilos: una generación tarda minutos y, mientras, la consola sigue pudiendo verificar o calcular trazas.
"""

import asyncio
import json
import logging
import re

import httpx
from pydantic_settings import BaseSettings, SettingsConfigDict

log = logging.getLogger("relevo")

# Solo rutas del agente: aunque el token se filtrara, la plataforma no podría pedirle otra cosa a este equipo
RUTA_PERMITIDA = re.compile(r"^/v1/[a-z0-9_-]+(/[a-z0-9_-]+)*$")


class AjustesRelevo(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    relevo_url: str
    agente_token: str
    agente_local_url: str = "http://127.0.0.1:8100"
    relevo_hilos: int = 3
    relevo_espera: int = 25  # segundos del sondeo largo (la plataforma acepta hasta 25)


def _error(estado: int, detalle: str) -> tuple[int, str]:
    return estado, json.dumps({"detail": detalle}, ensure_ascii=False)


async def atender(plataforma: httpx.AsyncClient, local: httpx.AsyncClient, ajustes: AjustesRelevo, t: dict) -> tuple[int, str]:
    """Reenvía una solicitud al agente de este equipo y devuelve (estado, cuerpo) tal como respondió."""
    if t.get("metodo") not in ("GET", "POST") or not RUTA_PERMITIDA.match(str(t.get("ruta", ""))):
        return _error(400, "El conector solo reenvía rutas /v1/ del agente.")
    url = ajustes.agente_local_url.rstrip("/") + t["ruta"]
    cabeceras = {"X-Agente-Token": ajustes.agente_token, "Accept": "application/json"}
    plazo = httpx.Timeout(float(t.get("segundos") or 900) + 5, connect=5)
    try:
        if t.get("archivo"):
            documento = await plataforma.get(f"/api/v1/relevo/{t['id']}/archivo", timeout=120)
            documento.raise_for_status()
            r = await local.post(url, headers=cabeceras, timeout=plazo,
                                 files={"archivo": (t["archivo"], documento.content, "application/pdf")})
        elif t["metodo"] == "GET":
            r = await local.get(url, headers=cabeceras, timeout=plazo)
        else:
            # El cuerpo viaja como texto: decodificarlo y volver a codificarlo podría cambiar {} por [] o los números
            r = await local.post(url, headers={**cabeceras, "Content-Type": "application/json"}, timeout=plazo,
                                 content=(t.get("cuerpo") or "{}").encode("utf-8"))
        return r.status_code, r.text
    except httpx.TimeoutException:
        return _error(504, "El agente local no terminó a tiempo.")
    except httpx.HTTPError as e:
        return _error(503, f"El agente local no responde en {ajustes.agente_local_url} ({e.__class__.__name__}): ¿está encendido?")


async def hilo(n: int, plataforma: httpx.AsyncClient, local: httpx.AsyncClient, ajustes: AjustesRelevo, detener: asyncio.Event) -> None:
    pausa = 1.0
    while not detener.is_set():
        try:
            r = await plataforma.get("/api/v1/relevo/siguiente", params={"espera": ajustes.relevo_espera},
                                     timeout=ajustes.relevo_espera + 20)
            if r.status_code == 204:
                pausa = 1.0
                continue
            if r.status_code in (401, 403, 404):
                motivo = ("la plataforma no está en modo relevo (AGENTE_MODO=relevo)" if r.status_code == 404
                          else "la plataforma rechazó el token: AGENTE_TOKEN debe ser el mismo en ambos lados")
                log.error("Hilo %d: %s; reintento en 30 s.", n, motivo)
                await _dormir(detener, 30)
                continue
            r.raise_for_status()
            t = r.json()
            log.info("Hilo %d: → %s %s", n, t["metodo"], t["ruta"])
            estado, cuerpo = await atender(plataforma, local, ajustes, t)
            respuesta = await plataforma.post(f"/api/v1/relevo/{t['id']}/respuesta", json={"estado": estado, "cuerpo": cuerpo}, timeout=60)
            if respuesta.status_code in (404, 409):
                log.warning("Hilo %d: la plataforma ya no esperaba %s %s (venció el plazo).", n, t["metodo"], t["ruta"])
            else:
                respuesta.raise_for_status()
                log.info("Hilo %d: ← %s %s → %d", n, t["metodo"], t["ruta"], estado)
            pausa = 1.0
        except (httpx.HTTPError, ValueError) as e:
            log.warning("Hilo %d: sin conexión con la plataforma (%s); reintento en %.0f s.", n, e.__class__.__name__, pausa)
            await _dormir(detener, pausa)
            pausa = min(pausa * 2, 30)


async def _dormir(detener: asyncio.Event, segundos: float) -> None:
    try:
        await asyncio.wait_for(detener.wait(), timeout=segundos)
    except TimeoutError:
        pass


async def correr(ajustes: AjustesRelevo, detener: asyncio.Event | None = None) -> None:
    detener = detener or asyncio.Event()
    cabeceras = {"X-Agente-Token": ajustes.agente_token, "Accept": "application/json"}
    async with httpx.AsyncClient(base_url=ajustes.relevo_url.rstrip("/"), headers=cabeceras) as plataforma, httpx.AsyncClient() as local:
        try:
            salud = (await local.get(ajustes.agente_local_url.rstrip("/") + "/salud", timeout=5)).json()
            log.info("Agente local en %s: proveedor %s, prompt %s.", ajustes.agente_local_url, salud.get("proveedor"), salud.get("prompt"))
        except (httpx.HTTPError, ValueError):
            log.warning("El agente local no responde en %s todavía: las solicitudes fallarán hasta que arranque.", ajustes.agente_local_url)
        log.info("Conectado a %s con %d hilos; esperando trabajo.", ajustes.relevo_url, ajustes.relevo_hilos)
        await asyncio.gather(*(hilo(n, plataforma, local, ajustes, detener) for n in range(1, ajustes.relevo_hilos + 1)))


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx").setLevel(logging.WARNING)  # una línea por petición del sondeo: ruido
    try:
        asyncio.run(correr(AjustesRelevo()))
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
