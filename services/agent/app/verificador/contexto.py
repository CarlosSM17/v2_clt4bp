"""Vista cómoda del diseño para las reglas: índices por uid y contenido efectivo por grupo."""

import re
from dataclasses import dataclass, field
from typing import Any

from .modelos import ResultadoCodigo, SolicitudVerificacion

CAMPOS_FIJOS = {"uid", "clase_uid", "tarea_uid", "orden", "solucion", "casos_prueba"}
AUTONOMIA = {"ejemplo_resuelto": 0, "por_completar": 1, "convencional": 2, "solucion_libre": 3}
INTERACTIVIDAD = {"baja": 0, "media": 1, "alta": 2}


def aplicar_variante(base: dict[str, Any], cambios: dict[str, Any] | None) -> dict[str, Any]:
    """Misma regla que la consola (aplicarVariante) y la publicación en PHP."""
    if not cambios:
        return base
    resultado = dict(base)
    for campo, valor in cambios.items():
        if campo in CAMPOS_FIJOS:
            continue
        actual = resultado.get(campo)
        if campo == "diseno" and isinstance(actual, dict) and isinstance(valor, dict):
            resultado[campo] = {**actual, **valor}
        else:
            resultado[campo] = valor
    return resultado


def palabras(texto: str) -> set[str]:
    sin_codigo = re.sub(r"```.*?```", " ", texto or "", flags=re.S)
    return {p for p in re.findall(r"[a-záéíóúñü0-9]+", sin_codigo.lower()) if len(p) > 2}


def similitud(a: str, b: str) -> float:
    """Jaccard entre conjuntos de palabras: 1 = mismo vocabulario."""
    pa, pb = palabras(a), palabras(b)
    return len(pa & pb) / len(pa | pb) if pa and pb else 0.0


@dataclass
class Contexto:
    diseno: dict[str, Any]
    codigo: dict[str, ResultadoCodigo]
    grupos: list[dict[str, Any]] = field(init=False)
    clases: list[dict[str, Any]] = field(init=False)
    tareas: list[dict[str, Any]] = field(init=False)
    variantes: dict[tuple[str, str], dict[str, Any]] = field(init=False)
    uids: set[str] = field(init=False)

    @classmethod
    def desde(cls, solicitud: SolicitudVerificacion) -> "Contexto":
        return cls(diseno=solicitud.diseno.model_dump(mode="json"), codigo=solicitud.codigo)

    def __post_init__(self) -> None:
        d = self.diseno
        self.grupos = d["curso"]["grupos"]
        self.clases = sorted(d["clases"], key=lambda c: c["orden"])
        self.tareas = d["tareas"]
        self.variantes = {(v["elemento_uid"], v["grupo_clave"]): v for v in d["variantes"]}
        self.uids = {
            e["uid"]
            for tipo in ("objetivos", "clases", "tareas", "soporte", "procedimental", "practica_parcial")
            for e in d[tipo]
        }

    def tareas_de(self, clase_uid: str, grupo: str | None = None) -> list[dict[str, Any]]:
        """Tareas de una clase, en orden, tal como las verá un grupo (None = versión base). Un grupo ve solo las tareas
        de su ruta (`rutas` vacío = todos), como en el aula (AplicadorVariantes::paraGrupo)."""
        propias = sorted((t for t in self.tareas if t["clase_uid"] == clase_uid and (grupo is None or not t.get("rutas") or grupo in t["rutas"])),
                         key=lambda t: t["orden"])
        return [self.efectivo(t, grupo) for t in propias]

    def efectivo(self, elemento: dict[str, Any], grupo: str | None) -> dict[str, Any]:
        v = self.variantes.get((elemento["uid"], grupo)) if grupo else None
        return aplicar_variante(elemento, v["cambios"]) if v else elemento

    def vistas(self) -> list[str | None]:
        """Versiones a revisar: la base y la de cada grupo."""
        return [None, *[g["clave"] for g in self.grupos]]
