"""Etapas que hacen confiable lo que un modelo pequeño no hace bien solo (medido en «Programación I», ADR 0006).

- Las salidas esperadas de los casos se calculan ejecutando la solución: el modelo las calculaba de cabeza y se
  equivocaba (esperaba «Promedio: 20» donde su propio programa imprimía otra cosa).
- Una solución que imprime lo mismo con cualquier entrada no lee la entrada (usaba un arreglo fijo {10, 20, 30}).
- Cada ejemplo resuelto lleva su ejecución paso a paso, calculada con el trazador a partir de su propio código.
- Una clase de tareas se completa con solicitudes enfocadas: su información de soporte y una ayuda procedimental
  por tarea. Pedir todo en una sola respuesta hacía que el modelo apenas escribiera una ayuda para toda la clase.
"""

import re
import unicodedata
from typing import Any

from app.bloques_md import ejecutable, lee_de_mas
from app.ejecutor import Ejecutor, normalizar
from app.multimedia import bloques
from app.solicitud import ElementoPropuesto, SolicitudGeneracion, Validacion
from app.trazador import leer_bloque
from app.validacion import diseno_combinado, no_es_programa
from app.verificador.contexto import similitud
from app.verificador.reglas import _normalizar as _normalizar_codigo  # la misma comparación que «apoyo_coherente»


# «-nan» o «inf» en la salida: una división entre cero o una operación inválida con algún caso (p. ej., n = 0)
NO_NUMERO = re.compile(r"(?<![\w.])-?(nan|inf|infinity)(?!\w)", re.I)


def _titulo(c: dict[str, Any]) -> str:
    return c.get("titulo") or c.get("uid") or ""


# printf con un formato que no corresponde al tipo (%f con un int): imprime 0.0 o basura sin error de compilación, y
# Piston no muestra los avisos de gcc. El modelo pequeño no lo encuentra solo (trabajo de regresión 2026-10-02-2223)
DECLARACION_SIMPLE = re.compile(r"\b(int|long|short|char|unsigned|size_t|float|double)\s+([^;()]+);")
LLAMADA_PRINTF = re.compile(r'\bprintf\s*\(\s*"((?:\\.|[^"\\])*)"((?:[^;"]|"(?:\\.|[^"\\])*")*)\)\s*;')
CONVERSION = re.compile(r"%[-+ #0]*(\d+|\*)?(?:\.(\d+|\*))?(?:hh|h|ll|l|L|z|j|t)?([diouxXfFeEgGcsp%])")


def _tipos_simples(codigo: str) -> dict[str, str]:
    """Variables escalares y su clase (entero o real); las que se declaran con dos tipos se descartan."""
    tipos: dict[str, str] = {}
    for m in DECLARACION_SIMPLE.finditer(codigo):
        clase = "real" if m.group(1) in ("float", "double") else "entero"
        for declarador in m.group(2).split(","):
            nombre = re.match(r"\s*([A-Za-z_]\w*)\s*(=|$)", declarador)  # sin punteros ni arreglos
            if nombre:
                tipos[nombre.group(1)] = clase if tipos.get(nombre.group(1), clase) == clase else "?"
    return tipos


def _argumentos(texto: str) -> list[str]:
    partes, profundidad, actual = [], 0, ""
    for c in texto:
        if c == "," and profundidad == 0:
            partes.append(actual)
            actual = ""
            continue
        profundidad += (c in "([") - (c in ")]")
        actual += c
    return [p.strip() for p in [*partes, actual][1:]]  # lo que hay antes de la primera coma es vacío


def formato_printf(codigo: str) -> str | None:
    tipos = _tipos_simples(codigo)
    for m in LLAMADA_PRINTF.finditer(codigo):
        conversiones = [c for c in CONVERSION.finditer(m.group(1)) if c.group(3) != "%"]
        argumentos = _argumentos(m.group(2))
        if any("*" in (c.group(1) or "") + (c.group(2) or "") for c in conversiones) or len(conversiones) != len(argumentos):
            continue
        for c, arg in zip(conversiones, argumentos, strict=True):
            clase = tipos.get(arg)
            if (c.group(3) in "fFeEgG" and clase == "entero") or (c.group(3) in "di" and clase == "real"):
                tipo = "un entero" if clase == "entero" else "un número real"
                return (f"printf usa «{c.group(0)}» con «{arg}», que es {tipo}: así imprime 0 o basura. Usa %d para "
                        "enteros y %f para float o double, o convierte el valor, p. ej. (double) " + arg + ".")
    return None


def _sin_espacios(codigo: str) -> str:
    """Solo se ignoran los espacios: quitar las líneas con # (comentarios en Python) borraba los #include de C++, y un
    ejemplo sin `#include <iomanip>` pasaba por igual a su solución (trabajo 24)."""
    return re.sub(r"\s+", "", codigo)


# Huecos de un problema por completar: las instrucciones que hacen el trabajo (asignaciones y condiciones), no la
# lectura, la escritura ni las declaraciones
DECLARACION = re.compile(
    r"^\s*(?:const\s+|static\s+|unsigned\s+|signed\s+|long\s+|short\s+)*"
    r"(?:int|float|double|char|bool|long|short|auto|size_t|string|std::string|vector<[^>]*>|std::vector<[^>]*>)\b"
)
ENTRADA_SALIDA = re.compile(r"\b(scanf|printf|cin|cout|puts|getchar|fgets|fopen|fclose|fprintf|fscanf|input|print|return)\b")
ASIGNACION_C = re.compile(r"^(\s*)([A-Za-z_][\w\[\]\.>-]*)\s*(?:[-+*/%]?=(?!=)[^;]*|\+\+|--);\s*$")
ASIGNACION_PY = re.compile(r"^(\s*)([A-Za-z_][\w\[\]\.]*)\s*[-+*/%]?=(?!=)\s*\S.*$")
CONDICION_C = re.compile(r"^(\s*(?:\}\s*)?(?:else\s+)?if\s*\()(.*)(\)\s*\{?\s*)$")
CONDICION_PY = re.compile(r"^(\s*(?:el)?if\s+)(.*?)(\s*:\s*)$")
LECTURA = re.compile(r"\b(cin|getline|scanf|input|fgets)\b")


def huecos_desde_solucion(solucion: str, lenguaje: str, maximo: int = 2) -> str | None:
    """La solución con sus líneas clave como huecos: las asignaciones más internas (las del ciclo) o la condición de
    un if. None si no encuentra ninguna."""
    python = lenguaje.startswith("py")
    asignacion, condicion = (ASIGNACION_PY, CONDICION_PY) if python else (ASIGNACION_C, CONDICION_C)
    lineas = solucion.split("\n")
    candidatas: list[tuple[int, int, str]] = []  # (sangría, número de línea, tipo)
    for i, linea in enumerate(lineas):
        if ENTRADA_SALIDA.search(linea) or DECLARACION.match(linea) or re.match(r"^\s*for\b", linea):
            continue
        sangria = len(linea) - len(linea.lstrip())
        if asignacion.match(linea):
            candidatas.append((sangria, i, "asignacion"))
        elif condicion.match(linea):
            candidatas.append((sangria - 1, i, "condicion"))  # a igual sangría, mejor la instrucción que la condición
    if not candidatas:
        # Un tema sin cálculos (tipos, variables, entrada y salida): los huecos son la lectura y la declaración
        # (corrida del 2026-10-06: «por completar» del nombre de un estudiante, sin asignaciones ni condiciones)
        for i, linea in enumerate(lineas):
            if LECTURA.search(linea):
                candidatas.append((1, i, "lectura"))
            elif DECLARACION.match(linea) and "(" not in linea:
                candidatas.append((0, i, "declaracion"))
    elegidas: list[tuple[int, int, str]] = []
    for c in sorted(candidatas, key=lambda c: (-c[0], c[1])):
        if len(elegidas) < maximo and lineas[c[1]].strip() not in {lineas[e[1]].strip() for e in elegidas}:
            elegidas.append(c)  # dos huecos de la misma instrucción (max = x; en dos ramas) no enseñan más
    if not elegidas:
        return None
    elegidas.sort(key=lambda c: c[1])
    for n, (_, i, tipo) in enumerate(elegidas, start=1):
        sangria = lineas[i][: len(lineas[i]) - len(lineas[i].lstrip())]
        if tipo in ("lectura", "declaracion"):
            if tipo == "lectura":
                leidas = re.findall(r">>\s*([A-Za-z_]\w*)|getline\s*\([^,]*,\s*([A-Za-z_]\w*)|&\s*([A-Za-z_]\w*)|^\s*([A-Za-z_]\w*)\s*=", lineas[i])
                nombres = list(dict.fromkeys(x for grupo in leidas for x in grupo if x))
                pista = f"HUECO {n}: lee {' y '.join(nombres[:3]) or 'el dato'} desde la entrada"
            else:
                tras_tipo = DECLARACION.sub("", lineas[i], count=1)
                nombre = re.search(r"([A-Za-z_]\w*)", tras_tipo)
                pista = f"HUECO {n}: declara {nombre.group(1) if nombre else 'la variable'} con el tipo adecuado"
            lineas[i] = f"{sangria}# {pista}" if python else f"{sangria}/* {pista} */"
        elif tipo == "asignacion":
            m = asignacion.match(lineas[i])
            pista = f"HUECO {n}: escribe la instrucción que actualiza {m.group(2)}"
            lineas[i] = f"{m.group(1)}# {pista}" if python else f"{m.group(1)}/* {pista} */"
        else:
            m = condicion.match(lineas[i])
            usa = list(dict.fromkeys(x for x in re.findall(r"[A-Za-z_]\w*", m.group(2)) if x not in ("and", "or", "not")))
            pista = f"HUECO {n}: escribe la condición" + (f" (usa {' y '.join(usa[:3])})" if usa else "")
            lineas[i] = f"{m.group(1)}___{m.group(3)}  # {pista}" if python else f"{m.group(1)}/* {pista} */{m.group(3)}"
    return "\n".join(lineas)


def marcar_huecos(elementos: list[ElementoPropuesto], lenguaje: str) -> list[Validacion]:
    """Un problema por completar sin huecos (el modelo pequeño a veces entrega el programa entero) los recibe a partir
    de la solución, en lugar de bloquear la propuesta."""
    avisos = []
    for e in elementos:
        t = e.contenido
        inicial = t.get("codigo_inicial") or ""
        # Huecos de verdad: marcados y sin el código debajo (el modelo a veces comenta «HUECO 1» y deja la instrucción;
        # sin comentarios es la solución completa, y el verificador lo bloquea)
        con_huecos_reales = "HUECO" in inicial and _normalizar_codigo(inicial) != _normalizar_codigo(t.get("solucion") or "")
        if e.tipo != "tarea" or t.get("nivel_apoyo") != "por_completar" or con_huecos_reales:
            continue
        if no_es_programa(t.get("solucion", ""), lenguaje, "") or not (con_huecos := huecos_desde_solucion(t["solucion"], lenguaje)):
            continue  # sin programa o sin líneas que quitar: lo informa la validación de código
        t["codigo_inicial"] = con_huecos
        avisos.append(Validacion(
            nombre="huecos_automaticos", ok=False, bloqueante=False, elemento_uid=t["uid"],
            detalle=f"{_titulo(t)}: el código inicial no dejaba nada por completar; el sistema quitó de la solución "
            f"{con_huecos.count('HUECO')} líneas clave. Revisa que sean las que quieres que complete el estudiante.",
        ))
    return avisos


def _repartir_ocultos(t: dict[str, Any]) -> None:
    """Tras quitar casos: siempre uno visible y, en un problema sin apoyo, uno oculto (regla «casos_de_prueba»)."""
    casos = t["casos_prueba"]
    if all(c.get("oculto") for c in casos):
        casos[0]["oculto"] = False
    if t.get("nivel_apoyo") in ("convencional", "solucion_libre") and len(casos) >= 2 and not any(c.get("oculto") for c in casos):
        casos[-1]["oculto"] = True


DECLARACION_CIN = re.compile(r"\b(long long|unsigned|int|long|short|float|double|char|bool|std::string|string)\s+([^;(){}]+);")
LECTURA_CIN = re.compile(r"\b(?:std::)?cin\s*((?:>>\s*[A-Za-z_]\w*\s*)+)")
DATO_VALIDO = {
    "entero": re.compile(r"[+-]?\d+"), "real": re.compile(r"[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?"),
    "char": re.compile(r"\S"), "bool": re.compile(r"[01]"),
}
CLASE_TIPO = {"int": "entero", "long": "entero", "short": "entero", "unsigned": "entero", "long long": "entero",
              "float": "real", "double": "real", "char": "char", "bool": "bool"}
NOMBRE_CLASE = {"entero": "un entero", "real": "un número real", "char": "un solo carácter", "bool": "un bool (0 o 1)"}


def desajuste_entrada(solucion: str, entrada: str) -> str | None:
    """Un programa de C++ que lee sus datos en línea recta (sin ciclos ni getline) con `cin >> a >> b`: cada dato de la
    entrada debe ser del tipo de la variable que lo recibe. Si no, cin falla en silencio y la salida «calculada» no tiene
    sentido (ejemplo del 2026-10-06: «20.5» a un char y «A» a un bool imprimía «Nombre: .5, Genero: 2»)."""
    if re.search(r"\b(for|while|do|getline|scanf)\b|\[", solucion):
        return None
    tipos: dict[str, str] = {}
    for m in DECLARACION_CIN.finditer(solucion):
        for declarador in m.group(2).split(","):
            if nombre := re.match(r"\s*([A-Za-z_]\w*)", declarador):
                tipos[nombre.group(1)] = CLASE_TIPO.get(m.group(1), "texto")
    leidas = [v for m in LECTURA_CIN.finditer(solucion) for v in re.findall(r">>\s*([A-Za-z_]\w*)", m.group(1))]
    datos = entrada.split()
    if len(datos) < len(leidas):
        return f"la entrada trae {len(datos)} datos y el programa lee {len(leidas)}"
    for i, (variable, dato) in enumerate(zip(leidas, datos), start=1):
        clase = tipos.get(variable, "texto")
        if clase in DATO_VALIDO and not DATO_VALIDO[clase].fullmatch(dato):
            return f"el dato {i} («{dato}») va a «{variable}», que espera {NOMBRE_CLASE[clase]}"
    return None


def getline_para_texto(elementos: list[ElementoPropuesto], lenguaje: str) -> list[Validacion]:
    """Un texto con espacios («Super Mario», «Edgar C. Dijkstra») leído con `cin >> nombre` se parte en dos y descuadra
    todo lo que sigue (tercera tarea del 2026-10-06: «Nombre: Super», «Género: Mario», «Jugadores: 0»). Si los casos
    traen un dato por renglón y el de una variable string lleva espacios, esa lectura pasa a `getline(cin >> ws, nombre)`
    (`ws` salta el salto de línea que deja una lectura anterior)."""
    if lenguaje != "cpp":
        return []
    avisos = []
    for e in elementos:
        t = e.contenido
        solucion = t.get("solucion") or ""
        if e.tipo != "tarea" or not t.get("casos_prueba") or re.search(r"\b(for|while|do|getline|scanf)\b|\[", solucion):
            continue
        tipos = {n.group(1): m.group(1) for m in DECLARACION_CIN.finditer(solucion)
                 for d in m.group(2).split(",") if (n := re.match(r"\s*([A-Za-z_]\w*)", d))}
        sueltas = re.findall(r"^\s*(?:std::)?cin\s*>>\s*([A-Za-z_]\w*)\s*;", solucion, re.M)  # una variable por instrucción
        leidas = [v for m in LECTURA_CIN.finditer(solucion) for v in re.findall(r">>\s*([A-Za-z_]\w*)", m.group(1))]
        if not leidas or leidas != sueltas:
            continue
        renglones = [(c.get("entrada") or "").strip("\n").split("\n") for c in t["casos_prueba"]]
        if any(len(r) != len(leidas) for r in renglones):
            continue
        con_espacios = {v for r in renglones for v, dato in zip(leidas, r, strict=True)
                        if " " in dato.strip() and tipos.get(v) in ("string", "std::string")}
        for v in sorted(con_espacios):
            for campo in ("solucion", "codigo_inicial"):
                if isinstance(t.get(campo), str):
                    t[campo] = re.sub(rf"(?:std::)?cin\s*>>\s*{v}\s*;", f"getline(cin >> ws, {v});", t[campo])
        if con_espacios:
            avisos.append(Validacion(
                nombre="getline_automatico", ok=False, bloqueante=False, elemento_uid=t["uid"],
                detalle=f"{_titulo(t)}: los casos traen texto con espacios; {', '.join(sorted(con_espacios))} se lee ahora con "
                "getline(cin >> ws, …) en lugar de cin >> (que se detiene en el primer espacio).",
            ))
    return avisos


async def calcular_salidas(
    elementos: list[ElementoPropuesto], lenguaje: str, ejecutor: Ejecutor | None, quitar_fallidos: bool = False
) -> list[Validacion]:
    """Reemplaza la salida esperada de cada caso por la que imprime la solución de referencia.

    Con `quitar_fallidos` (desde el segundo intento), los casos con los que la solución termina con error o imprime
    nan se quitan si quedan al menos 2, en lugar de bloquear: con n = 0 y una división entera, `qwen3:4b` no agregó
    la validación en tres intentos aunque el mensaje decía «Floating point exception» (trabajo 18)."""
    if ejecutor is None:
        return []
    problemas: list[Validacion] = []
    entradas_por_tarea: dict[str, tuple[str, ...]] = {}
    for e in elementos:
        if e.tipo != "tarea":
            continue
        t = e.contenido
        casos = t.get("casos_prueba") or []
        if not casos or no_es_programa(t.get("solucion", ""), lenguaje, ""):
            continue  # sin casos o sin programa: la validación de código lo informa
        if not lenguaje.startswith("py") and (formato := formato_printf(t["solucion"])):
            problemas.append(Validacion(nombre="formato_printf", ok=False, elemento_uid=t["uid"], detalle=f"{_titulo(t)}: {formato}"))
            continue
        desajustes = [desajuste_entrada(t["solucion"], c.get("entrada") or "") if lenguaje == "cpp" else None for c in casos]
        if any(desajustes):
            if quitar_fallidos and desajustes.count(None) >= 2:
                quitadas = [repr((c.get("entrada") or "")[:30]) for c, d in zip(casos, desajustes, strict=True) if d]
                t["casos_prueba"] = casos = [c for c, d in zip(casos, desajustes, strict=True) if not d]
                _repartir_ocultos(t)
                problemas.append(Validacion(
                    nombre="casos_quitados", ok=False, bloqueante=False, elemento_uid=t["uid"],
                    detalle=f"{_titulo(t)}: se quitaron {len(quitadas)} casos cuyos datos no coinciden con lo que lee la "
                    f"solución (entrada {', '.join(quitadas)}).",
                ))
            else:
                caso, motivo = next((c, d) for c, d in zip(casos, desajustes, strict=True) if d)
                problemas.append(Validacion(
                    nombre="entrada_desajustada", ok=False, elemento_uid=t["uid"],
                    detalle=f"{_titulo(t)}: con la entrada {(caso.get('entrada') or '')[:40]!r}, {motivo}. Cada caso trae los "
                    "datos en el mismo orden y del mismo tipo en que el programa los lee.",
                ))
                continue
        resultados, inestable = [], []
        for c in casos:
            resultados.append(r := await ejecutor.ejecutar(lenguaje, t["solucion"], c.get("entrada") or ""))
            if not r.compilo:
                break
            # Cada caso se corre dos veces: si la salida cambia, el programa leyó memoria sin valor. Pasó con
            # «n = 3» y solo 5 de los 6 números (imprimía 1701870090 y luego otra cosa), sin error de ejecución
            otra = await ejecutor.ejecutar(lenguaje, t["solucion"], c.get("entrada") or "") if r.codigo == 0 else r
            # Y con datos de más al final: si cambia, a la entrada le faltaban datos (cin falla en silencio y deja ceros)
            inestable.append(normalizar(otra.salida) != normalizar(r.salida)
                             or (r.codigo == 0 and await lee_de_mas(ejecutor, lenguaje, t["solucion"], c.get("entrada") or "", r.salida)))
        if not all(r.compilo for r in resultados):
            continue  # no compila: lo informa la validación de código
        falla = [r.codigo != 0 or r.excedio_limite or bool(NO_NUMERO.search(normalizar(r.salida))) or i
                 for r, i in zip(resultados, inestable, strict=True)]
        if quitar_fallidos and any(falla) and falla.count(False) >= 2:
            quitadas = [repr((c.get("entrada") or "")[:30]) for c, f in zip(casos, falla, strict=True) if f]
            casos = [c for c, f in zip(casos, falla, strict=True) if not f]
            resultados = [r for r, f in zip(resultados, falla, strict=True) if not f]
            inestable = [False] * len(casos)
            t["casos_prueba"] = casos
            _repartir_ocultos(t)
            problemas.append(Validacion(
                nombre="casos_quitados", ok=False, bloqueante=False, elemento_uid=t["uid"],
                detalle=f"{_titulo(t)}: se quitaron {len(quitadas)} casos con los que la solución termina con error, "
                f"imprime nan o imprime algo distinto en cada ejecución (entrada {', '.join(quitadas)}; p. ej., una "
                "división entre cero o una entrada con menos datos de los que lee). Si quieres ese caso, corrige la "
                "solución o la entrada; revisa también que el enunciado no lo prometa.",
            ))
        if any(r.codigo != 0 or r.excedio_limite for r in resultados):
            continue  # falla con algún caso: lo informa la validación de código
        if any(inestable):
            caso = casos[inestable.index(True)]
            problemas.append(Validacion(
                nombre="salida_inestable", ok=False, elemento_uid=t["uid"],
                detalle=f"{_titulo(t)}: con la entrada {(caso.get('entrada') or '')[:40]!r} la solución imprime algo distinto "
                "en cada ejecución: lee datos que la entrada no trae (si n = 3 y lee dos números por elemento, la "
                "entrada lleva 6 números después de n) o usa una variable sin valor inicial. Corrige la entrada o la "
                "solución.",
            ))
            continue
        salidas = [normalizar(r.salida) for r in resultados]

        entradas = tuple(normalizar(c.get("entrada") or "") for c in casos)
        entradas_por_tarea[t["uid"]] = entradas
        if len(set(entradas)) >= 2 and len(set(salidas)) == 1:
            lee = re.search(r"\b(scanf|cin|getline|fgets|getchar|input)\b", t["solucion"])
            problemas.append(Validacion(
                nombre="solucion_usa_entrada", ok=False, elemento_uid=t["uid"],
                detalle=f"{_titulo(t)}: la solución imprime lo mismo ({salidas[0][:60]!r}) con todas las entradas. "
                + ("Sí lee la entrada, pero el resultado no depende de ella: revisa que se calcule con los datos leídos y "
                   "que el formato de printf corresponda al tipo de la variable (%d para int, %f para double)."
                   if lee else "Debe leer los datos de la entrada estándar (scanf o cin) en lugar de usar valores fijos en el código."),
            ))
            continue
        invalida = next(((c, x) for c, x in zip(casos, salidas, strict=True) if NO_NUMERO.search(x)), None)
        if invalida:
            caso, salida = invalida
            problemas.append(Validacion(
                nombre="salida_invalida", ok=False, elemento_uid=t["uid"],
                detalle=f"{_titulo(t)}: con la entrada {(caso.get('entrada') or '')[:40]!r} la solución imprime {salida[:40]!r}, "
                "señal de una división entre cero u otra operación inválida. Haz que la solución atienda ese caso (por "
                "ejemplo, que imprima un mensaje cuando no hay datos) y explícalo en el enunciado, o usa otra entrada.",
            ))
            continue

        distintas = sum(normalizar(c.get("salida_esperada") or "") != s for c, s in zip(casos, salidas, strict=True))
        for c, s in zip(casos, salidas, strict=True):
            c["salida_esperada"] = s
        # Un ejemplo resuelto ya no se sustituye por la solución (sistema-v12): su código inicial es el ejemplo que se
        # estudia y la solución es la de su gemelo, que el estudiante escribe; mostrarla delataría la respuesta
        if distintas:
            problemas.append(Validacion(
                nombre="salidas_calculadas", ok=False, bloqueante=False, elemento_uid=t["uid"],
                detalle=f"{_titulo(t)}: las salidas esperadas se calcularon ejecutando la solución (el modelo había escrito "
                f"otras en {distintas} de {len(casos)} casos). Revisa que la solución haga lo que pide el enunciado.",
            ))

    # Las tareas de una clase varían en datos (regla 3): mismas entradas en dos tareas es una señal de copia
    vistas: dict[tuple[str, ...], str] = {}
    for uid, entradas in entradas_por_tarea.items():
        if entradas in vistas:
            problemas.append(Validacion(
                nombre="casos_variados", ok=False, bloqueante=False, elemento_uid=uid,
                detalle="Esta tarea usa exactamente los mismos casos de prueba que otra: varía los datos entre tareas.",
            ))
        vistas.setdefault(entradas, uid)
    return problemas


# «cout << x << \n;»: el modelo olvida las comillas del salto de línea y el programa no compila («stray '\'»).
# `qwen3:4b` lo repitió en las tres tareas y en los tres intentos del trabajo 24
SALTO_SIN_COMILLAS = re.compile(r"(<<\s*)\\n(\s*(?:;|<<))")


# «struct Persona { … }» sin el «;» final: «expected ';' after struct definition» (trabajo 24, tres tareas y tres intentos)
INICIO_TIPO = re.compile(r"^\s*(?:typedef\s+)?(?:struct|class|union|enum)\b[^;()=]*\{")


def punto_y_coma_tras_tipos(codigo: str) -> str:
    lineas = codigo.split("\n")
    i = 0
    while i < len(lineas):
        if not INICIO_TIPO.match(lineas[i]):
            i += 1
            continue
        profundidad = 0
        for j in range(i, len(lineas)):
            profundidad += lineas[j].count("{") - lineas[j].count("}")
            if profundidad <= 0:
                if not lineas[j][lineas[j].rfind("}") + 1:].strip():  # nada tras la llave: ni «;» ni una variable
                    lineas[j] = lineas[j].rstrip() + ";"
                break
        i = j + 1
    return "\n".join(lineas)


CERCO = re.compile(r"^\s*```[\w+-]*[^\n]*\n(.*?)\n?```\s*$", re.S)


def sin_cerco(codigo: str) -> str:
    """Un campo de código que el modelo envolvió en ```cpp … ``` (en los ítems, el 2026-10-06): sin el cerco no compila."""
    m = CERCO.match(codigo)
    return m.group(1) if m else codigo


def reparar_codigo(elementos: list[ElementoPropuesto]) -> None:
    """Errores de escritura mecánicos que el modelo pequeño no corrige con la retroalimentación."""
    for e in elementos:
        for campo in ("solucion", "codigo_inicial", "enunciado_md", "cuerpo_md"):
            valor = e.contenido.get(campo)
            if not isinstance(valor, str):
                continue
            if campo in ("solucion", "codigo_inicial"):
                valor = sin_cerco(valor)
            if "\\n" in valor:
                valor = SALTO_SIN_COMILLAS.sub(r"\1'\\n'\2", valor)
            if "{" in valor:
                valor = punto_y_coma_tras_tipos(valor)
            e.contenido[campo] = valor


def limpiar_items(items: list[dict[str, Any]], lenguaje: str) -> list[Validacion]:
    """Ítems de la evaluación: el código sin cercos de Markdown, como programa completo, y sin ítems repetidos (el modelo
    pequeño devolvió el mismo ítem de predicción seis veces). Lo repetido se quita con aviso. En una predicción de salida,
    el programa que se ejecuta es el que ve el estudiante en el enunciado (el modelo ponía en el código solo unas líneas)."""
    vistos, unicos = set(), []
    for it in items:
        for campo in ("codigo_inicial", "solucion"):
            if isinstance(it.get(campo), str):
                it[campo] = sin_cerco(it[campo])
        if it.get("tipo") == "prediccion_salida":
            del_enunciado = [c for t, c in bloques(str(it.get("enunciado_md", ""))) if t in ("c", "cpp", "c++", "python", "py")]
            if del_enunciado and re.search(r"\bmain\s*\(|\bprint\s*\(", del_enunciado[0]):
                it["codigo_inicial"] = del_enunciado[0].strip()
        for campo in ("codigo_inicial", "solucion"):
            if isinstance(it.get(campo), str) and it[campo].strip():
                it[campo] = ejecutable(lenguaje, it[campo])
        clave = (it.get("tipo"), " ".join(str(it.get("enunciado_md", "")).split()).lower())
        if clave not in vistos:
            vistos.add(clave)
            unicos.append(it)
    repetidos = len(items) - len(unicos)
    items[:] = unicos
    if not repetidos:
        return []
    return [Validacion(nombre="items_repetidos", ok=False, bloqueante=False,
                       detalle=f"Se quitaron {repetidos} ítems repetidos (mismo enunciado); quedan {len(unicos)}.")]


NIVEL_POR_TITULO = {
    "ejemplo resuelto": "ejemplo_resuelto", "problema por completar": "por_completar", "por completar": "por_completar",
    "problema convencional": "convencional", "convencional": "convencional", "solucion libre": "solucion_libre",
}


def alinear_nivel(elementos: list[ElementoPropuesto]) -> list[Validacion]:
    """El nivel de apoyo que dice el título («Problema por completar: …») manda: `qwen3:4b` titulaba «por completar» y
    marcaba «ejemplo resuelto», y el estudiante recibía la solución completa (trabajo 24)."""
    avisos = []
    for e in elementos:
        t = e.contenido
        if e.tipo != "tarea":
            continue
        # Sin apoyo, el código inicial va vacío (regla 8): el modelo a veces copia ahí la solución completa
        if (t.get("nivel_apoyo") in ("convencional", "solucion_libre") and t.get("codigo_inicial")
                and _sin_espacios(t["codigo_inicial"]) == _sin_espacios(t.get("solucion") or "")):
            t["codigo_inicial"] = ""
        nivel = _nivel_en_titulo(t.get("titulo") or "")
        if nivel and nivel != t.get("nivel_apoyo"):
            avisos.append(Validacion(
                nombre="nivel_por_titulo", ok=False, bloqueante=False, elemento_uid=t["uid"],
                detalle=f"{_titulo(t)}: el nivel de apoyo era «{t.get('nivel_apoyo')}» y el título dice otro; quedó «{nivel}».",
            ))
            t["nivel_apoyo"] = nivel
            if nivel in ("convencional", "solucion_libre"):
                t["codigo_inicial"] = ""  # sin apoyo: el estudiante empieza de cero
            if t.get("casos_prueba"):
                _repartir_ocultos(t)  # un problema sin apoyo necesita un caso oculto
    return avisos


def _sin_acentos(texto: str) -> str:
    return unicodedata.normalize("NFKD", texto).encode("ascii", "ignore").decode().lower()


def _nivel_en_titulo(titulo: str) -> str | None:
    """El nivel que nombra el título, al inicio («Por completar: …») o al final («… (por completar)»)."""
    t = _sin_acentos(titulo)
    hallados = {nivel for frase, nivel in NIVEL_POR_TITULO.items() if re.search(rf"\b{frase}\b", t)}
    return hallados.pop() if len(hallados) == 1 else None


def _nucleo(titulo: str) -> str:
    """«Problema por completar: Cálculo del promedio» y «Cálculo del promedio (ejemplo resuelto)» son el mismo problema."""
    t = _sin_acentos(titulo)
    for frase in NIVEL_POR_TITULO:
        t = re.sub(rf"\b{frase}\b", " ", t)
    t = re.sub(r"\bproblema\b", " ", t)
    return re.sub(r"[^a-z0-9]+", " ", t).strip()


def repite_existente(sol: SolicitudGeneracion, elementos: list[ElementoPropuesto], tema: str) -> list[Validacion]:
    """Una clase nueva que copia una existente (mismo título de clase o de tarea) bloquea: `qwen3:4b` devolvía la clase
    ya publicada, con sus mismas tareas, aunque los objetivos y las indicaciones pedían otro tema (trabajos 23 y 24)."""
    clases = {_nucleo(c.titulo): c.titulo for c in sol.diseno.clases}
    tareas = {_nucleo(t.titulo): t.titulo for t in sol.diseno.tareas}
    problemas = []
    for e in elementos:
        c = e.contenido
        if e.tipo not in ("clase", "tarea") or not (nucleo := _nucleo(c.get("titulo") or "")):
            continue
        igual = (clases if e.tipo == "clase" else tareas).get(nucleo)
        if igual:
            problemas.append(Validacion(
                nombre="repite_existente", ok=False, elemento_uid=c.get("uid"),
                detalle=f"«{c.get('titulo')}» repite {'la clase' if e.tipo == 'clase' else 'la tarea'} «{igual}», que ya existe "
                f"en el curso. {tema} Propón otro título y otros problemas.",
            ))
    return problemas


def tareas_distintas(elementos: list[ElementoPropuesto], previas: list[dict[str, Any]] | None = None) -> list[Validacion]:
    """Cada tarea de una clase es un ejercicio distinto. Con `qwen3:4b`, las tres tareas solían ser el mismo problema
    («promedio de edades») con distinto nivel de apoyo; el instructor pidió ejercicios diferentes (2026-10-04).
    `previas`: las tareas que la clase ya tiene (T1–T4, al proponer T5–T8)."""
    nuevas = [e.contenido for e in elementos if e.tipo == "tarea"]
    tareas = [*(previas or []), *nuevas]
    problemas = []
    for i, b in enumerate(tareas):
        if b not in nuevas:
            continue
        for a in tareas[:i]:
            # Mismo título sin el nivel, enunciado casi igual o casi el mismo programa (los enunciados del mismo
            # ejercicio redactados distinto se parecían solo un 32 %; las soluciones, casi por completo). Un título que
            # solo dice el nivel («Ejemplo resuelto») queda vacío y no cuenta
            ids_a, ids_b = _identificadores(a.get("solucion") or ""), _identificadores(b.get("solucion") or "")
            nucleo = _nucleo(b.get("titulo") or "")
            if ((nucleo and nucleo == _nucleo(a.get("titulo") or ""))
                    or similitud(a.get("enunciado_md") or "", b.get("enunciado_md") or "") >= 0.75
                    or (ids_a and ids_b and len(ids_a & ids_b) / len(ids_a | ids_b) >= 0.85)):
                problemas.append(Validacion(
                    nombre="tareas_distintas", ok=False, elemento_uid=b.get("uid"),
                    detalle=f"«{b.get('titulo')}» es el mismo ejercicio que «{a.get('titulo')}». Cada tarea de la clase es un "
                    "problema distinto (otra operación o subtema, otro escenario y otros datos), no el mismo con otro "
                    "nivel de apoyo: cambia su título, su enunciado, su solución y sus casos.",
                ))
                break
    return problemas


# Lo que comparten casi todos los programas del curso: no dice si dos ejercicios son el mismo
COMUNES = {"include", "iostream", "stdio", "string", "using", "namespace", "std", "int", "main", "void", "return", "cin",
           "cout", "endl", "printf", "scanf", "for", "if", "else", "while", "double", "float", "char", "const", "auto"}


def _identificadores(codigo: str) -> set[str]:
    return {p for p in re.findall(r"[A-Za-z_]\w+", codigo.lower()) if p not in COMUNES}


def _codigos(md: str) -> list[str]:
    salida = []
    for lenguaje, contenido in bloques(md):
        if lenguaje == "traza":
            if (b := leer_bloque(contenido)[0]) is not None:
                salida.append(b.codigo)
        elif lenguaje in ("c", "cpp", "c++", "python", "py"):
            salida.append(contenido)
    return salida


def procedimental_del_tema(sol: SolicitudGeneracion, elementos: list[ElementoPropuesto]) -> list[Validacion]:
    """La información procedimental del tema tiene la forma del mapa de ruta (ADR 0007): tarjeta de sintaxis con su
    tabla, errores frecuentes con el código que falla y ejemplo isomórfico con un programa propio (no la solución de
    una tarea: la regalaría)."""
    soluciones = {t.titulo: t.solucion for t in sol.diseno.tareas if t.solucion}
    problemas, tipos = [], set()
    for e in elementos:
        if e.tipo != "procedimental":
            continue
        c, md = e.contenido, e.contenido.get("cuerpo_md") or ""
        tipo, titulo, uid = c.get("tipo"), c.get("titulo") or c.get("uid"), c.get("uid")
        tipos.add(tipo)
        falta = {
            "ficha_sintaxis": None if re.search(r"^\s*\|.*\|\s*$", md, re.M) else "la tabla «Quiero… | Escribo»",
            "errores_frecuentes": None if _codigos(md) else "el código de cada error (un bloque de código por error)",
            "ejemplo_isomorfico": None if _codigos(md) else "el programa del ejemplo en un bloque de código",
            "protocolo_verbal": None if _codigos(md) else "el programa del experto en un bloque de código",
        }.get(tipo)
        if falta:
            problemas.append(Validacion(nombre="procedimental_completo", ok=False, elemento_uid=uid,
                                        detalle=f"{titulo}: le falta {falta}."))
            continue
        if tipo in ("ejemplo_isomorfico", "protocolo_verbal"):
            for codigo in _codigos(md):
                ids = _identificadores(codigo)
                for tarea, solucion in soluciones.items():
                    otros = _identificadores(solucion)
                    if ids and otros and len(ids & otros) / len(ids | otros) >= 0.8:
                        problemas.append(Validacion(
                            nombre="ejemplo_distinto", ok=False, elemento_uid=uid,
                            detalle=f"{titulo}: el programa es casi la solución de «{tarea}». Usa el mismo patrón en otro "
                            "contexto, con otros datos.",
                        ))
                        break
    if sol.plantilla == "info_procedimental" and sol.alcance.get("clase_uid"):
        faltan = [t for t in ("ficha_sintaxis", "guia_preguntas", "errores_frecuentes", "ejemplo_isomorfico") if t not in tipos]
        if faltan:
            problemas.append(Validacion(nombre="procedimental_del_tema", ok=False, bloqueante=False,
                                        detalle=f"Faltan partes de la información procedimental del tema: {', '.join(faltan)}."))
    return problemas


# Lo que lleva cada pieza del soporte del tema (ADR 0007). Avisos: el diagrama y los bloques ya se validan aparte
SECCIONES = {
    "info_soporte": [("💡", "la analogía (> 💡)"), ("❓", "la pregunta para pensar (> ❓)"), ("🎯", "¿por qué y para qué? (> 🎯)"),
                     ("Conceptos", "«Conceptos, uno por uno»"), ("```salida", "la salida de cada programa (```salida)"),
                     ("🙋", "la actividad en el aula (> 🙋)")],
    "ejemplo_resuelto_tema": [("Algoritmo", "«A. Algoritmo»"), ("```pseint", "el pseudocódigo (```pseint)"),
                              ("```salida", "la salida del programa (```salida)"), ("Verificación", "«D. Verificación»")],
    "mapa_glosario": [("🗺", "el resumen visual (> 🗺)"), ("Glosario", "«Glosario bilingüe»")],
}


def secciones_del_tema(plantilla: str, elementos: list[ElementoPropuesto]) -> list[Validacion]:
    avisos = []
    for e in elementos:
        if e.tipo != "soporte" or plantilla not in SECCIONES:
            continue
        md = e.contenido.get("cuerpo_md") or ""
        faltan = [nombre for marca, nombre in SECCIONES[plantilla] if marca.lower() not in md.lower()]
        if faltan:
            avisos.append(Validacion(nombre="secciones_del_tema", ok=False, bloqueante=False, elemento_uid=e.contenido.get("uid"),
                                     detalle=f"{e.contenido.get('titulo')}: falta {', '.join(faltan)}."))
    return avisos


def asignar_rutas(elementos: list[ElementoPropuesto], grupos: list[Any]) -> list[Validacion]:
    """Las rutas de cada tarea salen de su papel en la secuencia, como en la matriz del mapa de ruta: la Ruta A (sin
    conocimientos previos, grupos de nivel básico) trabaja el ejemplo, el primer por completar y la solución libre; la
    Ruta B (intermedio y avanzado), la autoexplicación y la imaginación; todas, el resto. El instructor puede cambiarlas."""
    tareas = [e.contenido for e in elementos if e.tipo == "tarea"]
    basicos = [g.clave for g in grupos if g.nivel == "basico"]
    otros = [g.clave for g in grupos if g.nivel != "basico"]
    if not tareas or not basicos or not otros:
        for t in tareas:
            t["rutas"] = []  # un solo nivel (o sin grupos): todas las tareas para todos
        return []
    primer_ejemplo = next((t["uid"] for t in tareas if t.get("nivel_apoyo") == "ejemplo_resuelto"), None)
    primer_completar = next((t["uid"] for t in tareas if t.get("nivel_apoyo") == "por_completar"), None)
    for t in tareas:
        if t.get("colaborativa"):
            t["rutas"] = []
        elif t["uid"] in (primer_ejemplo, primer_completar) or t.get("nivel_apoyo") == "solucion_libre":
            t["rutas"] = list(basicos)
        elif t.get("nivel_apoyo") == "ejemplo_resuelto" and t.get("pide_autoexplicacion"):
            t["rutas"] = list(otros)
        else:
            t["rutas"] = []
    # Ningún grupo puede quedarse con menos de 3 tareas: entonces todas son para todos
    pocas = [g for g in basicos + otros if sum(1 for t in tareas if not t["rutas"] or g in t["rutas"]) < 3]
    if pocas:
        for t in tareas:
            t["rutas"] = []
        return [Validacion(nombre="rutas", ok=False, bloqueante=False,
                           detalle=f"Con estas tareas, {', '.join(pocas)} tendría menos de 3: todas quedaron para todos los grupos.")]
    return [Validacion(nombre="rutas", ok=False, bloqueante=False,
                       detalle=f"Rutas asignadas: Ruta A ({', '.join(basicos)}) y Ruta B ({', '.join(otros)}) según el papel de "
                       "cada tarea; cámbialas en el editor de la tarea si hace falta.")]


def programas_completos(elementos: list[ElementoPropuesto], lenguaje: str) -> None:
    """La solución de una tarea y el programa de un ejemplo resuelto son programas completos: si el modelo escribió solo
    las líneas (`cin >> altura;` sin main, cuarta corrida del 2026-10-06), van dentro de un main con sus bibliotecas."""
    for e in elementos:
        t = e.contenido
        if e.tipo != "tarea":
            continue
        campos = ["solucion"] + (["codigo_inicial"] if t.get("nivel_apoyo") == "ejemplo_resuelto" else [])
        for campo in campos:
            codigo = t.get(campo)
            if isinstance(codigo, str) and codigo.strip() and "HUECO" not in codigo:
                t[campo] = ejecutable(lenguaje, codigo)


def codigo_dado(elementos: list[ElementoPropuesto]) -> None:
    """Autoexplicación e imaginación (T6, T7): el estudiante recibe un programa que no lee datos. Su código inicial y su
    solución son ese programa, y basta un caso con entrada vacía (su salida la calcula el sistema). `qwen3:4b` dejaba
    vacío uno de los dos campos o los casos, y la tarea quedaba bloqueada."""
    for e in elementos:
        t = e.contenido
        if e.tipo != "tarea" or t.get("nivel_apoyo") != "ejemplo_resuelto" or not t.get("pide_autoexplicacion"):
            continue
        inicial, solucion = (t.get("codigo_inicial") or "").strip(), (t.get("solucion") or "").strip()
        completo = re.compile(r"\bmain\s*\(")
        if inicial and (not solucion or (completo.search(inicial) and not completo.search(solucion))):
            t["solucion"] = t["codigo_inicial"]
        elif solucion and (not inicial or (completo.search(solucion) and not completo.search(inicial))):
            # Solo las líneas sueltas como código dado (imaginación, 2026-10-06) no compilan: va el programa entero
            t["codigo_inicial"] = t["solucion"]
        if not t.get("casos_prueba"):
            t["casos_prueba"] = [{"entrada": "", "salida_esperada": "", "oculto": False}]


def agregar_trazas_ejemplos(elementos: list[ElementoPropuesto]) -> int:
    """Cada ejemplo resuelto recibe su ejecución paso a paso (los pasos los calcula después el trazador). No las tareas
    de autoexplicación o imaginación que siguen al primer ejemplo (T6 y T7): ahí el estudiante deduce lo que la traza
    le mostraría."""
    agregadas = 0
    primeros = {}
    for e in elementos:
        if e.tipo == "tarea" and e.contenido.get("nivel_apoyo") == "ejemplo_resuelto":
            primeros.setdefault(e.contenido.get("clase_uid"), e.contenido.get("uid"))
    for e in elementos:
        t = e.contenido
        if e.tipo != "tarea" or t.get("nivel_apoyo") != "ejemplo_resuelto" or "```traza" in (t.get("enunciado_md") or ""):
            continue
        if t.get("pide_autoexplicacion") and primeros.get(t.get("clase_uid")) != t.get("uid"):
            continue
        codigo = (t.get("codigo_inicial") or t.get("solucion") or "").strip("\n")
        if not codigo.strip():
            continue
        visible = next((c for c in t.get("casos_prueba") or [] if not c.get("oculto")), {})
        entrada = (visible.get("entrada") or "").replace("\n", "\\n")
        t["enunciado_md"] = (
            (t.get("enunciado_md") or "").rstrip()
            + "\n\n### Ejecución paso a paso\n\nAvanza línea por línea y observa cómo cambian las variables y la salida.\n\n"
            + f"```traza\ntitulo: {_titulo(t)}\nentrada: {entrada}\n---\n{codigo}\n```\n"
        )
        agregadas += 1
    return agregadas


def subsolicitud(sol: SolicitudGeneracion, elementos: list[ElementoPropuesto], plantilla: str, prefijo: str = "") -> SolicitudGeneracion | None:
    """Una solicitud de seguimiento para la clase recién propuesta, que ya ve en el diseño la clase y sus tareas."""
    clase = next((e.contenido for e in elementos if e.tipo == "clase"), None)
    if clase is None:
        return None
    try:
        diseno = diseno_combinado(sol, elementos)
    except Exception:  # noqa: BLE001 — una propuesta con errores de contrato no se completa; ya se informó
        return None
    return SolicitudGeneracion(
        plantilla=plantilla, alcance={"clase_uid": clase["uid"], "prefijo": prefijo or clase["uid"]}, curso=sol.curso,
        diseno=diseno, grupos=sol.grupos, indicaciones=sol.indicaciones, calidad=sol.calidad, material=sol.material,
    )


def subsolicitudes_clase(sol: SolicitudGeneracion, elementos: list[ElementoPropuesto]) -> list[SolicitudGeneracion]:
    """Lo que completa una clase recién propuesta (CLASE_COMPLETA): sus conceptos de soporte y su información
    procedimental del tema. La consola lo hace con «Generar tema completo», una propuesta por pieza (ADR 0007)."""
    clase = next((e.contenido for e in elementos if e.tipo == "clase"), None)
    if clase is None:
        return []
    try:
        diseno = diseno_combinado(sol, elementos)  # las solicitudes nuevas ven la clase y sus tareas
    except Exception:  # noqa: BLE001 — una propuesta con errores de contrato no se completa; ya se informó
        return []
    base = {"curso": sol.curso, "diseno": diseno, "grupos": sol.grupos, "indicaciones": sol.indicaciones,
            "calidad": sol.calidad, "material": sol.material}
    salida = []
    if not any(e.tipo == "soporte" for e in elementos):
        salida.append(SolicitudGeneracion(plantilla="info_soporte", alcance={"clase_uid": clase["uid"], "prefijo": clase["uid"]}, **base))
    if not any(e.tipo == "procedimental" for e in elementos):
        salida.append(SolicitudGeneracion(plantilla="info_procedimental", alcance={"clase_uid": clase["uid"], "prefijo": f"{clase['uid']}-tema"}, **base))
    return salida
