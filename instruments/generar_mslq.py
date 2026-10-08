"""Genera instruments/mslq.json. Uso: uv run instruments/generar_mslq.py

Versión del MSLQ usada en la tesis (Anexo A.1): 31 ítems de motivación y 42 de estrategias
de aprendizaje, en escala Likert de 1 a 7. La numeración del anexo reinicia en la parte B;
aquí los ítems se numeran de corrido: m1-m31 (motivación) y m32-m73 (estrategias).
El anexo no marca ítems inversos, por lo que ninguno se recodifica.
"""
import json
from pathlib import Path

# (clave de subescala, nombre, [textos de sus ítems en el orden del anexo])
MOTIVACION = [
    ("intrinseca", "Orientación intrínseca hacia la meta", [
        "En una clase como esta, prefiero material de curso que realmente me desafíe para poder aprender cosas nuevas.",
        "En una clase como ésta, prefiero material que despierte mi curiosidad, aunque sea difícil de aprender.",
        "Lo más satisfactorio para mí de este curso es intentar comprender el contenido lo más a fondo posible.",
        "Cuando tengo la oportunidad en esta clase, elijo tareas del curso que puedo aprender incluso si no garantizan una buena calificación.",
    ]),
    ("extrinseca", "Orientación extrínseca hacia la meta", [
        "Obtener una buena nota en esta clase es lo más satisfactorio para mí en este momento.",
        "Lo más importante para mí en este momento es mejorar mi promedio general de calificaciones, por lo que mi principal preocupación en esta clase es obtener una buena calificación.",
        "Si puedo, quiero obtener mejores notas en esta clase que la mayoría de los otros estudiantes.",
        "Quiero tener un buen desempeño en esta clase porque es importante mostrar mi capacidad a mi familia, amigos, empleador u otros.",
    ]),
    ("valor_tarea", "Valor de la tarea", [
        "Creo que podré utilizar lo aprendido en este curso en otros cursos.",
        "Es importante para mí aprender el material del curso en esta clase.",
        "Estoy muy interesado en el contenido de este curso.",
        "Pienso que el material del curso en esta clase es útil para aprender.",
        "Me gusta el tema de este curso.",
        "Para mí es muy importante comprender el tema de este curso.",
    ]),
    ("autoeficacia", "Autoeficacia", [
        "Si estudio de manera apropiada, entonces podré aprender el material de este curso.",
        "Es mi culpa si no aprendo el material de este curso.",
        "Si me esfuerzo lo suficiente, entenderé el material del curso.",
        "Si no entiendo el material del curso es porque no me esforcé lo suficiente.",
        "Creo que recibiré una calificación excelente en esta clase.",
        "Estoy seguro de que puedo comprender el material más difícil presentado en las lecturas de este curso.",
        "Estoy seguro de que puedo comprender los conceptos básicos enseñados en este curso.",
        "Estoy seguro de que puedo comprender el material más complejo presentado por el instructor en este curso.",
        "Estoy seguro de que puedo hacer un excelente trabajo en las tareas y exámenes de este curso.",
        "Espero tener un buen desempeño en esta clase.",
        "Estoy seguro de que puedo dominar las habilidades que se enseñan en esta clase.",
        "Teniendo en cuenta la dificultad de este curso, el profesor y mis habilidades, creo que me irá bien en esta clase.",
    ]),
    ("ansiedad", "Ansiedad ante los exámenes", [
        "Cuando hago un examen, pienso en lo mal que me está yendo en comparación con otros estudiantes.",
        "Cuando hago un examen, pienso en elementos de otras partes del examen que no puedo responder.",
        "Cuando hago exámenes pienso en las consecuencias de fallar.",
        "Tengo una sensación de inquietud y malestar cuando hago un examen.",
        "Siento que mi corazón late rápido cuando hago un examen.",
    ]),
]

ESTRATEGIAS = [
    ("repaso", "Repetición", [
        "Tomo notas durante las clases para ayudarme a recordar la información importante.",
        "Repito mentalmente los términos clave mientras estudio para recordarlos mejor.",
        "Escribo las ideas principales varias veces para reforzarlas en mi memoria.",
        "Practico ejercicios repetitivos para dominar el contenido.",
    ]),
    ("elaboracion", "Elaboración", [
        "Relaciono el contenido que estoy aprendiendo con cosas que ya conozco.",
        "Explico el material a mí mismo para entenderlo mejor.",
        "Trato de encontrar ejemplos reales de los conceptos que aprendo.",
        "Conecto diferentes temas para crear una visión más completa de lo que estudio.",
        "Hago preguntas sobre el material para profundizar en mi comprensión.",
        "Uso asociaciones para recordar la información más fácilmente.",
    ]),
    ("organizacion", "Organización", [
        "Uso esquemas o diagramas para organizar la información importante.",
        "Subrayo las ideas principales al leer el material de estudio.",
        "Hago resúmenes de lo que aprendo para enfocarme en los puntos clave.",
        "Divido el material en secciones más pequeñas para aprenderlo mejor.",
    ]),
    ("metacognicion", "Control de la comprensión (metacognición)", [
        "Reviso regularmente si estoy entendiendo lo que estoy estudiando.",
        "Hago una pausa para reflexionar sobre lo que acabo de leer o aprender.",
        "Me esfuerzo por encontrar soluciones alternativas si no entiendo algo.",
        "Evalúo la calidad de mi trabajo académico antes de entregarlo.",
        "Identifico las partes más difíciles de un tema para enfocarme en ellas.",
        "Me hago preguntas a mí mismo mientras estudio para comprobar mi conocimiento.",
        "Planifico mis sesiones de estudio antes de comenzar.",
        "Cambio mi estrategia de aprendizaje si noto que algo no funciona.",  # el anexo dice "Cambió" (errata)
        "Verifico si mis notas son completas después de una clase o lectura.",
        "Pienso en cómo el contenido aprendido podría ser útil en el futuro.",
        "Reflexiono sobre cómo mejoré en mi aprendizaje después de una tarea difícil.",
        "Me pregunto cómo lo que aprendí se relaciona con mi vida diaria.",
    ]),
    ("gestion_tiempo", "Gestión del tiempo y del estudio", [
        "Establezco horarios claros para completar mis actividades académicas.",
        "Intento cumplir con las fechas límite sin retrasos.",
        "Distribuyo mi tiempo entre diferentes tareas para mantener el equilibrio.",
        "Dedico tiempo extra a temas que me parecen más complicados.",
    ]),
    ("regulacion_esfuerzo", "Regulación del esfuerzo", [
        "Sigo trabajando en tareas difíciles, aunque me resulten frustrantes.",
        "Mantengo el esfuerzo incluso cuando el material no me interesa.",
        "Busco motivación extra para terminar mis estudios si pierdo interés.",
        "Me obligo a estudiar incluso cuando no tengo ganas.",
    ]),
    ("busqueda_ayuda", "Búsqueda de ayuda", [
        "Pido ayuda a mis profesores cuando no entiendo algo.",
        "Formo grupos de estudio para aprender junto con mis compañeros.",
        "Me siento cómodo solicitando aclaraciones sobre temas complicados.",
        "Recurro a fuentes externas, como libros o internet, si necesito más información.",
    ]),
    ("entorno_aprendizaje", "Entorno de aprendizaje", [
        "Me aseguro de tener un lugar tranquilo y ordenado para estudiar.",
        "Elijo ambientes sin distracciones para concentrarme mejor.",
        "Organizo mis materiales de estudio antes de comenzar.",
        "Utilizo recursos como bibliotecas o laboratorios para mejorar mi aprendizaje.",
    ]),
]

INVERSOS: set[int] = set()  # el anexo no marca ítems inversos


def numerar(bloques, inicio):
    """Devuelve (ítems, subescalas) numerando de corrido desde `inicio`."""
    items, subescalas, n = [], [], inicio
    for clave, nombre, textos in bloques:
        ids = []
        for texto in textos:
            items.append({"id": f"m{n}", "texto": texto, "inverso": n in INVERSOS})
            ids.append(f"m{n}")
            n += 1
        subescalas.append({"clave": clave, "nombre": nombre, "items": ids})
    return items, subescalas, n


items_a, subs_a, siguiente = numerar(MOTIVACION, 1)
items_b, subs_b, fin = numerar(ESTRATEGIAS, siguiente)
items, subescalas = items_a + items_b, subs_a + subs_b

# Cada ítem debe pertenecer a exactamente una subescala
todos = [i for s in subescalas for i in s["items"]]
assert sorted(todos, key=lambda x: int(x[1:])) == [f"m{i}" for i in range(1, fin)]
assert len(items) == 73 and len(subescalas) == 13

instrumento = {
    "clave": "mslq",
    "version": "es-2026",
    "nombre": "Cuestionario de Motivación y Estrategias de Aprendizaje (MSLQ)",
    "fuente": "Pintrich, Smith, García y McKeachie (1991); versión en español del Anexo A.1 de la tesis.",
    "escala": {"min": 1, "max": 7, "etiquetas": {"1": "Totalmente en desacuerdo", "7": "Totalmente de acuerdo"}},
    "partes": [
        {"titulo": "Parte A. Motivación", "items": [i["id"] for i in items_a]},
        {"titulo": "Parte B. Estrategias de aprendizaje", "items": [i["id"] for i in items_b]},
    ],
    "items": items,
    "subescalas": subescalas,
    "indices": {
        "motivacion": ["autoeficacia", "valor_tarea", "intrinseca"],
        "estrategias_cognitivas": ["repaso", "elaboracion", "organizacion"],
        "autorregulacion": ["metacognicion", "gestion_tiempo", "regulacion_esfuerzo"],
    },
}

salida = Path(__file__).with_name("mslq.json")
salida.write_text(json.dumps(instrumento, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(f"✔ {salida}: {len(items)} ítems, {len(subescalas)} subescalas, {len(INVERSOS)} inversos")
