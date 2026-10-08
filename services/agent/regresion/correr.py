"""Conjunto de regresión del agente: corre los casos con el modelo real y deja resultados comparables.

Uso (desde services/agent, con PISTON_URL en .env y Ollama o ANTHROPIC_API_KEY según el proveedor):
    uv run python -m regresion.correr                        # todos los casos, con PROVEEDOR de .env
    uv run python -m regresion.correr 01 03                  # solo los que empiezan con 01 o 03
    uv run python -m regresion.correr --proveedor claude     # para comparar proveedores con la rúbrica

Con Claude cuesta tokens; en local, tiempo. Córrelo cuando cambies el prompt, una plantilla o el modelo.
"""

import argparse
import asyncio
import csv
import json
from datetime import datetime
from pathlib import Path
from typing import Any

from app.config import ajustes
from app.ejecutor import EjecutorPiston
from app.orquestador import Orquestador
from app.proveedor import crear_proveedor
from app.solicitud import SolicitudGeneracion
from app.trazador import ClienteTrazador

DIR = Path(__file__).parent
RAIZ = DIR.parents[2]  # raíz del repositorio


def cargar_casos(filtros: list[str]) -> list[dict[str, Any]]:
    casos = []
    for ruta in sorted((DIR / "casos").glob("*.json")):
        if filtros and not any(ruta.stem.startswith(f) for f in filtros):
            continue
        caso = json.loads(ruta.read_text(encoding="utf-8"))
        sol = caso["solicitud"]
        if "diseno_desde" in sol:  # reutiliza un ejemplo de packages/contracts en lugar de copiarlo
            sol["diseno"] = json.loads((RAIZ / sol.pop("diseno_desde")).read_text(encoding="utf-8"))
        casos.append({"id": ruta.stem, **caso})
    return casos


async def ejecutar(orq: Orquestador, casos: list[dict[str, Any]], destino: Path) -> list[dict[str, Any]]:
    destino.mkdir(parents=True, exist_ok=True)
    filas = []
    for caso in casos:
        r = await orq.generar(SolicitudGeneracion.model_validate(caso["solicitud"]))
        (destino / f"{caso['id']}.json").write_text(r.model_dump_json(indent=2), encoding="utf-8")
        fallas = [v for v in r.validaciones if not v.ok]
        filas.append({
            "caso": caso["id"],
            "plantilla": r.plantilla,
            "modelo": r.modelo,
            "prompt": r.version_prompt,
            "intentos": r.intentos,
            "bloqueantes": sum(v.bloqueante for v in fallas),
            "avisos": sum(not v.bloqueante for v in fallas),
            "elementos": len(r.elementos),
            "costo_usd": round(r.uso.costo_usd, 4),
            "segundos": round(r.duracion_ms / 1000, 1),
            "tokens_cache_lectura": r.uso.cache_lectura,
        })
        print(f"{caso['id']}: {filas[-1]['bloqueantes']} bloqueantes, {r.intentos} intento(s), US$ {filas[-1]['costo_usd']}")
    with (destino / "resumen.csv").open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(filas[0]))
        w.writeheader()
        w.writerows(filas)
    return filas


def main() -> None:
    args = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    args.add_argument("casos", nargs="*", help="prefijos de los casos a correr (todos si se omite)")
    args.add_argument("--proveedor", choices=["local", "claude"], help="por omisión, PROVEEDOR de .env")
    opciones = args.parse_args()

    a = ajustes()
    if opciones.proveedor:
        a = a.model_copy(update={"proveedor": opciones.proveedor})
    orq = Orquestador(crear_proveedor(a), EjecutorPiston(a.piston_url), a, ClienteTrazador(a.trazador_url))
    destino = DIR / "resultados" / f"{datetime.now():%Y-%m-%d-%H%M}-{a.proveedor}"
    filas = asyncio.run(ejecutar(orq, cargar_casos(opciones.casos), destino))
    limpios = sum(f["bloqueantes"] == 0 for f in filas)
    print(f"\n{limpios}/{len(filas)} casos sin problemas bloqueantes · US$ {sum(f['costo_usd'] for f in filas):.2f} · {destino}")


if __name__ == "__main__":
    main()
