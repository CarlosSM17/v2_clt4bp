"""Conocimiento CLT4BP: prompt de sistema versionado y catálogo de efectos (copiado por npm run gen)."""

import json
from functools import lru_cache
from pathlib import Path
from typing import Any

DIR = Path(__file__).parent
# Cambia la versión cada vez que modifiques prompt_sistema.md: queda registrada en cada ejecución
VERSION = "sistema-v12"  # v2: <material_curso> (RAG); v3: «solucion» y «codigo_inicial» por nivel; v4: sin archivos previos; v5: patrón para archivos; v6: diagramas y trazas; v7: pasos de trazas calculados; v8: clase por etapas, salidas calculadas, soporte estructurado; v9: diseño sin pasos de trazas, tema desde los objetivos; v10: se siguen las indicaciones, tema al final, sin copiar clases; v11: soporte breve y gráfico, ficha de sintaxis con ejemplo propio, tareas distintas; v12: tema completo con la forma del mapa de ruta (ADR 0007)


@lru_cache
def efectos() -> list[dict[str, str]]:
    return json.loads((DIR / "efectos.json").read_text(encoding="utf-8"))


@lru_cache
def bloques_sistema() -> tuple[dict[str, Any], ...]:
    """Prompt de sistema en dos bloques. El último lleva cache_control: rol + catálogo se cobran
    completos solo la primera vez; en las siguientes llamadas se leen de la caché."""
    catalogo = "\n".join(
        f"- {e['id']} ({e['nombre']}; {e['grupo']}): {e['definicion']} Cómo se materializa: {e['materializacion']} "
        f"Úsalo: {e['cuando_usar']} No lo uses: {e['cuando_no']}"
        for e in efectos()
    )
    return (
        {"type": "text", "text": (DIR / "prompt_sistema.md").read_text(encoding="utf-8")},
        {"type": "text", "text": f"<catalogo_efectos>\n{catalogo}\n</catalogo_efectos>", "cache_control": {"type": "ephemeral"}},
    )
