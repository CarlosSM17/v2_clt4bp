"""Proveedor falso: devuelve respuestas preparadas. Sirve para las pruebas y no gasta tokens."""

import json
from typing import Any

from pydantic import ValidationError

from .base import Respuesta, SalidaInvalida, T, Uso, resumir_errores


class ProveedorFalso:
    def __init__(self, respuestas: list[dict[str, Any] | str] | None = None) -> None:
        self.respuestas = list(respuestas or [])
        self.llamadas: list[dict[str, Any]] = []

    async def generar(
        self, sistema: list[dict[str, Any]], mensajes: list[dict[str, Any]], salida: type[T], modelo: str, max_tokens: int
    ) -> Respuesta:
        self.llamadas.append({"sistema": sistema, "mensajes": [dict(m) for m in mensajes], "modelo": modelo})
        if not self.respuestas:
            raise RuntimeError("El proveedor falso se quedó sin respuestas preparadas.")
        datos = self.respuestas.pop(0)
        texto = datos if isinstance(datos, str) else json.dumps(datos, ensure_ascii=False)
        uso = Uso(entrada=1000, salida=500)
        try:
            objeto = salida.model_validate_json(texto)
        except ValidationError as e:
            raise SalidaInvalida(resumir_errores(e), texto, uso) from e
        return Respuesta(objeto=objeto, texto=texto, uso=uso, modelo=f"falso:{modelo}")
