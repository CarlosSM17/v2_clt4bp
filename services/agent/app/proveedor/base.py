"""Interfaz ProveedorLLM: el orquestador no sabe qué modelo ni qué empresa hay detrás."""

from dataclasses import dataclass, field
from typing import Any, Protocol, TypeVar

from pydantic import BaseModel, ValidationError

T = TypeVar("T", bound=BaseModel)

# USD por millón de tokens (entrada, escritura en caché, lectura de caché, salida).
# Precios públicos de septiembre de 2026: revísalos en https://platform.claude.com/docs/en/about-claude/pricing
PRECIOS: dict[str, tuple[float, float, float, float]] = {
    "claude-sonnet-5": (2.0, 2.5, 0.2, 10.0),
    "claude-haiku-4-5": (1.0, 1.25, 0.1, 5.0),
    "claude-opus-5-5": (4.0, 5.0, 0.2, 20.0),
}


@dataclass
class Uso:
    entrada: int = 0
    salida: int = 0
    cache_escritura: int = 0
    cache_lectura: int = 0
    costo_usd: float = 0.0

    def sumar(self, otro: "Uso") -> None:
        self.entrada += otro.entrada
        self.salida += otro.salida
        self.cache_escritura += otro.cache_escritura
        self.cache_lectura += otro.cache_lectura
        self.costo_usd = round(self.costo_usd + otro.costo_usd, 6)


def costo(modelo: str, uso: Uso) -> float:
    p = PRECIOS.get(modelo)
    if not p:
        return 0.0
    return round((uso.entrada * p[0] + uso.cache_escritura * p[1] + uso.cache_lectura * p[2] + uso.salida * p[3]) / 1_000_000, 6)


@dataclass
class Respuesta:
    objeto: Any  # instancia del modelo Pydantic pedido
    texto: str  # el JSON tal como llegó (para la conversación de corrección)
    uso: Uso = field(default_factory=Uso)
    modelo: str = ""


class SalidaInvalida(Exception):
    """La respuesta no cumple el esquema. Lleva el texto y el uso para corregir en el siguiente intento.
    cortada: se acabó el espacio de salida; ese texto truncado no sirve para corregir (y ocuparía el contexto)."""

    def __init__(self, detalle: str, texto: str, uso: Uso, cortada: bool = False) -> None:
        super().__init__(detalle)
        self.detalle, self.texto, self.uso, self.cortada = detalle, texto, uso, cortada


class ProveedorNoDisponible(Exception):
    """El modelo no responde o no está instalado: reintentar más tarde (o instalarlo) lo arregla."""


def resumir_errores(e: ValidationError, maximo: int = 8) -> str:
    return "; ".join(f"{'.'.join(map(str, x['loc'])) or 'raíz'}: {x['msg']}" for x in e.errors()[:maximo])


class ProveedorLLM(Protocol):
    async def generar(
        self, sistema: list[dict[str, Any]], mensajes: list[dict[str, Any]], salida: type[T], modelo: str, max_tokens: int
    ) -> Respuesta: ...
