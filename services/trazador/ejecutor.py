"""Ejecución de programas con la forma de la API de Piston, para donde Piston no puede correr (ADR 0008).

Piston aísla cada ejecución con isolate y exige un contenedor privilegiado; Railway no los permite. Este contenedor ya
tiene gcc, g++ y Python, corre sin privilegios y sin secretos: aquí cada programa corre como un proceso hijo con
límites de CPU, memoria, tamaño de salida y procesos, en una carpeta temporal propia. Habla el subconjunto de la API
de Piston que usan Laravel (`PistonEjecutor`) y el agente (`EjecutorPiston`), así que basta con apuntar PISTON_URL aquí.

    POST /api/v2/execute  {"language": "c" | "c++" | "python", "version": "*", "files": [{"name"?, "content"}],
                           "stdin", "compile_timeout", "run_timeout" (ms), "run_memory_limit" (bytes)}
      → {"language", "version", "compile"?: {stdout, stderr, code, signal, output}, "run": {…lo mismo}}
    GET /api/v2/runtimes

Diferencia con Piston: sin isolate, el programa comparte el núcleo y la red del contenedor. Por eso el contenedor no
guarda secretos ni se publica a internet (solo red privada), y los límites de aquí son el techo de cualquier llamada.
"""

import math
import os
import resource
import signal
import subprocess
import tempfile
import threading
from functools import cache
from pathlib import Path

# Techo de cualquier llamada (como el piston.yml de producción): nadie puede pedir más
MAX_COMPILACION_MS, MAX_EJECUCION_MS, MAX_MEMORIA = 15_000, 5_000, 256 * 1024 * 1024
MAX_SALIDA = int(os.environ.get("EJECUTOR_MAX_SALIDA", "65536"))  # caracteres que se devuelven de cada flujo
MAX_ARCHIVO = 1 << 20  # lo que el programa puede escribir (stdout incluido): 1 MB y lo mata SIGXFSZ
SIMULTANEAS = threading.BoundedSemaphore(int(os.environ.get("EJECUTOR_SIMULTANEAS", "8")))

LENGUAJES = {"c": "c", "gcc": "c", "c++": "c++", "cpp": "c++", "g++": "c++", "python": "python", "python3": "python", "py": "python"}
FUENTE = {"c": "main.c", "c++": "main.cpp", "python": "main.py"}
COMPILAR = {"c": ["gcc", "-std=c17"], "c++": ["g++", "-std=c++17"]}


@cache
def version(lenguaje: str) -> str:
    orden = ["python3", "-c", "import platform; print(platform.python_version())"] if lenguaje == "python" else ["gcc", "-dumpfullversion"]
    try:
        return subprocess.run(orden, capture_output=True, text=True, timeout=10).stdout.strip() or "?"
    except (OSError, subprocess.SubprocessError):
        return "?"


def runtimes() -> list[dict]:
    return [
        {"language": "c", "version": version("c"), "aliases": ["gcc"], "runtime": "gcc"},
        {"language": "c++", "version": version("c++"), "aliases": ["cpp", "g++"], "runtime": "gcc"},
        {"language": "python", "version": version("python"), "aliases": ["py", "python3"]},
    ]


def _tareas_del_usuario() -> int:
    """Procesos e hilos del usuario del contenedor. RLIMIT_NPROC los cuenta todos, también los hilos de este servidor:
    un límite fijo haría fallar a un programa legítimo cuando hay muchas peticiones esperando turno."""
    total = 0
    for pid in os.listdir("/proc"):
        if pid.isdigit():
            try:
                total += len(os.listdir(f"/proc/{pid}/task"))
            except OSError:
                pass  # el proceso terminó mientras se contaba
    return total


def _limites(cpu_s: int, memoria: int | None, procesos: int | None):
    def aplicar() -> None:
        os.setsid()  # su propio grupo: al vencer el plazo se mata a todos sus hijos
        resource.setrlimit(resource.RLIMIT_CPU, (cpu_s, cpu_s + 1))
        resource.setrlimit(resource.RLIMIT_FSIZE, (MAX_ARCHIVO, MAX_ARCHIVO))
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        if procesos:
            resource.setrlimit(resource.RLIMIT_NPROC, (procesos, procesos))
        if memoria:
            resource.setrlimit(resource.RLIMIT_AS, (memoria, memoria))

    return aplicar


def _correr(orden: list[str], carpeta: Path, entrada: str, limite_ms: int, memoria: int | None, procesos: int | None,
            idioma: str = "C.UTF-8") -> dict:
    """Corre una orden con la entrada desde un archivo y la salida a archivos (no a tuberías: un ciclo que imprime sin
    fin llenaría la memoria del servidor; con archivos, RLIMIT_FSIZE lo detiene en 1 MB)."""
    (carpeta / "entrada.txt").write_text(entrada, encoding="utf-8")
    entorno = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(carpeta), "LANG": idioma, "LC_ALL": idioma, "PYTHONIOENCODING": "utf-8"}
    with open(carpeta / "entrada.txt", "rb") as fin, open(carpeta / "stdout.txt", "wb") as fout, open(carpeta / "stderr.txt", "wb") as ferr:
        proceso = subprocess.Popen(orden, stdin=fin, stdout=fout, stderr=ferr, cwd=carpeta, env=entorno,
                                   preexec_fn=_limites(math.ceil(limite_ms / 1000), memoria, procesos))
        try:
            codigo = proceso.wait(timeout=limite_ms / 1000 + 1)  # reloj de pared: también lo que duerme o espera
            vencido = False
        except subprocess.TimeoutExpired:
            os.killpg(proceso.pid, signal.SIGKILL)
            proceso.wait()
            codigo, vencido = None, True
    salida = (carpeta / "stdout.txt").read_bytes()[:MAX_SALIDA].decode("utf-8", "replace")
    errores = (carpeta / "stderr.txt").read_bytes()[:MAX_SALIDA].decode("utf-8", "replace")
    senal = None
    if vencido:
        senal = "SIGKILL"
    elif codigo is not None and codigo < 0:
        # Como Piston: lo que rebasa tiempo o memoria muere con SIGKILL (Laravel lo lee como «excedió el límite»)
        senal = "SIGKILL" if -codigo in (signal.SIGXCPU, signal.SIGKILL) else signal.Signals(-codigo).name
        codigo = None
    return {"stdout": salida, "stderr": errores, "code": codigo, "signal": senal, "output": salida + errores}


def ejecutar(datos: dict) -> tuple[int, dict]:
    lenguaje = LENGUAJES.get(str(datos.get("language", "")).lower())
    archivos = datos.get("files") or []
    if not lenguaje:
        return 400, {"message": f"runtime is unknown: {datos.get('language')}"}
    if not archivos or not isinstance(archivos[0], dict) or not isinstance(archivos[0].get("content"), str):
        return 400, {"message": "files[0].content is required"}
    codigo, entrada = archivos[0]["content"], str(datos.get("stdin") or "")
    if len(codigo) > 100_000 or len(entrada) > 100_000:
        return 400, {"message": "code or stdin too large"}
    compilar_ms = min(int(datos.get("compile_timeout") or 10_000), MAX_COMPILACION_MS)
    correr_ms = min(int(datos.get("run_timeout") or 3_000), MAX_EJECUCION_MS)
    memoria = int(datos.get("run_memory_limit") or -1)
    memoria = MAX_MEMORIA if memoria <= 0 else min(memoria, MAX_MEMORIA)

    respuesta: dict = {"language": lenguaje, "version": version(lenguaje)}
    with SIMULTANEAS, tempfile.TemporaryDirectory(prefix="ejecucion-") as dir_:
        carpeta = Path(dir_)
        fuente = carpeta / FUENTE[lenguaje]
        fuente.write_text(codigo, encoding="utf-8")
        if lenguaje in COMPILAR:
            # El compilador es de confianza: sin límite de memoria virtual (cc1plus reserva mucho espacio de
            # direcciones) ni de procesos; solo tiempo y tamaño de lo que escribe
            # LC_ALL=C: los mensajes de gcc con comillas ASCII, como los de Piston ('x' y no ‘x’)
            compilacion = _correr([*COMPILAR[lenguaje], "-o", "programa", fuente.name, "-lm"], carpeta, "", compilar_ms, None, None, "C")
            respuesta["compile"] = compilacion
            if compilacion["code"] != 0:
                return 200, respuesta
            orden = ["./programa"]
        else:
            orden = ["python3", "-I", fuente.name]
        # El programa puede crear a lo más 16 procesos más de los que ya hay (una bomba de procesos se detiene ahí)
        respuesta["run"] = _correr(orden, carpeta, entrada, correr_ms, memoria, _tareas_del_usuario() + 16)
    return 200, respuesta
