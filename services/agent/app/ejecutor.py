"""Cliente de Piston: el agente ejecuta el código que genera antes de entregarlo."""

import re
from dataclasses import dataclass
from typing import Protocol

import httpx

LENGUAJES = {"c": "c", "cpp": "c++", "python": "python"}


@dataclass
class Ejecucion:
    compilo: bool
    salida: str
    errores: str
    codigo: int | None
    excedio_limite: bool


class Ejecutor(Protocol):
    async def ejecutar(self, lenguaje: str, codigo: str, entrada: str = "") -> Ejecucion: ...


class EjecutorPiston:
    def __init__(self, url: str) -> None:
        self.url = url.rstrip("/")

    async def ejecutar(self, lenguaje: str, codigo: str, entrada: str = "") -> Ejecucion:
        async with httpx.AsyncClient(timeout=30) as http:
            r = await http.post(
                f"{self.url}/api/v2/execute",
                json={
                    "language": LENGUAJES[lenguaje],
                    "version": "*",
                    "files": [{"content": codigo}],
                    "stdin": entrada,
                    "compile_timeout": 10000,
                    "run_timeout": 3000,
                    "run_memory_limit": 128_000_000,
                },
            )
            r.raise_for_status()
            d = r.json()

        compilacion = d.get("compile") or {}
        if compilacion and compilacion.get("code") not in (0, None):
            return Ejecucion(False, "", compilacion.get("stderr", "") or compilacion.get("output", ""), None, False)
        run = d["run"]
        excedio = run.get("signal") in ("SIGKILL", "SIGXCPU")
        return Ejecucion(True, run.get("stdout", ""), run.get("stderr", ""), run.get("code"), excedio)


def normalizar(texto: str) -> str:
    """Misma comparación que ComparadorSalida en Laravel: sin \\r, sin espacios al final de línea ni líneas vacías finales."""
    lineas = [re.sub(r"[ \t]+$", "", l) for l in texto.replace("\r\n", "\n").replace("\r", "\n").split("\n")]
    return "\n".join(lineas).rstrip("\n")


NOMBRE_DE_ARCHIVO = re.compile(r"\s*[\w./\\-]+\.(txt|bin|dat|csv|in|out|log)\s*", re.I)
FALLA_AL_ABRIR = re.compile(r"abrir|open|no such file|archivo|fichero", re.I)


async def explicar_falla(ejecutor: Ejecutor, lenguaje: str, codigo: str, casos: list[dict]) -> str | None:
    """El primer caso que falla, con lo que imprimió el programa: sin esto, el modelo solo sabe «0 de 1» y no puede
    decidir si corregir el código o la salida esperada."""
    corto = lambda s: repr(normalizar(s)[:200])  # noqa: E731 — repr deja ver los saltos de línea
    for caso in casos:
        e = await ejecutor.ejecutar(lenguaje, codigo, caso["entrada"])
        if not e.compilo:
            return None  # el error de compilación ya se informa aparte
        if e.excedio_limite:
            return f"Con la entrada {corto(caso['entrada'])} se excedió el tiempo o la memoria."
        if e.codigo != 0:
            falla = f"Con la entrada {corto(caso['entrada'])} terminó con código {e.codigo}: {(e.errores or e.salida)[:200]}"
            if NOMBRE_DE_ARCHIVO.fullmatch(caso["entrada"] or ""):
                falla += (" La «entrada» de un caso son los datos que el programa lee por stdin, no el nombre de un archivo:"
                          " no existe ningún archivo previo. Pon los datos en «entrada»; si el tema son archivos, el programa"
                          " los escribe en su propio archivo y luego lo vuelve a abrir para leerlo.")
            elif FALLA_AL_ABRIR.search(f"{e.salida} {e.errores}"):
                falla += (" El programa intenta abrir un archivo que no existe: no hay archivos previos. Primero debe leer"
                          " los datos de stdin y escribirlos con fopen(\"datos.txt\", \"w\"), cerrarlo, y solo entonces"
                          " abrirlo con fopen(\"datos.txt\", \"r\") para leerlo.")
            return falla
        if normalizar(e.salida) != normalizar(caso["salida_esperada"]):
            return (f"Con la entrada {corto(caso['entrada'])} se esperaba {corto(caso['salida_esperada'])} y el programa "
                    f"imprimió {corto(e.salida)}. Corrige la solución o la salida esperada, según cuál esté mal.")
    return None


async def probar(ejecutor: Ejecutor, lenguaje: str, codigo: str, casos: list[dict]) -> tuple[int, int, str | None]:
    """(aprobados, total, error de compilación)."""
    aprobados = 0
    for caso in casos:
        e = await ejecutor.ejecutar(lenguaje, codigo, caso["entrada"])
        if not e.compilo:
            return 0, len(casos), e.errores[:500]
        if not e.excedio_limite and e.codigo == 0 and normalizar(e.salida) == normalizar(caso["salida_esperada"]):
            aprobados += 1
    return aprobados, len(casos), None
