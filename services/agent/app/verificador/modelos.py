from typing import Literal

from pydantic import BaseModel, Field

from app.contracts.diseno_curso_schema import DisenoCurso

Nivel = Literal["error", "advertencia", "info"]


class ResultadoCodigo(BaseModel):
    """Resultado de ejecutar una solución de referencia contra sus casos (lo calcula Laravel con Piston)."""

    aprobados: int
    total: int
    error_compilacion: str | None = None
    # El primer caso que falla (entrada, esperada, obtenida). Solo lo llena el agente: el modelo lo necesita para
    # corregirse; Laravel no lo manda y el mensaje queda como antes.
    detalle: str | None = None


class SolicitudVerificacion(BaseModel):
    diseno: DisenoCurso
    # uid de tarea (o "pp-uid#n" para el ejercicio n de una práctica parcial) → resultado
    codigo: dict[str, ResultadoCodigo] = Field(default_factory=dict)


class Hallazgo(BaseModel):
    regla: str
    nivel: Nivel
    elemento_uid: str
    mensaje: str
    grupo: str | None = None  # clave del grupo cuando el problema solo aparece en su variante


class Informe(BaseModel):
    hallazgos: list[Hallazgo]
    errores: int
    advertencias: int
    semaforo: dict[str, Literal["rojo", "amarillo", "verde"]]
