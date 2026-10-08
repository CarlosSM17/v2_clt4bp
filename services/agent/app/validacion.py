"""Validaciones de una propuesta antes de entregarla al instructor.

Bloqueante = el orquestador pide una corrección al modelo. No bloqueante = se muestra como aviso.
"""

import re
from collections import Counter
from typing import Any

import httpx
from pydantic import ValidationError

from app.contracts.diseno_curso_schema import DisenoCurso
from app.ejecutor import Ejecutor, explicar_falla, normalizar, probar
from app.multimedia import validar_multimedia
from app.proveedor.base import resumir_errores
from app.solicitud import ElementoPropuesto, SolicitudGeneracion, Validacion
from app.verificador.modelos import ResultadoCodigo, SolicitudVerificacion
from app.verificador.reglas import verificar

LISTA_POR_TIPO = {
    "objetivo": "objetivos",
    "clase": "clases",
    "tarea": "tareas",
    "soporte": "soporte",
    "procedimental": "procedimental",
    "practica_parcial": "practica_parcial",
    "variante": "variantes",
}
NO_OBSERVABLES = re.compile(
    r"\b(comprend\w*|conoc\w*|sab\w*|entend\w*|aprend\w*|apreci\w*|familiariz\w*|internaliz\w*|valor\w*)\b", re.I
)


def problema(nombre: str, detalle: str, uid: str | None = None, bloqueante: bool = True) -> Validacion:
    return Validacion(nombre=nombre, ok=False, bloqueante=bloqueante, detalle=detalle, elemento_uid=uid)


def validar_objetivos(elementos: list[ElementoPropuesto]) -> list[Validacion]:
    salida = []
    for e in elementos:
        if e.tipo != "objetivo":
            continue
        verbo = e.contenido["descripcion"].split()[0] if e.contenido["descripcion"].split() else ""
        if NO_OBSERVABLES.fullmatch(verbo.strip(",.:;")):
            salida.append(problema("verbo_observable", f"«{verbo}» no es observable: usa escribir, trazar, depurar, explicar…", e.contenido["uid"]))
    return salida


SENALES_DE_CODIGO = {
    "c": re.compile(r"\bmain\s*\("),
    "cpp": re.compile(r"\bmain\s*\("),
    "python": re.compile(r"\b(print|input)\s*\(|^\s*(def|for|while|if|import|from)\b", re.M),
}


def no_es_programa(codigo: str, lenguaje: str, ref: str, uid: str | None = None) -> Validacion | None:
    """Los modelos pequeños a veces escriben en «solucion» una explicación en lugar del programa. Piston solo
    diría «unknown type name 'El'», que no les basta para corregirse: este mensaje dice qué falta."""
    if SENALES_DE_CODIGO[lenguaje].search(codigo or ""):
        return None
    esperado = "con sus #include y su función main" if lenguaje in ("c", "cpp") else "que lea la entrada e imprima la salida"
    return problema(
        "solucion_es_programa",
        f"{ref}: «solucion» debe ser el programa completo en {lenguaje.upper()}, {esperado}, listo para compilar y pasar "
        "los casos de prueba; no una explicación. Las explicaciones van en el enunciado o en «justificacion».",
        uid,
    )


async def probar_codigo(
    elementos: list[ElementoPropuesto], lenguaje: str, ejecutor: Ejecutor
) -> tuple[dict[str, ResultadoCodigo], list[Validacion]]:
    """Ejecuta cada solución contra sus casos; el ejemplo resuelto también debe funcionar tal como lo verá el estudiante."""
    resultados: dict[str, ResultadoCodigo] = {}
    avisos: list[Validacion] = []
    for e in (x for x in elementos if x.tipo == "tarea"):
        t = e.contenido
        if aviso := no_es_programa(t["solucion"], lenguaje, t.get("titulo") or t["uid"], t["uid"]):
            avisos.append(aviso)
        # Solo al generar (el verificador que usa Laravel no cambia): con un caso, ni se comprueba bien la solución
        # ni puede haber uno visible y otro oculto
        # Un programa que no lee datos (autoexplicación, imaginación) tiene una sola ejecución posible: un caso basta
        lee = re.search(r"\b(scanf|cin|getline|fgets|getchar|input)\b", t["solucion"] or "")
        if len(t["casos_prueba"]) < (2 if lee else 1):
            avisos.append(problema("casos_suficientes", f"{t.get('titulo') or t['uid']}: incluye al menos 3 casos de prueba "
                                   f"con datos distintos (tiene {len(t['casos_prueba'])}).", t["uid"]))
        aprobados, total, error = await probar(ejecutor, lenguaje, t["solucion"], t["casos_prueba"])
        detalle = await explicar_falla(ejecutor, lenguaje, t["solucion"], t["casos_prueba"]) if not error and aprobados < total else None
        resultados[t["uid"]] = ResultadoCodigo(aprobados=aprobados, total=total, error_compilacion=error, detalle=detalle)
        # Ejemplo resuelto y su gemelo (ADR 0007): el código inicial es el ejemplo que se estudia y la solución y los
        # casos son del gemelo. El ejemplo debe compilar y correr; no tiene por qué pasar los casos del gemelo
        if t["nivel_apoyo"] == "ejemplo_resuelto" and t["codigo_inicial"].strip() and ejecutor is not None:
            entrada = t["casos_prueba"][0]["entrada"] if t["casos_prueba"] else ""
            r = await ejecutor.ejecutar(lenguaje, t["codigo_inicial"], entrada)
            if not r.compilo:
                avisos.append(problema("ejemplo_ejecutable", f"{t.get('titulo') or t['uid']}: el ejemplo del código inicial no "
                                       f"compila: {r.errores.strip()[:300]}", t["uid"]))
    return resultados, avisos


def diseno_combinado(sol: SolicitudGeneracion, elementos: list[ElementoPropuesto]) -> DisenoCurso:
    """El diseño actual con la propuesta encima: lo que quedaría si el instructor acepta todo."""
    d = sol.diseno.model_dump(mode="json")
    for e in elementos:
        lista = d[LISTA_POR_TIPO[e.tipo]]
        lista[:] = [x for x in lista if x["uid"] != e.contenido["uid"]] + [e.contenido]
    return DisenoCurso.model_validate(d)


def validar_diseno(
    sol: SolicitudGeneracion, elementos: list[ElementoPropuesto], codigo: dict[str, ResultadoCodigo], con_codigo: bool,
    omitir: frozenset[str] = frozenset(),
) -> list[Validacion]:
    try:
        diseno = diseno_combinado(sol, elementos)
    except ValidationError as e:
        return [problema("contratos", resumir_errores(e))]

    tipos = {e.contenido["uid"]: e.tipo for e in elementos}
    variantes = {(e.contenido["elemento_uid"], e.contenido["grupo_clave"]) for e in elementos if e.tipo == "variante"}
    grupos = {g.clave for g in sol.diseno.curso.grupos}
    salida = [problema("grupo_inexistente", f"El grupo {g} no existe en el curso.", u) for u, g in variantes if g not in grupos]

    informe = verificar(SolicitudVerificacion(diseno=diseno, codigo=codigo))
    for h in informe.hallazgos:
        if h.regla in omitir:
            continue
        if h.regla == "codigo_verificado" and not con_codigo:
            continue  # sin Piston: el código se verifica después, en Laravel
        if h.regla == "objetivos" and tipos.get(h.elemento_uid) == "objetivo":
            continue  # un objetivo nuevo todavía no tiene clases: es lo esperado
        if h.elemento_uid in tipos or (h.grupo and (h.elemento_uid, h.grupo) in variantes):
            detalle = h.mensaje + (f" (grupo {h.grupo})" if h.grupo else "")
            salida.append(problema(f"verificador:{h.regla}", detalle, h.elemento_uid, bloqueante=h.nivel == "error"))
    return salida


async def validar_items(items: list[dict[str, Any]], lenguaje: str, ejecutor: Ejecutor) -> list[Validacion]:
    salida: list[Validacion] = []
    for i, it in enumerate(items, start=1):
        ref = f"ítem {i}"
        match it["tipo"]:
            case "opcion_multiple" if len(it["opciones"]) < 3 or not 0 <= it["correcta"] < len(it["opciones"]):
                salida.append(problema("item_opciones", f"{ref}: necesita al menos 3 opciones y un índice de respuesta válido."))
            case "respuesta_corta" if not it["aceptadas"]:
                salida.append(problema("item_aceptadas", f"{ref}: lista las respuestas aceptadas."))
            case "parsons" if len(it["lineas"]) < 3:
                salida.append(problema("item_parsons", f"{ref}: un problema de Parsons necesita al menos 3 líneas."))
            case "prediccion_salida":
                # La respuesta correcta es la salida real del programa: la calcula el sistema, no el modelo
                r = await ejecutor.ejecutar(lenguaje, it["codigo_inicial"])
                if not r.compilo or r.excedio_limite:
                    salida.append(problema("item_salida", f"{ref}: el programa a predecir no compila o no termina; corrígelo."))
                else:
                    it["salida"] = normalizar(r.salida)
            case "programacion":
                casos = it["casos_prueba"]
                if not any(c["oculto"] for c in casos) or all(c["oculto"] for c in casos):
                    salida.append(problema("item_casos", f"{ref}: incluye casos visibles y ocultos."))
                if aviso := no_es_programa(it["solucion"], lenguaje, ref):
                    salida.append(aviso)
                # Como en las tareas, la salida esperada es la de la solución (el modelo escribía «1.70» y cout da «1.7»)
                for c in casos:
                    r = await ejecutor.ejecutar(lenguaje, it["solucion"], c["entrada"])
                    if r.compilo and not r.excedio_limite and r.codigo in (0, None) and r.salida.strip():
                        c["salida_esperada"] = normalizar(r.salida)
                a, n, err = await probar(ejecutor, lenguaje, it["solucion"], casos)
                if err or a < n:
                    detalle = "" if err else f" {await explicar_falla(ejecutor, lenguaje, it['solucion'], casos) or ''}"
                    salida.append(problema("item_solucion", f"{ref}: la solución {'no compila' if err else f'pasa {a} de {n} casos'}.{detalle}".rstrip()))

    # Formas paralelas: mismo número de ítems por objetivo, nivel y forma (si hay forma B: la evaluación del tema pide
    # solo la A, y la B se arma en Pruebas)
    conteo = Counter((it["objetivo"], it["nivel"], it["forma"]) for it in items) if any(it["forma"] == "B" for it in items) else Counter()
    for (objetivo, nivel, forma), n in conteo.items():
        otra = "B" if forma == "A" else "A"
        if conteo.get((objetivo, nivel, otra), 0) != n and forma == "A":
            salida.append(problema("formas_paralelas", f"{objetivo} ({nivel}): la forma B no tiene los mismos ítems que la A.", bloqueante=False))
    return salida


async def validar(
    sol: SolicitudGeneracion, elementos: list[ElementoPropuesto], notas: dict[str, Any], probar_cod: bool, ejecutor: Ejecutor,
    omitir: frozenset[str] = frozenset(),
) -> list[Validacion]:
    """omitir: reglas del verificador que aún no aplican (el soporte de una clase llega en una etapa posterior)."""
    resultado = validar_objetivos(elementos)
    codigo: dict[str, ResultadoCodigo] = {}
    con_codigo = False
    if probar_cod:
        try:
            codigo, avisos = await probar_codigo(elementos, sol.curso.lenguaje, ejecutor)
            if "items" in notas:
                avisos += await validar_items(notas["items"], sol.curso.lenguaje, ejecutor)
            resultado += avisos
            con_codigo = True
        except httpx.HTTPError as e:
            resultado.append(problema("ejecucion", f"No se pudo ejecutar el código ({e.__class__.__name__}); se verificará en el servidor.", bloqueante=False))
    if elementos:
        resultado += validar_diseno(sol, elementos, codigo, con_codigo, omitir)
        # Diagramas y trazas, en cualquier plantilla (el soporte también los lleva)
        resultado += await validar_multimedia(elementos, sol.plantilla)
    return resultado
