"""Plantillas de trabajo por artefacto: qué pedir, con qué esquema de salida y cómo convertir la respuesta.

Cada plantilla corresponde a un paso de CLT4BP. Para agregar una, define su modelo de salida en
modelos.py, su conversión aquí y regístrala en PLANTILLAS.
"""

import json
import re
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any, Literal

from pydantic import BaseModel

from app.contracts.comunes_schema import Componente, Lenguaje
from app.contracts.info_procedimental_schema import Tipo as TipoProcedimental
from app.contracts.info_soporte_schema import Tipo as TipoSoporte
from app.multimedia import sin_pasos
from app.solicitud import ElementoPropuesto, SolicitudGeneracion

from . import modelos as m

Conversion = tuple[list[ElementoPropuesto], dict[str, Any]]


@dataclass(frozen=True)
class Plantilla:
    clave: str
    paso: int
    titulo: str
    salida: type[BaseModel]
    instrucciones: str
    convertir: Callable[[Any, SolicitudGeneracion], Conversion]
    nivel_modelo: Literal["ligero", "normal"] = "normal"
    max_tokens: int = 16000
    probar_codigo: bool = False  # ejecutar soluciones y ejemplos en Piston antes de entregar


def elemento(tipo: str, modelo: BaseModel) -> ElementoPropuesto:
    # Sin nulos: los campos opcionales del contrato (clase_uid, tarea_uid, ficha_md) son cadenas o no van
    return ElementoPropuesto(tipo=tipo, contenido=modelo.model_dump(mode="json", exclude_none=True))


def prefijo(sol: SolicitudGeneracion) -> str:
    """Laravel manda un prefijo único por trabajo: los uid nuevos nunca chocan con los existentes."""
    return str(sol.alcance.get("prefijo", "ia"))


# ---------- Conversiones ----------


def convertir_objetivos(p: m.PropuestaObjetivos, sol: SolicitudGeneracion) -> Conversion:
    inicio = len(sol.diseno.objetivos) + 1
    for i, o in enumerate(p.objetivos):
        o.uid, o.orden, o.codigo = f"{prefijo(sol)}-ob{i + 1}", inicio + i, f"OB-{inicio + i}"
    return [elemento("objetivo", o) for o in p.objetivos], {"jerarquia": p.jerarquia}


def convertir_clase(p: m.PropuestaClase, sol: SolicitudGeneracion) -> Conversion:
    clase_uid = f"{prefijo(sol)}-tc"
    p.clase.uid = clase_uid
    p.clase.orden = int(sol.alcance.get("orden", len(sol.diseno.clases) + 1))
    p.clase.diseno.paso_clt4bp, p.clase.diseno.componente = 5, Componente.tarea

    nuevos = _ajustar_tareas(p.tareas, sol, clase_uid, 1, p.advertencias)

    for i, s in enumerate(p.soporte, start=1):
        s.uid, s.clase_uid = f"{clase_uid}-s{i}", clase_uid
        s.diseno.paso_clt4bp, s.diseno.componente = 6, Componente.soporte

    for i, a in enumerate(p.procedimental, start=1):
        # Si el modelo cita una tarea que no existe, la ayuda se asigna a la primera tarea y se advierte
        if a.tarea_uid not in nuevos and p.tareas:
            p.advertencias.append(f"La ayuda «{a.titulo}» citaba una tarea inexistente; se asignó a la primera.")
        a.tarea_uid, a.clase_uid = nuevos.get(a.tarea_uid, p.tareas[0].uid if p.tareas else ""), None
        a.uid = f"{a.tarea_uid}-p{i}"
        a.diseno.paso_clt4bp, a.diseno.componente = 7, Componente.procedimental

    elementos = [
        elemento("clase", p.clase),
        *[elemento("tarea", t) for t in p.tareas],
        *[elemento("soporte", s) for s in p.soporte],
        *[elemento("procedimental", a) for a in p.procedimental],
    ]
    return elementos, {}


def _ajustar_tareas(tareas: list[Any], sol: SolicitudGeneracion, clase_uid: str, inicio: int, advertencias: list[str]) -> dict[str, str]:
    """uid, orden, clase y lenguaje definitivos de cada tarea nueva, y sus casos visibles y ocultos."""
    nuevos: dict[str, str] = {}
    for i, t in enumerate(tareas, start=inicio):
        nuevos[t.uid] = f"{clase_uid}-t{i}"
        t.uid, t.clase_uid, t.orden, t.lenguaje = nuevos[t.uid], clase_uid, i, Lenguaje(sol.curso.lenguaje)
        t.diseno.paso_clt4bp, t.diseno.componente = 5, Componente.tarea
        # Los modelos pequeños casi nunca marcan casos ocultos, ni aunque se les pida al corregir. Con dos o más
        # visibles basta ocultar el último: el problema sin apoyo cumple la regla y sigue teniendo ejemplos
        if (getattr(t.nivel_apoyo, "value", t.nivel_apoyo) in ("convencional", "solucion_libre")
                and len(t.casos_prueba) >= 2 and not any(c.oculto for c in t.casos_prueba)):
            t.casos_prueba[-1].oculto = True
            advertencias.append(f"«{t.titulo}»: se marcó como oculto el último caso de prueba (un problema sin apoyo necesita uno).")
        # Y al revés: si todos son ocultos, el estudiante no tiene ningún ejemplo; el primero pasa a visible
        if len(t.casos_prueba) >= 2 and all(c.oculto for c in t.casos_prueba):
            t.casos_prueba[0].oculto = False
            advertencias.append(f"«{t.titulo}»: se hizo visible el primer caso de prueba (todos estaban ocultos).")
    return nuevos


def convertir_complementarias(p: m.PropuestaTareas, sol: SolicitudGeneracion) -> Conversion:
    """T5–T8 de una clase que ya tiene sus primeras tareas: siguen su numeración."""
    clase_uid = str(sol.alcance["clase_uid"])
    previas = sum(1 for t in sol.diseno.tareas if t.clase_uid == clase_uid)
    _ajustar_tareas(p.tareas, sol, clase_uid, previas + 1, p.advertencias)
    return [elemento("tarea", t) for t in p.tareas], {}


def soporte_de(tipo: TipoSoporte | None) -> Callable[[m.PropuestaSoporte, SolicitudGeneracion], Conversion]:
    """Soporte de la clase de <alcance>. Con `tipo`, el primer elemento lo lleva (cada plantilla del tema produce uno:
    conceptos → modelo mental, ejemplo resuelto → sap, mapa y glosario → mapa conceptual)."""

    def convertir(p: m.PropuestaSoporte, sol: SolicitudGeneracion) -> Conversion:
        clase_uid = str(sol.alcance["clase_uid"])
        for i, s in enumerate(p.soporte, start=1):
            s.uid, s.clase_uid = f"{prefijo(sol)}-s{i}", clase_uid
            s.diseno.paso_clt4bp, s.diseno.componente = 6, Componente.soporte
            if tipo is not None and i == 1:
                s.tipo = tipo
        return [elemento("soporte", s) for s in p.soporte], {}

    return convertir


convertir_soporte = soporte_de(None)


def procedimental_de(tipo: TipoProcedimental | None) -> Callable[[m.PropuestaProcedimental, SolicitudGeneracion], Conversion]:
    """Información procedimental del tema (alcance con clase_uid) o de una tarea (tarea_uid): cuelga de una sola."""

    def convertir(p: m.PropuestaProcedimental, sol: SolicitudGeneracion) -> Conversion:
        clase_uid, tarea_uid = sol.alcance.get("clase_uid"), sol.alcance.get("tarea_uid")
        for i, a in enumerate(p.procedimental, start=1):
            a.uid = f"{prefijo(sol)}-p{i}"
            a.tarea_uid, a.clase_uid = (str(tarea_uid), None) if tarea_uid else (None, str(clase_uid))
            a.diseno.paso_clt4bp, a.diseno.componente = 7, Componente.procedimental
            if tipo is not None:
                a.tipo = tipo
        return [elemento("procedimental", a) for a in p.procedimental], {}

    return convertir


convertir_procedimental = procedimental_de(None)


def convertir_ficha(p: m.PropuestaFicha, sol: SolicitudGeneracion) -> Conversion:
    """La ficha de diseño se guarda en la clase: se propone la misma clase con su «ficha_md»."""
    clase_uid = str(sol.alcance["clase_uid"])
    clase = next((c for c in sol.diseno.clases if c.uid == clase_uid), None)
    if clase is None:
        p.advertencias.append(f"La clase {clase_uid} no está en el diseño: la ficha queda en las notas.")
        return [], {"ficha_md": p.ficha_md}
    con_ficha = clase.model_copy(deep=True)
    con_ficha.ficha_md = p.ficha_md.strip()
    return [elemento("clase", con_ficha)], {}


def convertir_diferenciacion(p: m.PropuestaDiferenciacion, sol: SolicitudGeneracion) -> Conversion:
    tareas = {t.uid: t for t in sol.diseno.tareas}
    elementos = []
    for v in p.variantes:
        base = tareas.get(v.tarea_uid)
        if base is None:
            p.advertencias.append(f"Se descartó una variante para la tarea inexistente {v.tarea_uid}.")
            continue
        cambios: dict[str, Any] = {}
        if v.cambios.enunciado_md.strip():
            cambios["enunciado_md"] = v.cambios.enunciado_md
        if v.cambios.nivel_apoyo != "heredar":
            cambios["nivel_apoyo"] = v.cambios.nivel_apoyo
        if v.cambios.codigo_inicial.strip():
            cambios["codigo_inicial"] = v.cambios.codigo_inicial
        if v.cambios.arcs_confianza.strip():
            cambios["arcs"] = {**base.arcs.model_dump(), "confianza": v.cambios.arcs_confianza}
        if not cambios:
            continue
        elementos.append(
            ElementoPropuesto(
                tipo="variante",
                contenido={
                    "uid": f"{v.tarea_uid}-{v.grupo_clave}",  # misma convención que la consola
                    "elemento_uid": v.tarea_uid,
                    "grupo_clave": v.grupo_clave,
                    "cambios": json.loads(json.dumps(cambios, default=str)),
                    "diferenciacion": {"dimensiones": v.dimensiones or ["proceso"], "razon": v.razon},
                },
            )
        )
    return elementos, {"planes": [x.model_dump() for x in p.planes]}


def solo_notas(clave: str) -> Callable[[Any, SolicitudGeneracion], Conversion]:
    def convertir(p: BaseModel, _sol: SolicitudGeneracion) -> Conversion:
        datos = p.model_dump(mode="json")
        datos.pop("advertencias", None)
        # Si queda un solo campo (p. ej. «items»), se guarda su valor directamente
        return [], {clave: next(iter(datos.values())) if len(datos) == 1 else datos}

    return convertir


# ---------- Registro ----------

PLANTILLAS: dict[str, Plantilla] = {
    "objetivos": Plantilla(
        "objetivos",
        1,
        "Objetivos de desempeño",
        m.PropuestaObjetivos,
        "Propón objetivos de desempeño para el tema de <alcance>: verbo observable, condición y criterio medible "
        "(por ejemplo, «Escribir un programa en C que lea n datos y calcule su promedio, sin errores de compilación "
        "y con los casos de prueba correctos»). Evita verbos no observables como comprender, conocer o saber. "
        "Indica en «evaluacion» con qué métodos se comprobará cada uno y describe la jerarquía de habilidades.",
        convertir_objetivos,
    ),
    "resumen_grupo": Plantilla(
        "resumen_grupo",
        2,
        "Resumen pedagógico del grupo",
        m.PropuestaResumen,
        "Resume en lenguaje pedagógico el perfil agregado de cada grupo de <grupos>: fortalezas, riesgos e "
        "implicaciones para el diseño. Usa solo las cifras que recibes; no inventes datos.",
        solo_notas("resumen"),
        nivel_modelo="ligero",
        max_tokens=4000,
    ),
    "preseleccion": Plantilla(
        "preseleccion",
        3,
        "Preselección de efectos",
        m.PropuestaPreseleccion,
        "Para el grupo indicado en <alcance>, elige los efectos del catálogo que conviene aplicar y en qué "
        "componente 4C/ID. Parte de los efectos sugeridos por las reglas en <grupos> y justifica cada elección, "
        "también si descartas uno sugerido.",
        solo_notas("preseleccion"),
        nivel_modelo="ligero",
        max_tokens=4000,
    ),
    "diferenciacion": Plantilla(
        "diferenciacion",
        4,
        "Estrategias diferenciadas",
        m.PropuestaDiferenciacion,
        "Diseña la diferenciación (Tomlinson) para los grupos de <grupos>: un plan de contenido, proceso y "
        "producto por grupo, y variantes concretas de las tareas existentes en <diseno_actual> que las "
        "necesiten. Una variante solo cambia lo necesario (usa «heredar» y cadenas vacías para lo demás) y nunca "
        "cambia la solución ni los casos de prueba. Respeta el desvanecimiento de la guía en cada grupo.",
        convertir_diferenciacion,
    ),
    "clase_tareas": Plantilla(
        "clase_tareas",
        5,
        "Clase de tareas",
        m.PropuestaClase,
        "Diseña la clase de tareas del tema para los objetivos de <alcance>. El tema lo fijan esos objetivos (su "
        "descripción viene en <alcance>) y <indicaciones_instructor>; las clases de <diseno_actual> solo dicen qué tan "
        "compleja debe ser la nueva (más que ellas), no su tema. Propón la clase y sus primeras 4 tareas, de menor a "
        "mayor complejidad y con la ayuda que se desvanece; cada una es un ejercicio distinto (otro problema, otro "
        "escenario y otros datos) y su título nombra ese problema (p. ej., «Registro en la veterinaria», «Perfil de "
        "videojuego»), nunca el nivel de apoyo:\n"
        "T1 ejemplo resuelto y su gemelo («ejemplo_resuelto»): el enunciado lleva «**Paso 1 · Estudia:**» con el ejemplo "
        "en un bloque ```traza (líneas «titulo:» y «entrada:», «---» y el programa completo) y «**Paso 2 · Ahora tú "
        "(gemelo).**» con un problema parecido en otro contexto; «codigo_inicial» es el programa del ejemplo; «solucion» "
        "y los casos son del gemelo.\n"
        "T2 por completar con pistas («por_completar»): «codigo_inicial» es la solución con huecos `/* HUECO n: pista */`.\n"
        "T3 por completar sin pistas («por_completar»): huecos `/* HUECO n */`, sin pista.\n"
        "T4 problema convencional («convencional», «codigo_inicial» vacío): requisitos en lista y un ejemplo de ejecución.\n"
        "Cada enunciado empieza con «**Contexto.**» (un escenario auténtico) y «**¿Para qué?**» (relevancia, ARCS); "
        "«diseno.tiempo_estimado_min» entre 15 y 35. Deja «soporte» y «procedimental» vacíos: el sistema genera después "
        "las tareas T5 a T8 y el resto del tema. Cada programa es completo (con sus #include y main), lee TODOS sus datos "
        "de la entrada estándar (cin o scanf), nunca de valores fijos, y cada tarea trae al menos 3 casos con entradas "
        "distintas entre sí y de las otras tareas (incluye un caso límite con un solo dato; no uses n = 0 ni entradas "
        "vacías). Las salidas esperadas las calcula el sistema ejecutando tu solución: lo importante es que sea correcta "
        "y use la entrada.",
        convertir_clase,
        probar_codigo=True,
        max_tokens=32000,
    ),
    "tareas_complementarias": Plantilla(
        "tareas_complementarias",
        5,
        "Tareas T5 a T8",
        m.PropuestaTareas,
        "La clase de <alcance> ya tiene sus primeras tareas (T1 a T4 en <diseno_actual>). Propón sus 4 tareas siguientes, "
        "del mismo tema y distintas de ellas y entre sí, cada una con un título que nombre su problema:\n"
        "T5 solución libre («solucion_libre», «codigo_inicial» vacío): un problema con una salida bien definida que se "
        "puede resolver de muchas formas, sin guía; lee sus datos de la entrada y trae al menos 3 casos.\n"
        "T6 autoexplicación («ejemplo_resuelto», «pide_autoexplicacion» verdadero): un programa completo y corto del tema "
        "que NO lee datos y cuya salida sorprende (p. ej., una conversión que trunca, un desbordamiento, un char sumado a "
        "un número); el enunciado pide explicar con sus palabras por qué ocurre cada línea de la salida; «codigo_inicial» "
        "y «solucion» son ese programa; un solo caso con entrada vacía.\n"
        "T7 imaginación («ejemplo_resuelto», «pide_autoexplicacion» verdadero): un programa completo y corto que NO lee "
        "datos; el enunciado pide imaginar, sin ejecutarlo, el contenido de cada variable después de cada línea y luego "
        "comprobar la salida; «codigo_inicial» y «solucion» son ese programa; un solo caso con entrada vacía.\n"
        "T8 reto colaborativo («convencional», «colaborativa» verdadero, «codigo_inicial» vacío): un problema más "
        "completo; el enunciado dice qué datos y qué parte resuelve cada integrante («**Integrante 1:** …») y cómo "
        "integran el programa; lee sus datos de la entrada y trae al menos 3 casos.\n"
        "Cada enunciado empieza con «**Contexto.**» y «**¿Para qué?**»; «diseno.tiempo_estimado_min» entre 15 y 35. "
        "Cada programa es completo (con sus #include y main). Las salidas esperadas las calcula el sistema.",
        convertir_complementarias,
        probar_codigo=True,
        max_tokens=24000,
    ),
    "ficha_tema": Plantilla(
        "ficha_tema",
        4,
        "Ficha de diseño del tema",
        m.PropuestaFicha,
        "Escribe la ficha de diseño instruccional del tema de la clase indicada en <alcance>, para el instructor, en "
        "Markdown («ficha_md»): una primera línea con **Tema**, **Duración sugerida** y **Conocimientos previos**; "
        "«### 1. Objetivos de aprendizaje por ruta» (tabla Ruta | Al terminar podrás…, una fila por grupo de <grupos>, "
        "o «Todo el grupo» si no hay grupos); «### 2. Métodos de evaluación» (tabla Instrumento | Tipo de conocimiento "
        "| Contenido en este tema: recall test, comprehension test, carga cognitiva, IMMS y MSLQ); «### 3. Efectos de la "
        "TCC por componente 4C/ID» (tabla Componente | Efecto | Cómo se aplica en este tema, con efectos del catálogo); "
        "«### 4. Matriz de instrucción diferenciada» (tabla Elemento | Componente 4C/ID | una columna por ruta, con las "
        "filas Contenido, Proceso, Producto y Entorno); «### 5. Plan de implementación» (tabla Momento | Componente | "
        "Actividad | Tiempo, de la apertura al cierre). Usa las tareas y el material de la clase en <diseno_actual>.",
        convertir_ficha,
        max_tokens=8000,
    ),
    "info_soporte": Plantilla(
        "info_soporte",
        6,
        "Información de soporte: conceptos",
        m.PropuestaSoporte,
        "Escribe la información de soporte de la clase indicada en <alcance>: un solo elemento (tipo «modelo_mental») "
        "titulado «Conceptos del tema», en Markdown y en este orden:\n"
        "1. Tres recuadros: «> 💡 **Analogía · <título>**» (una comparación de la vida diaria que explique la idea "
        "central), «> ❓ **Pregunta para pensar**» (una pregunta que active lo que el estudiante ya sabe) y «> 🎯 "
        "**¿Por qué y para qué?**» (dónde se usa en la vida real y qué error evita elegir bien).\n"
        "2. «## Conceptos, uno por uno»: de 4 a 8 conceptos, cada uno «### n. <nombre>» con una a tres frases; un "
        "programa corto y completo (con sus #include y main, y que muestre con cout lo que explica) en un bloque de "
        "código con notas al final de las líneas clave en la forma `//→ nota` "
        "(`#→ nota` en Python); justo debajo, un bloque ```salida (si el programa lee datos, la línea «entrada: …» con "
        "\\n entre renglones y luego «---»; la salida la escribe el sistema); cuando ayude, un diagrama Mermaid (la "
        "memoria como cajas con nombre, tipo y valor, o una comparación) o una tabla; y, si hay una trampa típica, un "
        "recuadro «> ⚠️ **¡Cuidado!** …».\n"
        "3. «> 🙋 **Actividad en el aula · <nombre> (10 min)**»: una dinámica en la que los estudiantes representan el "
        "concepto con su cuerpo o con objetos.\n"
        "Lleva al menos un diagrama Mermaid (obligatorio): por ejemplo, las cajas de memoria de las variables de un "
        "concepto, con su nombre, tipo y valor. Solo lo que necesitan las tareas de la clase; frases cortas, un concepto "
        "a la vez.",
        soporte_de(TipoSoporte.modelo_mental),
        probar_codigo=True,
    ),
    "ejemplo_resuelto_tema": Plantilla(
        "ejemplo_resuelto_tema",
        6,
        "Información de soporte: ejemplo resuelto",
        m.PropuestaSoporte,
        "Escribe el ejemplo resuelto del tema de la clase indicada en <alcance>: un solo elemento (tipo «sap») titulado "
        "«Ejemplo resuelto: <problema>», en Markdown: «**Problema.**» (un escenario auténtico, distinto de las tareas de "
        "la clase), «### A. Algoritmo» (pasos numerados), «### B. Pseudocódigo» (un bloque ```pseint al estilo PSeInt: "
        "Algoritmo, Definir … Como …, Escribir, Leer, FinAlgoritmo), «### C. Código» (el programa completo con notas "
        "`//→ nota` en las líneas clave) seguido de su bloque ```salida con «entrada: …» y «---» (la salida la escribe "
        "el sistema) y «### D. Verificación» (qué demuestra esa ejecución).",
        soporte_de(TipoSoporte.sap),
        probar_codigo=True,
    ),
    "mapa_glosario": Plantilla(
        "mapa_glosario",
        6,
        "Información de soporte: mapa y glosario",
        m.PropuestaSoporte,
        "Escribe el cierre visual del tema de la clase indicada en <alcance>: un solo elemento (tipo «mapa_conceptual») "
        "titulado «Mapa del tema y glosario», en Markdown: «> 🗺 **Resumen visual · Mapa del tema**» seguido de un "
        "diagrama Mermaid (flowchart LR) que relacione los conceptos de la clase entre sí; «## Glosario bilingüe» (tabla "
        "Español | English | Significado, de 8 a 15 términos del tema); y «## Fuentes» (los documentos de "
        "<material_curso> que respaldan el tema, con su página; si no hay material, omite esta sección).",
        soporte_de(TipoSoporte.mapa_conceptual),
        nivel_modelo="ligero",
        max_tokens=6000,
    ),
    "info_procedimental": Plantilla(
        "info_procedimental",
        7,
        "Información procedimental del tema",
        m.PropuestaProcedimental,
        "Escribe la información procedimental del tema de la clase indicada en <alcance> (o de la tarea, si <alcance> "
        "indica una), para consultar justo a tiempo mientras se resuelven las tareas. Cuatro elementos, en este orden:\n"
        "1. «ficha_sintaxis» titulado «Tarjeta de sintaxis»: tabla «Quiero… | Escribo» con cada operación que las tareas "
        "necesitan (el código entre comillas invertidas) y, debajo, una regla de decisión en una línea (p. ej., «**¿Qué "
        "tipo elijo?** ¿Puede tener decimales? → `double` …»).\n"
        "2. «guia_preguntas» titulado «Guía de preguntas de rutina»: de 5 a 8 líneas «☐ ¿…?» que el estudiante revisa "
        "antes de entregar.\n"
        "3. «errores_frecuentes» titulado «Errores frecuentes»: de 3 a 5 errores; cada uno «**a) <error>.** ¿Por qué …?», "
        "el programa con el error en un bloque de código, debajo un bloque ```compilador vacío (el sistema escribe el "
        "mensaje real de g++) o un bloque ```salida si compila pero falla al ejecutarse, y «*Corrección:* …».\n"
        "4. «ejemplo_isomorfico» titulado «Ejemplo isomórfico · Mismo patrón, otro contexto»: una frase con el patrón, "
        "un programa completo en otro contexto (distinto de las tareas), con notas `//→ nota`, y su bloque ```salida con "
        "«entrada: …» y «---».",
        convertir_procedimental,
        probar_codigo=True,
    ),
    "guion_protocolo": Plantilla(
        "guion_protocolo",
        7,
        "Protocolo verbal",
        m.PropuestaProcedimental,
        "Escribe el protocolo verbal del tema de la clase indicada en <alcance> (o de la tarea, si <alcance> indica "
        "una): un solo elemento (tipo «protocolo_verbal») titulado «Protocolo verbal · <problema>», en Markdown: "
        "«> 🎙 **Guion para narrar en N segmentos breves (≈ 3 min en total)**, mostrando el código en pantalla; el "
        "estudiante controla el ritmo.», «**Problema:** …» (un problema nuevo del tema), de 3 a 5 segmentos «**Segmento "
        "n — <idea>.** **CÓMO:** … **POR QUÉ:** …» (las decisiones del experto en voz alta, incluido un error típico que "
        "evita) y, al final y obligatorio, «**Programa del experto:**» seguido del programa completo que resuelve el "
        "problema en un bloque de código del lenguaje del curso (con #include, main y notas `//→ nota`) y su bloque "
        "```salida con «entrada: …» y «---». Sin ese programa el protocolo no sirve: el estudiante ve el código mientras "
        "escucha cada segmento.",
        procedimental_de(TipoProcedimental.protocolo_verbal),
        probar_codigo=True,
        max_tokens=8000,
    ),
    "plan_implementacion": Plantilla(
        "plan_implementacion",
        8,
        "Plan de implementación",
        m.PropuestaPlan,
        "Propón las fechas de apertura y cierre de cada clase de tareas para cada grupo, dentro de las fechas del "
        "curso en <alcance>, respetando el orden de las clases y el tiempo estimado de sus tareas.",
        solo_notas("plan"),
        nivel_modelo="ligero",
        max_tokens=4000,
    ),
    "items_evaluacion": Plantilla(
        "items_evaluacion",
        9,
        "Evaluación del tema",
        m.PropuestaItems,
        "Genera la evaluación de los objetivos de <alcance> (del tema de su clase, si la indica), forma «A». Recall test "
        "(nivel «recall»): 6 reactivos de opción múltiple (4 opciones, una correcta; también verdadero o falso con 2 "
        "opciones) o de respuesta corta, sobre los conceptos y la sintaxis del tema. Comprehension test (nivel "
        "«comprension»): 1 de predecir la salida (tipo «prediccion_salida»: el programa completo, que no lee datos, va en "
        "«codigo_inicial» y el sistema calcula la salida), 1 de corregir los errores de un fragmento (respuesta corta: en "
        "«aceptadas», la corrección) y 1 de escribir un programa nuevo (tipo «programacion»: solución completa que lee de "
        "la entrada y 3 casos de prueba, el último oculto). La forma B se pide aparte, si hace falta.",
        solo_notas("items"),
        probar_codigo=True,
    ),
    "informe_revision": Plantilla(
        "informe_revision",
        10,
        "Informe de revisión",
        m.PropuestaInforme,
        "Con los <resultados> del curso (logro por objetivo, pre/post con sus estadísticos, carga cognitiva por "
        "clase, IMMS por dimensión ARCS, uso de ayudas y alertas) redacta el informe del paso 10: qué objetivos se "
        "cumplieron, qué funcionó y qué no, y si conviene cerrar el ciclo o iterar (regresar a la Fase 1 si fallan "
        "los objetivos o la evaluación; a la Fase 2 si falla el material). Cita las cifras que usas; no inventes "
        "datos, no interpretes un p > 0.05 como efecto y, si hay menos de 10 estudiantes, dilo en limitaciones.",
        solo_notas("informe"), max_tokens=6000,
    ),
}


def material(sol: SolicitudGeneracion) -> str:
    """Fragmentos numerados [M1], [M2]… con su documento y página, para poder citarlos."""
    return "\n\n".join(
        f"[M{i}] {f.documento}{f', p. {f.pagina}' if f.pagina else ''}\n{f.texto}" for i, f in enumerate(sol.material, start=1)
    )


def resumen_diseno(sol: SolicitudGeneracion) -> str:
    """Lo que el modelo necesita del diseño, acotado: con el modelo local, el prompt y la respuesta comparten un
    contexto de 24k tokens. Las trazas van sin sus pasos calculados y, si el curso es grande, los enunciados se
    recortan (el trabajo 18 de «Programación I» mandó 20k tokens de entrada y la respuesta se cortó en los tres
    intentos).

    El enunciado completo solo viaja para las tareas del alcance (la tarea de una ayuda, las de la clase de un soporte):
    con los enunciados de todas, al pedir una clase nueva `qwen3:4b` copiaba la existente con su mismo título y sus
    mismas tareas, aunque los objetivos y las indicaciones pedían otro tema (trabajos 23 y 24)."""
    d = sol.diseno
    tarea_uid, clase_uid = sol.alcance.get("tarea_uid"), sol.alcance.get("clase_uid")

    def armar(limite: int | None) -> str:
        def enunciado(md: str) -> str:
            md = sin_pasos(md)
            return md if limite is None or len(md) <= limite else md[:limite].rstrip() + " …"

        def tarea(t: Any) -> dict[str, Any]:
            resumen = {"uid": t.uid, "clase_uid": t.clase_uid, "orden": t.orden, "titulo": t.titulo, "nivel_apoyo": t.nivel_apoyo}
            if t.uid == tarea_uid or (clase_uid and t.clase_uid == clase_uid):
                resumen["enunciado_md"] = enunciado(t.enunciado_md)
            return resumen

        return json.dumps({
            "objetivos": [o.model_dump(mode="json") for o in d.objetivos],
            "clases": [
                {"uid": c.uid, "orden": c.orden, "titulo": c.titulo, "objetivos": c.objetivos, "interactividad": c.diseno.interactividad}
                for c in d.clases
            ],
            "tareas": [tarea(t) for t in d.tareas],
            "soporte": [{"uid": s.uid, "clase_uid": s.clase_uid, "titulo": s.titulo} for s in d.soporte],
        }, ensure_ascii=False)

    completo = armar(None)
    if len(completo) <= MAX_DISENO:
        return completo
    for limite in (1500, 600, 200):
        if len(recortado := armar(limite)) <= MAX_DISENO:
            return recortado
    return armar(0)


# ~6k tokens: con el prompt de sistema (~4k) y el material (~1.5k) quedan más de 10k para la respuesta
MAX_DISENO = 20_000


def alcance_con_objetivos(sol: SolicitudGeneracion) -> dict[str, Any]:
    """El alcance con la descripción de sus objetivos junto al código: con solo «OB-1», el modelo pequeño tomaba el
    tema de la clase ya existente (punteros) en lugar del de los objetivos (funciones), trabajo 18."""
    citados = sol.alcance.get("objetivos")
    if not isinstance(citados, list) or not citados:
        return sol.alcance
    por_clave = {clave: o for o in sol.diseno.objetivos for clave in (o.uid, o.codigo)}
    return {**sol.alcance, "objetivos": [
        {"codigo": o.codigo, "descripcion": o.descripcion.strip()} if (o := por_clave.get(c)) else c for c in citados
    ]}


def mensaje_usuario(p: Plantilla, sol: SolicitudGeneracion) -> str:
    """Contexto entre etiquetas + la tarea. Las etiquetas separan datos de instrucciones."""
    partes = [
        f"<curso>\n{sol.curso.model_dump_json()}\n</curso>",
        f"<diseno_actual>\n{resumen_diseno(sol)}\n</diseno_actual>",
        *([f"<resultados>\n{json.dumps(sol.resultados, ensure_ascii=False)}\n</resultados>"] if sol.resultados else []),
        *([f"<material_curso>\n{material(sol)}\n</material_curso>"] if sol.material else []),
        f"<grupos>\n{json.dumps([g.model_dump() for g in sol.grupos], ensure_ascii=False)}\n</grupos>",
        f"<alcance>\n{json.dumps(alcance_con_objetivos(sol), ensure_ascii=False)}\n</alcance>",
        f"<indicaciones_instructor>\n{sol.indicaciones}\n</indicaciones_instructor>",
        f"Tarea (paso {p.paso} de CLT4BP, {p.titulo}): {p.instrucciones}",
        *([tema_clase(sol)] if p.clave == "clase_tareas" else []),
        *([tareas_previas(sol)] if p.clave == "tareas_complementarias" else []),
    ]
    return "\n\n".join(partes)


def _codigos_cubiertos(sol: SolicitudGeneracion) -> set[str]:
    por_clave = {clave: o.codigo for o in sol.diseno.objetivos for clave in (o.uid, o.codigo)}
    return {por_clave.get(x, x) for c in sol.diseno.clases for x in c.objetivos}


def _partir(texto: str, separador: str) -> list[str]:
    """Parte el texto en el separador, salvo dentro de paréntesis («(int, double, char)» no se parte)."""
    partes, actual, nivel = [], "", 0
    i = 0
    while i < len(texto):
        c = texto[i]
        nivel += (c == "(") - (c == ")")
        if nivel == 0 and texto.startswith(separador, i):
            partes.append(actual)
            actual, i = "", i + len(separador)
            continue
        actual += c
        i += 1
    return [p.strip(" .") for p in [*partes, actual] if p.strip(" .")]


def subtemas(descripcion: str) -> list[str]:
    """Las partes de un objetivo: «Elegir el tipo…; declarar…; y leer…» → tres subtemas. Primero por «;», luego por
    oraciones y, si es una lista («Estructuras: declaración, acceso y anidación»), por sus elementos."""
    texto = descripcion.strip()
    if ":" in texto and len(texto.split(":", 1)[0]) < 60:
        texto = texto.split(":", 1)[1]
    partes = [texto]
    for separador in (";", ". ", ", "):
        partes = _partir(texto, separador)
        if len(partes) > 1:
            break
    if separador == ", " and len(partes) > 1:
        partes[-1:] = _partir(partes[-1], " y ")  # «…, Arreglos de estructuras y Anidación de estructuras»
    limpias = [re.sub(r"^(y|e)\s+", "", p.strip(), flags=re.I) for p in partes]
    return [p[0].upper() + p[1:] for p in limpias if p]


def reparto_de_subtemas(descripciones: list[str]) -> str:
    """Un modelo pequeño repite el mismo problema en todas las tareas («promedio» en T2, T3 y T4, corrida del
    2026-10-06): se le da a cada tarea una parte distinta del tema. T4 (convencional) integra las partes."""
    partes = [s for d in descripciones for s in subtemas(d)]
    if len(partes) < 2:
        return ""
    # Con menos de cuatro partes, las tareas que sobran combinan las anteriores en un problema más completo. Cada línea
    # repite el papel de la tarea: con solo los subtemas, el modelo los copió como títulos y las cuatro salieron como
    # ejemplo resuelto (cuarta corrida del 2026-10-06)
    papeles = ["ejemplo resuelto y su gemelo", "por completar con pistas", "por completar sin pistas", "convencional"]
    focos = [f"sobre «{partes[i]}»" if i < len(partes) else "combina las partes anteriores en un problema más completo" for i in range(4)]
    lineas = "; ".join(f"T{n} ({papel}) {foco}" for n, (papel, foco) in enumerate(zip(papeles, focos), start=1))
    return (f"Reparto del tema entre las tareas: {lineas}. Cada tarea conserva su nivel de apoyo, inventa su propio problema "
            "con sus propios datos y su título nombra ese problema (p. ej., «Ficha de un paciente»), no el subtema.")


def tareas_previas(sol: SolicitudGeneracion) -> str:
    """T5 a T8, al final del mensaje: los problemas de T1 a T4 que no se repiten (el reto colaborativo copiaba el
    escenario de T4, «Registrar información de un estudiante», 2026-10-06)."""
    titulos = [f"«{t.titulo}»" for t in sol.diseno.tareas if t.clase_uid == sol.alcance.get("clase_uid")]
    if not titulos:
        return ""
    return (f"La clase ya tiene {', '.join(titulos)}: las tareas nuevas son otros problemas, con otros escenarios y otros "
            "datos (ni sus personajes ni sus cálculos).")


def tema_clase(sol: SolicitudGeneracion) -> str:
    """El tema de una clase nueva, al final del mensaje (un modelo pequeño atiende sobre todo a lo último que lee):
    los objetivos del alcance que ninguna clase cubre todavía, las indicaciones del instructor y qué no repetir."""
    objetivos = [o for o in alcance_con_objetivos(sol).get("objetivos") or [] if isinstance(o, dict)]
    nuevos = [o for o in objetivos if o["codigo"] not in _codigos_cubiertos(sol)] or objetivos
    partes = []
    if nuevos:
        partes.append("Tema de esta clase: " + "; ".join(f"{o['codigo']}, «{o['descripcion']}»" for o in nuevos) + ".")
        if reparto := reparto_de_subtemas([o["descripcion"] for o in nuevos]):
            partes.append(reparto)
    if sol.indicaciones.strip():
        partes.append(f"Indicaciones del instructor para esta clase: «{sol.indicaciones.strip()}».")
    if sol.diseno.clases:
        existentes = "; ".join(f"«{c.titulo}»" for c in sol.diseno.clases)
        partes.append(f"Ya existen las clases {existentes}: no repitas ni adaptes sus títulos ni sus tareas; propón "
                      "problemas nuevos sobre el tema de esta clase.")
    return " ".join(partes)
