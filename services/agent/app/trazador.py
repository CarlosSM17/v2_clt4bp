"""Cliente del trazador (services/trazador): calcula los pasos reales de una traza ejecutando el programa con gdb.

Los pasos que escriba el modelo se descartan: un modelo no puede «ejecutar» código mentalmente y su traza no sigue
el orden real (medido: recorría las líneas en orden de texto e inventaba salidas). Aquí se reemplazan siempre por
los que produce la ejecución, una línea JSON por paso.
"""

import json
import re
from dataclasses import dataclass

import httpx

from app.proveedor.base import ProveedorNoDisponible

TRAZA = re.compile(r"```[ \t]*traza[^\n]*\n(.*?)```", re.S)
LENGUAJES = {"c": "c", "cpp": "cpp", "c++": "cpp", "python": "python"}


@dataclass
class Bloque:
    titulo: str
    entrada: str
    lenguaje: str | None
    codigo: str


def leer_bloque(texto: str) -> tuple[Bloque | None, str | None]:
    """Encabezado y código; los pasos (si los trae) se ignoran porque se recalculan."""
    partes = re.split(r"^\s*---\s*$", texto, flags=re.M)
    if len(partes) not in (2, 3):
        return None, "debe tener un encabezado y el código, separados por una línea «---»"
    campos: dict[str, str] = {}
    for linea in partes[0].splitlines():
        clave, igual, valor = linea.partition(":")
        if igual:
            campos[clave.strip().lower()] = valor.strip()
    codigo = partes[1].strip("\n")
    if not codigo.strip():
        return None, "la sección de código está vacía"
    lenguaje = LENGUAJES.get(campos.get("lenguaje", "").lower())
    return Bloque(campos.get("titulo", ""), campos.get("entrada", "").replace("\\n", "\n"), lenguaje, codigo), None


def escribir_bloque(b: Bloque, lenguaje: str, resultado: dict) -> str:
    encabezado = [f"titulo: {b.titulo}" if b.titulo else "titulo: Traza del programa", f"lenguaje: {lenguaje}"]
    if b.entrada:
        encabezado.append("entrada: " + b.entrada.replace("\n", "\\n"))
    if resultado.get("truncada"):
        encabezado.append(f"aviso: se muestran los primeros {len(resultado['pasos'])} pasos")
    if resultado.get("error"):
        encabezado.append(f"aviso: {resultado['error']}")
    pasos = [json.dumps(p, ensure_ascii=False, separators=(",", ":")) for p in resultado["pasos"]]
    return "\n".join([*encabezado, "---", b.codigo, "---", *pasos]) + "\n"


class ClienteTrazador:
    def __init__(self, url: str, transporte: httpx.AsyncBaseTransport | None = None) -> None:
        self.cliente = httpx.AsyncClient(base_url=url, timeout=httpx.Timeout(60, connect=5), transport=transporte)

    async def trazar(self, lenguaje: str, codigo: str, entrada: str) -> dict:
        try:
            r = await self.cliente.post("/trazar", json={"lenguaje": lenguaje, "codigo": codigo, "entrada": entrada})
            r.raise_for_status()
        except httpx.HTTPError as e:
            raise ProveedorNoDisponible(f"El trazador no responde ({e.__class__.__name__}).") from e
        return r.json()

    async def completar(self, texto: str, lenguaje_curso: str) -> tuple[str | None, str | None]:
        """(bloque con los pasos reales, None) o (None, motivo por el que no se pudo trazar)."""
        b, error = leer_bloque(texto)
        if error:
            return None, error
        lenguaje = b.lenguaje or lenguaje_curso
        r = await self.trazar(lenguaje, b.codigo, b.entrada)
        if not r["pasos"]:
            return None, r.get("error") or "el programa no ejecutó ninguna línea"
        return escribir_bloque(b, lenguaje, r), None

    async def completar_md(self, md: str, lenguaje_curso: str, sin_traza_si_falla: bool = False) -> tuple[str, list[str]]:
        """Recalcula todas las trazas de un Markdown. Devuelve el Markdown nuevo y los errores encontrados. Con
        `sin_traza_si_falla`, una traza que no se puede ejecutar queda como bloque de código, sin reproductor."""
        errores: list[str] = []
        partes, fin = [], 0
        for m in TRAZA.finditer(md):
            nuevo, error = await self.completar(m.group(1), lenguaje_curso)
            bloque = leer_bloque(m.group(1))[0] if error and sin_traza_si_falla else None
            if bloque:
                errores.append(error)
                partes += [md[fin : m.start()], f"```{bloque.lenguaje or lenguaje_curso}\n{bloque.codigo}\n```"]
                fin = m.end()
                continue
            partes.append(md[fin : m.start(1)])
            if error:
                errores.append(error)
                partes.append(m.group(1))
            else:
                partes.append(nuevo)
            fin = m.end(1)
        partes.append(md[fin:])
        return "".join(partes), errores
