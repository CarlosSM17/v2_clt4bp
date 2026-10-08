"""Genera instruments/imms.json. Uso: uv run instruments/generar_imms.py"""
import json
from pathlib import Path

# Clave de calificación del IMMS (Keller, 2010), la misma que reportan Loorbach et al. (2015)
SUBESCALAS = [
    ("atencion", "Atención", [2, 8, 11, 12, 15, 17, 20, 22, 24, 28, 29, 31]),
    ("relevancia", "Relevancia", [6, 9, 10, 16, 18, 23, 26, 30, 33]),
    ("confianza", "Confianza", [1, 3, 4, 7, 13, 19, 25, 34, 35]),
    ("satisfaccion", "Satisfacción", [5, 14, 21, 27, 32, 36]),
]
INVERSOS = {3, 7, 12, 15, 19, 22, 26, 29, 31, 34}

assert sorted(i for _, _, its in SUBESCALAS for i in its) == list(range(1, 37))

instrumento = {
    "clave": "imms",
    "version": "es-2026",
    "nombre": "Encuesta de motivación con los materiales de instrucción (IMMS)",
    "fuente": "Keller (2010). Anota aquí la adaptación al español que uses y sus condiciones de uso.",
    "escala": {"min": 1, "max": 5, "etiquetas": {"1": "No es cierto", "5": "Muy cierto"}},
    "partes": [
        {"titulo": "Motivación con el material (1 de 2)", "items": [f"i{i}" for i in range(1, 19)]},
        {"titulo": "Motivación con el material (2 de 2)", "items": [f"i{i}" for i in range(19, 37)]},
    ],
    "items": [
        {"id": f"i{i}", "texto": f"[Texto del ítem {i} de la versión validada]", "inverso": i in INVERSOS}
        for i in range(1, 37)
    ],
    # «total» es la media de los 36 ítems (no el promedio de las cuatro subescalas, que tienen distinto número de ítems)
    "subescalas": [{"clave": c, "nombre": n, "items": [f"i{i}" for i in its]} for c, n, its in SUBESCALAS]
    + [{"clave": "total", "nombre": "Motivación total", "items": [f"i{i}" for i in range(1, 37)]}],
    "indices": {},
}

salida = Path(__file__).with_name("imms.json")
salida.write_text(json.dumps(instrumento, ensure_ascii=False, indent=2), encoding="utf-8")
print(f"✔ {salida}: {len(instrumento['items'])} ítems, {len(INVERSOS)} inversos")
