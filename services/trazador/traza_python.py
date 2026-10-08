"""Traza de un programa de Python con sys.settrace: mismo resultado que traza_gdb.py para C y C++.

Cada paso es la línea POR ejecutarse (el evento «line» llega antes de ejecutarla), sus variables locales y lo que
el programa imprimió desde el paso anterior.
"""

import io
import json
import os
import sys
import types

FUENTE = os.environ["TRAZA_FUENTE"]
ENTRADA = os.environ["TRAZA_ENTRADA"]
RESULTADO = os.environ["TRAZA_RESULTADO"]
MAX_PASOS = int(os.environ.get("TRAZA_MAX_PASOS", "300"))
NOMBRE = "<programa>"


class Corte(Exception):
    pass


salida = io.StringIO()
leido = 0
resultado: dict = {"pasos": [], "truncada": False, "error": None}


def salida_nueva() -> str:
    global leido
    texto = salida.getvalue()
    nuevo, leido = texto[leido:], len(texto)
    return nuevo


def variables(marco: types.FrameType) -> list[list[str]]:
    return [
        [nombre, repr(valor)[:80]]
        for nombre, valor in marco.f_locals.items()
        if not nombre.startswith("__") and not isinstance(valor, (types.ModuleType, types.FunctionType, type))
    ]


def rastrear(marco: types.FrameType, evento: str, _arg):  # noqa: ANN202 — firma de sys.settrace
    if marco.f_code.co_filename != NOMBRE:
        return None  # no se entra en la biblioteca estándar
    if evento == "line":
        if len(resultado["pasos"]) >= MAX_PASOS:
            resultado["truncada"] = True
            raise Corte
        funcion = marco.f_code.co_name
        resultado["pasos"].append({
            "l": marco.f_lineno, "f": "" if funcion == "<module>" else funcion, "v": variables(marco), "s": salida_nueva(),
        })
    return rastrear


with open(FUENTE, encoding="utf-8") as f:
    codigo = f.read()
with open(ENTRADA, encoding="utf-8") as f:
    sys.stdin = io.StringIO(f.read())

stdout_real = sys.stdout
sys.stdout = salida
try:
    programa = compile(codigo, NOMBRE, "exec")
    sys.settrace(rastrear)
    exec(programa, {"__name__": "__main__"})  # noqa: S102 — es el propósito: ejecutar el programa en el sandbox
except Corte:
    pass
except SyntaxError as e:
    resultado["error"] = f"no compila: línea {e.lineno}: {e.msg}"
except Exception as e:  # noqa: BLE001 — el error del programa es parte de lo que se muestra
    resultado["error"] = f"{type(e).__name__}: {e}"[:300]
finally:
    sys.settrace(None)
    sys.stdout = stdout_real

resto = salida_nueva()
if resultado["pasos"] and not resultado["truncada"]:
    ultimo = resultado["pasos"][-1]
    resultado["pasos"].append({"l": ultimo["l"], "f": ultimo["f"], "v": [], "s": resto, "fin": True})

with open(RESULTADO, "w", encoding="utf-8") as f:
    json.dump(resultado, f, ensure_ascii=False)
