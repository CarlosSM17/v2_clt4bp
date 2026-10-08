"""Material multimedia en el Markdown que genera el agente: diagramas Mermaid y trazas de código.

Una traza (bloque ```traza) es una visualización paso a paso de un programa. Quien la escribe (el modelo o el
instructor) solo da el encabezado y el código; los pasos los calcula el trazador ejecutando el programa con gdb
(app/trazador.py, services/trazador). La consola y el aula la muestran como un reproductor paso a paso.

    titulo: Suma de un arreglo
    entrada: 3 10 20 30
    ---
    <el código, tal cual>
    ---
    {"l":3,"f":"main","v":[["n","3"]],"s":""}      ← un paso por línea, calculado
"""

import re

from app.solicitud import ElementoPropuesto, Validacion

BLOQUE = re.compile(r"```[ \t]*([\w-]*)[^\n]*\n(.*?)```", re.S)
TIPOS_MERMAID = re.compile(
    r"\s*(flowchart|graph|sequenceDiagram|classDiagram|stateDiagram(-v2)?|erDiagram|mindmap|timeline|pie|journey|"
    r"gantt|quadrantChart|xychart-beta|block-beta)\b"
)
# Etiquetas sin comillas con símbolos que rompen el analizador de Mermaid: A[suma = a[i]], B{i < n?}, C(printf("x")).
# Un nodo es un identificador seguido de su forma; las formas dobles (A[[…]], A{{…}}, A((…)), A[(…)]) no se tocan
NODO = re.compile(r"\b[A-Za-z_][\w-]*\s*([\[{(])")
CIERRE = {"[": "]", "{": "}", "(": ")"}
NO_ES_ETIQUETA = {"[": "\"[(/\\", "{": "\"{", "(": "\"(["}
SIMBOLOS = re.compile(r"[()\[\]{}<>;\"]")
PASO_CALCULADO = re.compile(r'^\{"l":', re.M)


MARCAS_MD = re.compile(r"^\s*(#{1,4}\s|>\s|\|)")
DIAGRAMA_SUELTO = re.compile(r"^\s*((flowchart|graph)\s+(TD|TB|LR|RL|BT)\b|mindmap\s*$)")


def _cierre_con_markdown(lineas: list[str], i: int) -> int | None:
    """Si la cerca sin lenguaje de la línea i encierra Markdown (títulos, citas, tablas), el índice de su cierre."""
    anidados = 0
    for j in range(i + 1, len(lineas)):
        cerca = lineas[j].strip()
        if cerca.startswith("```"):
            if cerca == "```" and anidados == 0:
                return j if any(MARCAS_MD.match(x) for x in lineas[i + 1:j]) else None
            anidados += -1 if cerca == "```" else 1
    return None


def _sin_cercas_desnudas(md: str) -> str:
    """Una cerca ``` sin lenguaje alrededor de todo el material (mapa del tema del 2026-10-06) lo mostraba como código."""
    lineas, quitar, dentro, i = md.split("\n"), set(), False, 0
    while i < len(lineas):
        cerca = lineas[i].strip()
        if cerca.startswith("```"):
            if dentro:
                dentro = False
            elif cerca == "```" and (cierre := _cierre_con_markdown(lineas, i)) is not None:
                quitar |= {i, cierre}
            else:
                dentro = True
        i += 1
    return "\n".join(x for n, x in enumerate(lineas) if n not in quitar)


def cercar_diagramas(md: str) -> str:
    """Un diagrama escrito como texto («flowchart LR» y sus aristas, sin ```mermaid) va dentro de su bloque."""
    lineas, salida, dentro, i = md.split("\n"), [], False, 0
    while i < len(lineas):
        linea = lineas[i]
        if linea.strip().startswith("```"):
            dentro = not dentro
        elif not dentro and DIAGRAMA_SUELTO.match(linea):
            fin = i + 1
            while fin < len(lineas) and lineas[fin].strip() and not MARCAS_MD.match(lineas[fin]) and not lineas[fin].strip().startswith("```"):
                fin += 1
            salida += ["```mermaid", *lineas[i:fin], "```"]
            i = fin
            continue
        salida.append(linea)
        i += 1
    return "\n".join(salida)


EMOJIS_RECUADRO = "💡❓🎯⚠🙋🗺✎⌨☑🐞⇄👥🎙"
NO_ES_PARRAFO = re.compile(r"\s*(>|#|```|\||[-*] |\d+\. )")


def cerrar_cercas(md: str) -> str:
    """El modelo a veces no cierra el bloque del programa y escribe su salida sin abrir la suya («```cpp … }» y debajo
    «entrada: Juan» y «---», ejemplo isomórfico del 2026-10-06): todo lo que sigue se veía como código. Una línea
    «entrada:» dentro de un bloque de código cierra el programa y abre su ```salida (que termina en el «---»), y un bloque
    que queda abierto al final se cierra."""
    lineas, salida, abierto, en_salida = md.split("\n"), [], None, False
    for linea in lineas:
        cerca = linea.strip()
        if cerca.startswith("```"):
            abierto = None if abierto else (cerca[3:].strip().lower() or "texto")
            en_salida = False
        elif abierto in LENGUAJES_CODIGO and re.match(r"\s*entrada:\s", linea, re.I):
            salida += ["```", "```salida"]
            abierto, en_salida = "salida", True
        elif en_salida and cerca == "---":
            salida += [linea, "```"]
            abierto, en_salida = None, False
            continue
        salida.append(linea)
    if abierto:
        salida.append("```")
    return "\n".join(salida)


LENGUAJES_CODIGO = {"c", "cpp", "c++", "python", "py"}


def reparar_recuadros(md: str) -> str:
    """Los recuadros del mapa de ruta son una cita que empieza con su emoji (ADR 0007). El modelo a veces deja la cita
    vacía y el título fuera («>» y debajo «💡 **Analogía · …**», 2026-10-06), o solo el título en la cita y el texto
    afuera: el título vuelve a la cita y el párrafo que lo sigue pasa a ser el cuerpo del recuadro."""
    lineas, salida, dentro, i = md.split("\n"), [], False, 0
    while i < len(lineas):
        linea = lineas[i]
        if linea.strip().startswith("```"):
            dentro = not dentro
        elif not dentro and linea.strip() == ">" and i + 1 < len(lineas) and lineas[i + 1][:1] in EMOJIS_RECUADRO:
            i += 1
            linea = "> " + lineas[i]
        if not dentro and linea.startswith("> ") and linea[2:3] in EMOJIS_RECUADRO and linea.rstrip().endswith("**"):
            j = i + 1 + (i + 1 < len(lineas) and not lineas[i + 1].strip())
            cuerpo = []
            while j < len(lineas) and lineas[j].strip() and not NO_ES_PARRAFO.match(lineas[j]):
                cuerpo.append("> " + lineas[j])
                j += 1
            salida += [linea, *cuerpo]
            i = j if cuerpo else i + 1
            continue
        salida.append(linea)
        i += 1
    return "\n".join(salida)


def desenvolver_markdown(md: str) -> str:
    """Quita las cercas ```markdown que el modelo pone alrededor de una tabla o una lista: con ellas se verían como
    código en lugar de dibujarse. Respeta los bloques de código que haya dentro."""
    lineas, salida = _sin_cercas_desnudas(md).split("\n"), []
    dentro, anidados = False, 0
    for linea in lineas:
        cerca = linea.strip()
        if not dentro and cerca.lower() in ("```markdown", "```md"):
            dentro, anidados = True, 0
            continue
        if dentro and cerca.startswith("```"):
            if cerca == "```" and anidados == 0:
                dentro = False
                continue
            anidados += -1 if cerca == "```" else 1
        salida.append(linea)
    return "\n".join(salida)


def _entre_comillas(linea: str, posicion: int) -> bool:
    return linea.count('"', 0, posicion) % 2 == 1


def _etiquetas_peligrosas(linea: str) -> list[tuple[int, int, int]]:
    """Dónde empieza su nodo y dónde empieza y termina cada etiqueta sin comillas con símbolos. El cierre es el que corresponde a la apertura
    (en A[max = a[0]] la etiqueta es «max = a[0]»), y lo que ya está entre comillas no se revisa."""
    salida, i = [], 0
    while m := NODO.search(linea, i):
        abre, inicio = m.group(1), m.end()
        i = inicio
        if _entre_comillas(linea, m.start()) or linea[inicio:inicio + 1] in NO_ES_ETIQUETA[abre]:
            continue
        profundidad, fin = 0, None
        for k in range(inicio, len(linea)):
            if linea[k] == abre:
                profundidad += 1
            elif linea[k] == CIERRE[abre]:
                if profundidad == 0:
                    fin = k
                    break
                profundidad -= 1
        if fin is None:
            continue
        if SIMBOLOS.search(linea[inicio:fin]):
            salida.append((m.start(), inicio, fin))
        i = fin + 1
    return salida


def reparar_mermaid(codigo: str) -> str:
    """Pone entre comillas las etiquetas con símbolos (B{¿n > 0?} → B{"¿n > 0?"}): el modelo pequeño no lo corrige ni
    con la retroalimentación, y es un cambio mecánico."""
    if not re.match(r"\s*(flowchart|graph)\b", codigo):
        return codigo
    lineas = []
    for linea in codigo.split("\n"):
        for _, inicio, fin in reversed(_etiquetas_peligrosas(linea)):  # de derecha a izquierda: las posiciones no se mueven
            linea = linea[:inicio] + '"' + linea[inicio:fin].replace('"', "#quot;") + '"' + linea[fin:]
        lineas.append(linea)
    return "\n".join(lineas)


def _reparar_bloque(m: re.Match) -> str:
    if m.group(1).lower() != "mermaid":
        return m.group(0)
    inicio, fin = m.start(2) - m.start(0), m.end(2) - m.start(0)
    return m.group(0)[:inicio] + reparar_mermaid(m.group(2)) + m.group(0)[fin:]


def normalizar_markdown(elementos: list[ElementoPropuesto]) -> None:
    for e in elementos:
        for clave, valor in e.contenido.items():
            if clave.endswith("_md") and isinstance(valor, str):
                valor = reparar_recuadros(cercar_diagramas(desenvolver_markdown(cerrar_cercas(valor) if "```" in valor else valor)))
                valor = re.sub(r"^[ \t]*\|[ \t]*\n", "", valor, flags=re.M)  # un «|» suelto antes de la tabla (tarjeta de sintaxis)
                e.contenido[clave] = BLOQUE.sub(_reparar_bloque, valor) if "```m" in valor else valor


def _etiqueta(texto: str) -> str:
    """Texto plano para una etiqueta de Mermaid entre comillas: sin Markdown, sin emojis ni comillas."""
    # Sin < ni >: Mermaid los lee como HTML («vector<int>» desaparecería)
    limpio = re.sub(r"[`*_\"<>]|[^\w\s.,:;¿?¡!()+\-/=]", "", texto)
    return " ".join(limpio.split())[:60]


def diagrama_por_omision(elementos: list[ElementoPropuesto]) -> list[Validacion]:
    """Los conceptos y el mapa del tema llevan diagrama. Si el modelo no lo puso (`qwen3:4b` lo omitió en tres intentos,
    2026-10-06), se agrega un resumen visual con los conceptos del texto (sus títulos ### o, si no hay, ##): el tema al
    centro y cada concepto alrededor, como el «Resumen visual» del mapa de ruta. Es aviso: el instructor puede cambiarlo."""
    avisos = []
    for e in elementos:
        md = e.contenido.get("cuerpo_md")
        if (e.tipo != "soporte" or e.contenido.get("tipo") not in ("modelo_mental", "mapa_conceptual")
                or not isinstance(md, str) or "```mermaid" in md):
            continue
        conceptos = re.findall(r"^###\s+(.+)$", md, re.M) or re.findall(r"^##\s+(.+)$", md, re.M)[1:]
        if len(conceptos) < 2:
            # El mapa del tema casi no tiene títulos: sus conceptos son los términos de la primera columna del glosario
            filas = [f for f in re.findall(r"^\s*\|([^|\n]+)\|", md, re.M) if not re.fullmatch(r"\s*:?-+:?\s*", f)]
            conceptos = filas[1:]
        conceptos = [c for c in (_etiqueta(x) for x in conceptos) if c][:8]
        if len(conceptos) < 2:
            continue
        tema = _etiqueta(e.contenido.get("titulo") or "Tema") or "Tema"
        lineas = ["flowchart LR", f'  T(("{tema}"))'] + [f'  T --> C{i}["{c}"]' for i, c in enumerate(conceptos, start=1)]
        e.contenido["cuerpo_md"] = (md.rstrip() + f"\n\n> 🗺 **Resumen visual · {tema}**\n\n```mermaid\n" + "\n".join(lineas)
                                    + "\n```\n")
        avisos.append(Validacion(nombre="diagrama_automatico", ok=False, bloqueante=False, elemento_uid=e.contenido.get("uid"),
                                 detalle=f"{e.contenido.get('titulo')}: no traía diagrama; el sistema agregó un resumen visual "
                                 "con sus conceptos. Cámbialo por uno de la memoria o una comparación si lo prefieres."))
    return avisos


def _sin_pasos_bloque(m: re.Match) -> str:
    if m.group(1).lower() != "traza":
        return m.group(0)
    partes = re.split(r"^\s*---\s*$", m.group(2), flags=re.M)
    if len(partes) != 3:
        return m.group(0)
    return f"```traza\n{partes[0].strip(chr(10))}\n---\n{partes[1].strip(chr(10))}\n```"


def sin_pasos(md: str) -> str:
    """El Markdown sin los pasos calculados de sus trazas (encabezado y código sí): son datos para el reproductor,
    ocupan miles de tokens (cada paso lleva el diagrama de memoria) y el modelo nunca los escribe."""
    return BLOQUE.sub(_sin_pasos_bloque, md) if "```traza" in (md or "") else md


def bloques(md: str) -> list[tuple[str, str]]:
    return [(m.group(1).lower(), m.group(2)) for m in BLOQUE.finditer(md or "")]


def errores_mermaid(codigo: str) -> list[str]:
    if not TIPOS_MERMAID.match(codigo):
        return ["no empieza con un tipo de diagrama de Mermaid (p. ej., «flowchart TD»)"]
    if not re.match(r"\s*(flowchart|graph)\b", codigo):
        return []
    salida = [f"la etiqueta «{linea[nodo:fin + 1]}» lleva símbolos: escríbela entre comillas, p. ej. A[\"…\"]"
              for linea in codigo.splitlines() for nodo, _, fin in _etiquetas_peligrosas(linea)]
    return salida[:3]


def textos_md(e: ElementoPropuesto) -> list[tuple[str, str]]:
    c = e.contenido
    return [(campo, c[campo]) for campo in ("cuerpo_md", "enunciado_md") if isinstance(c.get(campo), str)]


async def validar_multimedia(elementos: list[ElementoPropuesto], plantilla: str = "") -> list[Validacion]:
    """Qué se exige depende de la plantilla: en «info_soporte» el diagrama es obligatorio; en una clase de tareas
    (la respuesta más larga) un modelo pequeño lo omite aunque se le pida al corregir, así que ahí es sugerencia."""
    salida: list[Validacion] = []

    def falla(nombre: str, detalle: str, uid: str, bloqueante: bool = True) -> None:
        salida.append(Validacion(nombre=nombre, ok=False, bloqueante=bloqueante, detalle=detalle, elemento_uid=uid))

    for e in elementos:
        uid, titulo = e.contenido.get("uid", ""), e.contenido.get("titulo") or e.contenido.get("uid", "")
        hay_diagrama = hay_traza = False
        for _campo, md in textos_md(e):
            for lenguaje_bloque, codigo in bloques(md):
                if lenguaje_bloque == "mermaid":
                    hay_diagrama = True
                    for error in errores_mermaid(codigo):
                        falla("diagrama_valido", f"{titulo}: un diagrama {error}.", uid)
                elif lenguaje_bloque == "traza":
                    hay_traza = True
                    # Los pasos los calcula el trazador (Orquestador.completar_trazas): si no hay, no respondió
                    if not PASO_CALCULADO.search(codigo):
                        falla("traza_calculada", f"{titulo}: una traza quedó sin sus pasos (el trazador no respondió); "
                              "en la consola, «Calcular pasos» los obtiene.", uid, bloqueante=False)
        # Información de soporte: los conceptos y el mapa del tema llevan diagrama (la memoria, una comparación, el mapa);
        # el ejemplo resuelto (sap) va con algoritmo, pseudocódigo y código, y un guion de video no lleva (ADR 0007)
        if e.tipo == "soporte" and e.contenido.get("tipo") in ("modelo_mental", "mapa_conceptual") and not hay_diagrama:
            falla("soporte_con_diagrama", f"{titulo}: la información de soporte necesita al menos un diagrama en un bloque "
                  "```mermaid junto al texto que explica (la memoria, una comparación o el mapa del tema).", uid,
                  bloqueante=plantilla in ("info_soporte", "mapa_glosario"))
        # El primer ejemplo resuelto se ve paso a paso; los de autoexplicación o imaginación no (el estudiante lo deduce)
        if (e.tipo == "tarea" and e.contenido.get("nivel_apoyo") == "ejemplo_resuelto"
                and not e.contenido.get("pide_autoexplicacion") and not hay_traza):
            falla("ejemplo_con_traza", f"{titulo}: un ejemplo resuelto se entiende mejor con una traza paso a paso "
                  "(bloque ```traza) en el enunciado.", uid, bloqueante=False)
    return salida
