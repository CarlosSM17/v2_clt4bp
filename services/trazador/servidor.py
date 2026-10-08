"""Trazador de CLT4BP: ejecuta un programa línea por línea y devuelve sus pasos reales (línea, variables, salida).

Lo llama el agente (nunca el navegador). Corre en un contenedor sin red, como usuario sin privilegios y con límites
de tiempo, memoria y procesos: el código viene del instructor o del modelo y se trata como no confiable.

    POST /trazar  {"lenguaje": "c" | "cpp" | "python", "codigo": "...", "entrada": "..."}
      → {"pasos": [{"l": línea, "f": función, "v": [[nombre, valor]], "s": salida nueva, "fin"?: true}],
         "truncada": bool, "error": str | null}
    GET /salud

También ejecuta programas con la forma de la API de Piston (`POST /api/v2/execute`, `GET /api/v2/runtimes`; ver
ejecutor.py): donde Piston no puede correr (Railway no admite contenedores privilegiados), este mismo contenedor ejecuta
el código de los estudiantes y del agente (ADR 0008).
"""

import json
import os
import resource
import socket
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import ejecutor

AQUI = Path(__file__).parent
MAX_CODIGO, MAX_ENTRADA = 20_000, 5_000
MAX_PASOS = int(os.environ.get("TRAZA_MAX_PASOS", "300"))
SIMULTANEAS = threading.BoundedSemaphore(int(os.environ.get("TRAZA_SIMULTANEAS", "4")))
COMPILADORES = {"c": ["gcc", "-std=c17"], "cpp": ["g++", "-std=c++17"]}


def limitar() -> None:
    """Se aplica al proceso hijo antes de ejecutarlo (gdb y el programa heredan los límites). Sin RLIMIT_AS: gdb
    reserva mucho espacio de direcciones al cargar los símbolos de C++ y con 1 GB se colgaba; la memoria real la
    limita el contenedor (mem_limit). El límite de CPU corta los ciclos que no terminan, incluso los de una sola
    línea, en los que «step» de gdb nunca regresa."""
    resource.setrlimit(resource.RLIMIT_CPU, (8, 8))
    resource.setrlimit(resource.RLIMIT_FSIZE, (2 << 20, 2 << 20))  # salida de 2 MB como máximo
    resource.setrlimit(resource.RLIMIT_NPROC, (64, 64))


def trazar(lenguaje: str, codigo: str, entrada: str) -> dict:
    if lenguaje not in ("c", "cpp", "python"):
        return {"pasos": [], "truncada": False, "error": f"lenguaje no soportado: {lenguaje}"}
    with tempfile.TemporaryDirectory(prefix="traza-") as dir_:
        d = Path(dir_)
        fuente = d / {"c": "programa.c", "cpp": "programa.cpp", "python": "programa.py"}[lenguaje]
        fuente.write_text(codigo, encoding="utf-8")
        (d / "entrada.txt").write_text(entrada, encoding="utf-8")
        entorno = {
            "PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": dir_, "TRAZA_MAX_PASOS": str(MAX_PASOS),
            "TRAZA_FUENTE": str(fuente), "TRAZA_ENTRADA": str(d / "entrada.txt"),
            "TRAZA_SALIDA": str(d / "salida.txt"), "TRAZA_RESULTADO": str(d / "resultado.json"),
        }
        try:
            if lenguaje == "python":
                orden = ["python3", str(AQUI / "traza_python.py")]
            else:
                compilado = subprocess.run(
                    [*COMPILADORES[lenguaje], "-g", "-O0", "-fno-omit-frame-pointer", "-o", str(d / "programa"), str(fuente), "-lm"],
                    capture_output=True, text=True, timeout=20, cwd=dir_, env=entorno, preexec_fn=limitar,
                )
                if compilado.returncode != 0:
                    return {"pasos": [], "truncada": False, "error": f"no compila: {compilado.stderr.strip()[:600]}"}
                orden = ["gdb", "-q", "-nx", "-batch", "-x", str(AQUI / "traza_gdb.py"), str(d / "programa")]
            subprocess.run(orden, capture_output=True, text=True, timeout=30, cwd=dir_, env=entorno, preexec_fn=limitar)
        except subprocess.TimeoutExpired:
            return {"pasos": [], "truncada": True, "error": "se excedió el tiempo: ¿un ciclo que no termina?"}
        archivo = d / "resultado.json"
        if not archivo.exists():
            return {"pasos": [], "truncada": False, "error": "no se pudo trazar el programa"}
        return json.loads(archivo.read_text(encoding="utf-8"))


class Manejador(BaseHTTPRequestHandler):
    def responder(self, estado: int, cuerpo: dict) -> None:
        datos = json.dumps(cuerpo, ensure_ascii=False).encode("utf-8")
        self.send_response(estado)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(datos)))
        self.end_headers()
        self.wfile.write(datos)

    def do_GET(self) -> None:  # noqa: N802 — nombre que exige BaseHTTPRequestHandler
        if self.path == "/salud":
            return self.responder(200, {"estado": "ok"})
        if self.path == "/api/v2/runtimes":
            return self.responder(200, ejecutor.runtimes())  # type: ignore[arg-type]
        self.responder(404, {"error": "no existe"})

    def do_POST(self) -> None:  # noqa: N802
        if self.path == "/api/v2/execute":
            try:
                datos = json.loads(self.rfile.read(int(self.headers.get("Content-Length", "0")) or 0))
            except ValueError:
                return self.responder(400, {"message": "se esperaba JSON"})
            return self.responder(*ejecutor.ejecutar(datos if isinstance(datos, dict) else {}))
        if self.path != "/trazar":
            return self.responder(404, {"error": "no existe"})
        try:
            datos = json.loads(self.rfile.read(int(self.headers.get("Content-Length", "0")) or 0))
            lenguaje, codigo, entrada = datos["lenguaje"], datos["codigo"], datos.get("entrada", "")
        except (ValueError, KeyError, TypeError):
            return self.responder(422, {"error": "se esperaba JSON con lenguaje, codigo y entrada"})
        if len(codigo) > MAX_CODIGO or len(entrada) > MAX_ENTRADA:
            return self.responder(422, {"error": "código o entrada demasiado largos"})
        with SIMULTANEAS:
            self.responder(200, trazar(lenguaje, codigo, entrada))

    def log_message(self, formato: str, *args) -> None:  # noqa: ANN002 — sin registrar el código recibido
        pass


class ServidorDual(ThreadingHTTPServer):
    """IPv6 y IPv4 en el mismo puerto: la red privada de Railway es IPv6 y Docker local usa IPv4."""

    address_family = socket.AF_INET6
    daemon_threads = True

    def server_bind(self) -> None:
        self.socket.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 0)
        super().server_bind()


if __name__ == "__main__":
    puerto = int(os.environ.get("PUERTO") or os.environ.get("PORT") or "2010")
    try:
        servidor: ThreadingHTTPServer = ServidorDual(("::", puerto), Manejador)
    except OSError:  # contenedor sin IPv6
        servidor = ThreadingHTTPServer(("0.0.0.0", puerto), Manejador)  # noqa: S104 — red interna del contenedor
    servidor.serve_forever()
