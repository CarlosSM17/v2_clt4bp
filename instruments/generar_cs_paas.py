"""Genera instruments/cs.json y instruments/paas.json. Uso: uv run instruments/generar_cs_paas.py

- cs: escala de carga cognitiva de Leppink et al. (10 ítems, 0-10): carga intrínseca c1-c3,
  extrínseca c4-c6 y germana c7-c10.
- paas: escala de esfuerzo mental de Paas (1 ítem, 1-9).
"""
import json
from pathlib import Path

aqui = Path(__file__).parent


def escribir(nombre: str, instrumento: dict) -> None:
    ruta = aqui / f"{nombre}.json"
    ruta.write_text(json.dumps(instrumento, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"✔ {ruta}: {len(instrumento['items'])} ítems")


# Orden: c1-c3 intrínseca, c4-c6 extrínseca, c7-c10 germana (Leppink et al., 2013).
CS_TEXTOS = [
    "Los temas tratados en la actividad fueron muy complejos.",
    "La actividad abordó código de programa que percibí como muy complejo.",
    "La actividad abordó conceptos y definiciones que percibí como muy complejos.",
    "Las instrucciones o explicaciones durante la actividad fueron muy confusas.",
    "Las instrucciones o explicaciones fueron, en términos de aprendizaje, muy ineficaces.",
    "Las instrucciones o explicaciones estaban llenas de lenguaje confuso.",
    "La actividad mejoró significativamente mi comprensión del/de los tema(s) tratado(s).",
    "La actividad mejoró significativamente mis conocimientos y comprensión de programación.",
    "La actividad mejoró significativamente mi comprensión del código de programa tratado.",
    "La actividad mejoró significativamente mi comprensión de los conceptos y definiciones.",
]
assert len(CS_TEXTOS) == 10

cs_ids = [f"c{i}" for i in range(1, 11)]
escribir("cs", {
    "clave": "cs",
    "version": "es-2026",
    "nombre": "Escala de carga cognitiva (Leppink et al.)",
    "fuente": "Leppink, Paas, Van der Vleuten, Van Gog y Van Merriënboer (2013).",
    "escala": {"min": 0, "max": 10, "etiquetas": {"0": "Nada en absoluto", "10": "Por completo"}},
    "partes": [{"titulo": "Carga cognitiva", "items": cs_ids}],
    "items": [{"id": i, "texto": t, "inverso": False} for i, t in zip(cs_ids, CS_TEXTOS)],
    "subescalas": [
        {"clave": "intrinseca", "nombre": "Carga intrínseca", "items": ["c1", "c2", "c3"]},
        {"clave": "extrinseca", "nombre": "Carga extrínseca", "items": ["c4", "c5", "c6"]},
        {"clave": "germana", "nombre": "Carga germana", "items": ["c7", "c8", "c9", "c10"]},
    ],
    "indices": {},
})

escribir("paas", {
    "clave": "paas",
    "version": "es-2026",
    "nombre": "Escala de esfuerzo mental (Paas)",
    "fuente": "Paas (1992). Escala de un ítem, de 1 a 9.",
    "escala": {"min": 1, "max": 9, "etiquetas": {"1": "Muy, muy bajo", "9": "Muy, muy alto"}},
    "partes": [{"titulo": "Esfuerzo mental", "items": ["p1"]}],
    "items": [{"id": "p1", "texto": "Indica el esfuerzo mental que invertiste al realizar esta tarea.", "inverso": False}],
    "subescalas": [{"clave": "esfuerzo", "nombre": "Esfuerzo mental", "items": ["p1"]}],
    "indices": {},
})
