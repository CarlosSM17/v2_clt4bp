"""Salidas estructuradas de cada plantilla. Reutilizan los contratos cuando el artefacto es un elemento de diseño.

Límites de las salidas estructuradas de Claude: sin recursión y pocos campos opcionales o uniones
(24 opcionales y 16 uniones por solicitud). Por eso casi todo es obligatorio y las listas pueden ir vacías.
"""

from typing import Literal

from pydantic import BaseModel, Field

from app.contracts.clase_tareas_schema import ClaseTareas
from app.contracts.comunes_schema import Arcs, CasoPrueba, Componente, EfectoId, NivelApoyo
from app.contracts.info_procedimental_schema import InfoProcedimental
from app.contracts.info_soporte_schema import InfoSoporte
from app.contracts.objetivo_schema import Objetivo
from app.contracts.tarea_schema import Tarea


class ConAdvertencias(BaseModel):
    advertencias: list[str] = Field(description="Lo que no pudiste cumplir o lo que el instructor debe revisar.")


# Paso 1
class PropuestaObjetivos(ConAdvertencias):
    objetivos: list[Objetivo]
    jerarquia: str = Field(description="Jerarquía de habilidades en texto: qué habilidad se apoya en cuál.")


# Paso 2
class ResumenDeGrupo(BaseModel):
    grupo_clave: str = Field(description="Clave del grupo o 'curso' si no hay grupos.")
    fortalezas: list[str]
    riesgos: list[str]
    implicaciones: list[str] = Field(description="Qué significa para el diseño (efectos, apoyo, ARCS).")


class PropuestaResumen(ConAdvertencias):
    grupos: list[ResumenDeGrupo]


# Paso 3
class EfectoElegido(BaseModel):
    efecto: EfectoId
    componente: Componente
    justificacion: str


class PropuestaPreseleccion(ConAdvertencias):
    grupo_clave: str
    efectos: list[EfectoElegido]


# Paso 4
class CambiosTarea(BaseModel):
    """Lo que puede variar de una tarea para un grupo. Cadena vacía = se hereda de la base."""

    enunciado_md: str
    nivel_apoyo: NivelApoyo | Literal["heredar"]
    codigo_inicial: str
    arcs_confianza: str = Field(description="Estrategia ARCS de confianza para este grupo, o vacío.")


class VarianteTarea(BaseModel):
    tarea_uid: str
    grupo_clave: str
    cambios: CambiosTarea
    dimensiones: list[Literal["contenido", "proceso", "producto"]]
    razon: str


class PlanGrupo(BaseModel):
    grupo_clave: str
    contenido: str
    proceso: str
    producto: str


class PropuestaDiferenciacion(ConAdvertencias):
    planes: list[PlanGrupo]
    variantes: list[VarianteTarea]


# Paso 5
class PropuestaClase(ConAdvertencias):
    clase: ClaseTareas
    tareas: list[Tarea]
    soporte: list[InfoSoporte]
    procedimental: list[InfoProcedimental]


# Paso 5, segunda tanda: T5–T8 de una clase que ya tiene sus T1–T4 (mapa de ruta, ADR 0007)
class PropuestaTareas(ConAdvertencias):
    tareas: list[Tarea]


# Pasos 6 y 7
class PropuestaSoporte(ConAdvertencias):
    soporte: list[InfoSoporte]


class PropuestaProcedimental(ConAdvertencias):
    procedimental: list[InfoProcedimental]


# Paso 8
class Sesion(BaseModel):
    clase_uid: str
    grupo_clave: str
    abre: str = Field(description="Fecha AAAA-MM-DD")
    cierra: str = Field(description="Fecha AAAA-MM-DD")


class PropuestaPlan(ConAdvertencias):
    sesiones: list[Sesion]


# Paso 9: ítems con el mismo formato del banco de la Etapa 2
class ItemGenerado(BaseModel):
    tipo: Literal["opcion_multiple", "respuesta_corta", "prediccion_salida", "parsons", "programacion"]
    nivel: Literal["recall", "comprension", "practica"]
    objetivo: str
    forma: Literal["A", "B"]
    enunciado_md: str
    opciones: list[str] = Field(description="Solo opción múltiple; si no, lista vacía.")
    correcta: int = Field(description="Índice de la opción correcta (desde 0); -1 si no aplica.")
    aceptadas: list[str] = Field(description="Respuestas aceptadas (respuesta corta); lista vacía si no aplica.")
    salida: str = Field(description="Salida exacta (predicción de salida); vacío si no aplica.")
    lineas: list[str] = Field(description="Líneas en el orden correcto (Parsons); lista vacía si no aplica.")
    codigo_inicial: str
    solucion: str
    casos_prueba: list[CasoPrueba]


class PropuestaItems(ConAdvertencias):
    items: list[ItemGenerado]


# Ficha de diseño instruccional del tema (para el instructor; se guarda en la clase)
class PropuestaFicha(ConAdvertencias):
    ficha_md: str = Field(description="La ficha completa en Markdown, con sus cinco secciones y tablas.")


# El programa del experto de un protocolo verbal, cuando el guion llegó sin él (solicitud aparte y pequeña)
class ProgramaExperto(BaseModel):
    programa: str = Field(description="El programa completo (con #include y main) con notas //→ al final de las líneas clave.")
    entrada: str = Field("", description="Los datos que lee el programa, separados por espacios; vacío si no lee nada.")


# Paso 10: revisión de resultados
class ObjetivoRevisado(BaseModel):
    codigo: str = Field(description="Código del objetivo, p. ej. OB-2.")
    logrado: bool
    evidencia: str = Field(description="La cifra de <resultados> que lo respalda.")


class CambioSugerido(BaseModel):
    paso: int = Field(ge=1, le=9, description="Paso de CLT4BP donde se haría el cambio.")
    elemento_uid: str = Field(description="uid del elemento de <diseno_actual> afectado, o vacío si es general.")
    sugerencia: str


class PropuestaInforme(ConAdvertencias):
    resumen: str = Field(description="De tres a cinco oraciones para el instructor, sin jerga estadística innecesaria.")
    objetivos: list[ObjetivoRevisado]
    hallazgos: list[str] = Field(description="Desempeño, carga cognitiva, motivación (ARCS) y uso de ayudas, con cifras.")
    recomendacion: Literal["cerrar", "iterar"]
    regresar_a: Literal["ninguna", "fase1", "fase2"] = Field(
        description="fase1 = objetivos y evaluación; fase2 = diseño del material; ninguna si se cierra el ciclo.")
    cambios: list[CambioSugerido]
    limitaciones: list[str] = Field(description="Tamaño de muestra, datos faltantes o supuestos no cumplidos.")


__all__ = [
    "Arcs",
    "PropuestaClase",
    "PropuestaDiferenciacion",
    "PropuestaFicha",
    "PropuestaTareas",
    "PropuestaInforme",
    "PropuestaItems",
    "PropuestaObjetivos",
    "PropuestaPlan",
    "PropuestaPreseleccion",
    "PropuestaProcedimental",
    "PropuestaResumen",
    "PropuestaSoporte",
]
