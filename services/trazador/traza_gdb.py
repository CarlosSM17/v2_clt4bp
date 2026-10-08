"""Se ejecuta DENTRO de gdb (gdb -batch -x traza_gdb.py): avanza el programa línea por línea y anota cada paso.

Cada paso es la línea que está POR ejecutarse, las variables en ese momento (las ya declaradas) y lo que el
programa imprimió desde el paso anterior. Así, la salida acumulada hasta un paso es lo impreso hasta entonces.
"""

import json
import os
import re
import signal
import threading
import time

import gdb

FUENTE = os.path.basename(os.environ["TRAZA_FUENTE"])
SALIDA = os.environ["TRAZA_SALIDA"]
ENTRADA = os.environ["TRAZA_ENTRADA"]
RESULTADO = os.environ["TRAZA_RESULTADO"]
MAX_PASOS = int(os.environ.get("TRAZA_MAX_PASOS", "300"))
STDBUF = "/usr/libexec/coreutils/libstdbuf.so"

CODIGO = open(os.environ["TRAZA_FUENTE"], encoding="utf-8", errors="replace").read().split("\n")
ESCALARES = (gdb.TYPE_CODE_INT, gdb.TYPE_CODE_FLT, gdb.TYPE_CODE_PTR, gdb.TYPE_CODE_BOOL, gdb.TYPE_CODE_CHAR, gdb.TYPE_CODE_ENUM)

leido = 0
senal: str | None = None
# Variables declaradas sin valor inicial («int n, x;»): muestran «?» hasta que una línea ejecutada las usa, como en
# Python Tutor; antes, su valor es basura de la memoria (6.37e-310) y confunde. Se identifican por la profundidad
# del marco en la pila (main = 0), así una llamada nueva empieza sin valores.
asignadas: set[tuple[int, str]] = set()
ultima_linea: dict[int, int] = {}  # por profundidad: la línea del paso anterior en ese marco, la que se acaba de ejecutar


def al_detenerse(evento: gdb.StopEvent) -> None:
    """Una señal (SIGSEGV por un puntero inválido, SIGXCPU por el límite de CPU) termina la traza y se informa."""
    global senal
    if isinstance(evento, gdb.SignalEvent):
        senal = evento.stop_signal


gdb.events.stop.connect(al_detenerse)

# Un ciclo de una sola línea hace que «step» no regrese nunca (y gdb gasta CPU avanzando instrucción por
# instrucción). Un paso normal tarda milisegundos: si uno pasa de 3 s, se termina el programa y la traza se corta
inicio_paso = time.monotonic()
atascado = False
vigilando = True


def vigilar(pid: int) -> None:
    global atascado
    while vigilando:
        time.sleep(0.2)
        if time.monotonic() - inicio_paso > 3:
            atascado = True
            try:
                os.kill(pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            return


def ejecutar(orden: str) -> str:
    return gdb.execute(orden, to_string=True)


def salida_nueva() -> str:
    """Lo que el programa escribió desde la última lectura (su salida va a un archivo, sin búfer)."""
    global leido
    try:
        with open(SALIDA, "rb") as f:
            f.seek(leido)
            datos = f.read()
    except FileNotFoundError:
        return ""
    leido += len(datos)
    return datos.decode("utf-8", "replace")


def mostrar(valor: gdb.Value) -> str:
    """Un puntero muestra también a qué apunta («0x7ffe… → 10»): la dirección sola no le dice nada al estudiante."""
    texto = str(valor)
    tipo = valor.type.strip_typedefs()
    if tipo.code == gdb.TYPE_CODE_PTR:
        destino = tipo.target().strip_typedefs()
        if int(valor) == 0:
            return "NULL"
        if destino.code not in (gdb.TYPE_CODE_VOID, gdb.TYPE_CODE_FUNC) and destino.sizeof != 1:  # char* ya trae su texto
            try:
                return f"{texto} → {valor.dereference()}"
            except gdb.MemoryError:
                return f"{texto} → (memoria inválida)"
    return texto


def texto_linea(numero: int) -> str:
    return CODIGO[numero - 1] if 0 < numero <= len(CODIGO) else ""


def identificadores(numero: int) -> set[str]:
    sin_cadenas = re.sub(r'"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'', '""', texto_linea(numero))
    return set(re.findall(r"[A-Za-z_]\w*", sin_cadenas))


def sin_inicializador(nombre: str, numero: int) -> bool:
    """En «int n, x, s = 0;» n y x no tienen valor inicial; s sí."""
    m = re.search(rf"\b{re.escape(nombre)}\s*(?:\[[^\]]*\]\s*)*([=;,({{])", texto_linea(numero))
    return m is not None and m.group(1) in ";,"


def sin_valor(profundidad: int, nombre: str, declarada: int, valor: gdb.Value) -> bool:
    if declarada == 0 or (profundidad, nombre) in asignadas:  # los argumentos siempre traen valor
        return False
    base = valor.type.strip_typedefs()
    while base.code == gdb.TYPE_CODE_ARRAY:
        base = base.target().strip_typedefs()
    return base.code in ESCALARES and sin_inicializador(nombre, declarada)  # un struct o un vector de C++ se construye


def simbolos(marco: gdb.Frame, linea: int, previas: set[str]) -> list[tuple[str, gdb.Value, int]]:
    """Argumentos y variables locales ya declaradas (las de líneas posteriores aún no existen o traen basura), en
    orden de declaración. Una variable declarada en la línea actual se incluye si ya existía en el paso anterior:
    la i de un for, cuando el ciclo vuelve a su encabezado."""
    encontradas: dict[str, tuple[int, gdb.Value]] = {}
    try:
        bloque = marco.block()
    except RuntimeError:
        return []
    while bloque is not None:
        for simbolo in bloque:
            if not (simbolo.is_variable or simbolo.is_argument) or simbolo.name in encontradas:
                continue
            if not simbolo.is_argument and (simbolo.line > linea or (simbolo.line == linea and simbolo.name not in previas)):
                continue
            try:
                encontradas[simbolo.name] = (0 if simbolo.is_argument else simbolo.line, simbolo.value(marco))
            except Exception:  # noqa: BLE001 — una variable optimizada o ilegible no detiene la traza
                continue
        if bloque.function is not None:
            break
        bloque = bloque.superblock
    return [(nombre, valor, declarada) for nombre, (declarada, valor) in sorted(encontradas.items(), key=lambda par: par[1][0])]


def variables(simbolos_marco: list[tuple[str, gdb.Value, int]], profundidad: int) -> list[list[str]]:
    salida = []
    for nombre, valor, declarada in simbolos_marco:
        try:
            salida.append([nombre, "?" if sin_valor(profundidad, nombre, declarada, valor) else mostrar(valor)[:80]])
        except Exception:  # noqa: BLE001
            salida.append([nombre, "?"])
    return salida


def anotar_asignaciones(simbolos_marco: list[tuple[str, gdb.Value, int]], profundidad: int, linea: int) -> None:
    """Una variable recibe valor cuando una línea ejecutada la menciona (scanf, una asignación) y lo pierde al salir
    de su bloque: la x declarada dentro de un ciclo vuelve a «?» en cada vuelta."""
    en_alcance = {nombre for nombre, _, _ in simbolos_marco}
    asignadas.difference_update({(d, n) for d, n in asignadas if d == profundidad and n not in en_alcance})
    ejecutada = ultima_linea.get(profundidad)
    if ejecutada is not None:
        usadas = identificadores(ejecutada)
        asignadas.update((profundidad, n) for n, _, declarada in simbolos_marco if n in usadas and declarada != ejecutada)
    ultima_linea[profundidad] = linea


def celda(nombre: str, valor: gdb.Value, desconocido: bool = False) -> dict | None:
    """Cómo dibujar una variable en el diagrama de memoria: su dirección y, según el tipo, sus elementos (arreglo) o
    la dirección a la que apunta (puntero). El reproductor une punteros y destinos por dirección."""
    try:
        tipo = valor.type.strip_typedefs()
        if valor.address is None:
            return None
        direccion = int(valor.address)
        if tipo.code == gdb.TYPE_CODE_ARRAY:
            elemento = tipo.target().strip_typedefs()
            inicio, fin = tipo.range()
            if elemento.code == gdb.TYPE_CODE_INT and elemento.sizeof == 1:  # cadena de caracteres: un solo valor
                return {"n": nombre, "d": direccion, "t": "valor", "x": "?" if desconocido else str(valor)[:40], "tam": tipo.sizeof}
            n = min(fin - inicio + 1, 20)
            elementos = ["?"] * n if desconocido else [str(valor[k])[:16] for k in range(n)]
            return {"n": nombre, "d": direccion, "t": "arreglo", "tam": elemento.sizeof, "e": elementos}
        if tipo.code == gdb.TYPE_CODE_PTR and not desconocido:
            return {"n": nombre, "d": direccion, "t": "puntero", "a": int(valor)}
        return {"n": nombre, "d": direccion, "t": "valor", "x": "?" if desconocido else str(valor)[:40], "tam": tipo.sizeof}
    except Exception:  # noqa: BLE001
        return None


def marcos_del_programa(marco: gdb.Frame) -> list[gdb.Frame]:
    """Los marcos de la pila que son del programa (no de bibliotecas), del actual hacia main."""
    marcos = []
    actual: gdb.Frame | None = marco
    while actual is not None and len(marcos) < 200:
        sal = actual.find_sal()
        if sal.symtab is not None and os.path.basename(sal.symtab.filename) == FUENTE:
            marcos.append(actual)
        actual = actual.older()
    return marcos


def pila(marcos: list[gdb.Frame], simbolos_actual: list[tuple[str, gdb.Value, int]]) -> list[dict]:
    """La pila (main primero, hasta 8 marcos): así un puntero dentro de una función se ve apuntando al arreglo de main."""
    salida = []
    for i, actual in enumerate(marcos[:8]):
        profundidad = len(marcos) - 1 - i
        simbolos_marco = simbolos_actual if i == 0 else simbolos(actual, actual.find_sal().line, set())
        celdas = [c for nombre, valor, declarada in simbolos_marco
                  if (c := celda(nombre, valor, sin_valor(profundidad, nombre, declarada, valor)))]
        salida.append({"f": actual.name() or "", "v": celdas})
    return list(reversed(salida))


def vivo() -> bool:
    return any(hilo.is_valid() for hilo in gdb.selected_inferior().threads())


for orden in (
    "set pagination off", "set confirm off", "set width 0", "set print pretty off",
    "set print elements 20", "set print repeats 20", "set print frame-arguments none",
    "set disable-randomization off",  # dentro de un contenedor gdb no puede desactivarla; así no avisa
    "skip -gfi /usr/*",  # no entrar en el código de las bibliotecas (printf, iostream, plantillas)
    f"set environment LD_PRELOAD {STDBUF}", "set environment _STDBUF_O 0",  # salida sin búfer
    "break main",
):
    ejecutar(orden)

resultado: dict = {"pasos": [], "truncada": False, "error": None}
try:
    ejecutar(f"run < {ENTRADA} > {SALIDA} 2> /dev/null")
    threading.Thread(target=vigilar, args=(gdb.selected_inferior().pid,), daemon=True).start()
    salvaguarda, profundidad_anterior = 0, -1
    while vivo() and senal is None:
        marco = gdb.selected_frame()
        sal = marco.find_sal()
        if sal.symtab is None or os.path.basename(sal.symtab.filename) != FUENTE:
            # Fuera del programa (código sin depuración): se regresa a quien llamó
            salvaguarda += 1
            if salvaguarda > 50:
                break
            ejecutar("finish")
            continue
        salvaguarda = 0
        if len(resultado["pasos"]) >= MAX_PASOS:
            resultado["truncada"] = True
            break
        anterior = resultado["pasos"][-1] if resultado["pasos"] else None
        previas = {n for n, _ in anterior["v"]} if anterior and anterior["f"] == marco.name() else set()
        marcos = marcos_del_programa(marco)
        profundidad = len(marcos) - 1
        if profundidad > profundidad_anterior:  # una llamada nueva: sus variables aún no tienen valor
            asignadas.difference_update({(d, n) for d, n in asignadas if d >= profundidad})
            ultima_linea.pop(profundidad, None)
        profundidad_anterior = profundidad
        simbolos_actual = simbolos(marco, sal.line, previas)
        anotar_asignaciones(simbolos_actual, profundidad, sal.line)
        resultado["pasos"].append({
            "l": sal.line, "f": marco.name() or "", "v": variables(simbolos_actual, profundidad), "s": salida_nueva(),
            "m": pila(marcos, simbolos_actual),  # diagrama de memoria: la pila con arreglos, punteros y sus destinos
        })
        inicio_paso = time.monotonic()
        ejecutar("step")
except gdb.error as e:
    if "exited" not in str(e) and "not being run" not in str(e):
        resultado["error"] = str(e)[:300]

vigilando = False
if atascado or senal in ("SIGXCPU", "SIGKILL"):
    resultado["truncada"], resultado["error"] = True, "se excedió el tiempo: ¿un ciclo que no termina?"
elif senal:
    resultado["error"] = f"el programa terminó con la señal {senal}" + (" (acceso a memoria inválido)" if senal == "SIGSEGV" else "")

# Lo impreso después del último paso (al salir de main) va en un paso final, sobre la última línea
resto = salida_nueva()
if resultado["pasos"] and not resultado["truncada"]:
    ultimo = resultado["pasos"][-1]
    resultado["pasos"].append({"l": ultimo["l"], "f": ultimo["f"], "v": [], "s": resto, "fin": True})

with open(RESULTADO, "w", encoding="utf-8") as f:
    json.dump(resultado, f, ensure_ascii=False)
try:
    ejecutar("kill")
except gdb.error:
    pass
