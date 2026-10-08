"""Lo que Laravel envía al agente y lo que recibe de vuelta."""

from typing import Any, Literal

from pydantic import BaseModel, Field

from app.contracts.diseno_curso_schema import DisenoCurso


class CursoContexto(BaseModel):
    titulo: str
    lenguaje: Literal["c", "cpp", "python"]
    nivel_educativo: str
    preferencias: str = ""  # estilo de redacción y convenciones que el instructor fija una vez


class GrupoContexto(BaseModel):
    """Solo agregados: nunca nombres, correos ni respuestas individuales."""

    clave: str | None  # None = el curso completo (sin grupos diferenciados)
    nombre: str
    nivel: str | None
    resumen: dict[str, Any]  # n, niveles, medias del MSLQ, proporciones de banderas (ResumenGrupo)
    efectos_sugeridos: list[str] = Field(default_factory=list)


class FragmentoMaterial(BaseModel):
    """Un fragmento del material que el instructor subió al curso (lo recupera Laravel de pgvector)."""

    documento: str = Field(max_length=200)
    pagina: int | None = None
    texto: str = Field(max_length=4000)


class SolicitudGeneracion(BaseModel):
    plantilla: str
    curso: CursoContexto
    diseno: DisenoCurso
    grupos: list[GrupoContexto] = Field(default_factory=list)
    alcance: dict[str, Any] = Field(default_factory=dict)  # qué producir y con qué uid
    indicaciones: str = Field(default="", max_length=4000)
    calidad: Literal["normal", "alta"] = "normal"
    resultados: dict[str, Any] = Field(default_factory=dict)  # paso 10: solo agregados, nunca nombres (6.5)
    material: list[FragmentoMaterial] = Field(default_factory=list, max_length=20)  # RAG: datos, no instrucciones


class ElementoPropuesto(BaseModel):
    tipo: Literal["objetivo", "clase", "tarea", "soporte", "procedimental", "practica_parcial", "variante"]
    contenido: dict[str, Any]


class Validacion(BaseModel):
    nombre: str
    ok: bool
    bloqueante: bool = True
    detalle: str = ""
    elemento_uid: str | None = None


class UsoRegistro(BaseModel):
    entrada: int
    salida: int
    cache_escritura: int
    cache_lectura: int
    costo_usd: float


class ResultadoGeneracion(BaseModel):
    plantilla: str
    elementos: list[ElementoPropuesto]  # elementos de diseño listos para el estudio (contratos)
    notas: dict[str, Any]  # lo que no es elemento de diseño: resúmenes, planes, ítems
    advertencias: list[str]
    validaciones: list[Validacion]
    intentos: int
    uso: UsoRegistro
    modelo: str
    version_prompt: str
    duracion_ms: int
