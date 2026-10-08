"""Proveedor real: Claude con salidas estructuradas y caché de prompts."""

from typing import Any

from anthropic import AsyncAnthropic, transform_schema
from pydantic import BaseModel, ValidationError

from .base import Respuesta, SalidaInvalida, T, Uso, costo, resumir_errores


class ProveedorClaude:
    def __init__(self, api_key: str) -> None:
        # max_retries: el SDK reintenta solo los errores transitorios (429, 5xx, red).
        # timeout: con streaming es el máximo entre fragmentos, no la duración total.
        self.cliente = AsyncAnthropic(api_key=api_key, max_retries=3, timeout=120)
        self._esquemas: dict[type[BaseModel], dict[str, Any]] = {}

    def esquema(self, salida: type[BaseModel]) -> dict[str, Any]:
        """El SDK adapta el esquema de Pydantic a lo que aceptan las salidas estructuradas
        (mueve longitudes y patrones a la descripción). Se calcula una vez por modelo."""
        if salida not in self._esquemas:
            self._esquemas[salida] = transform_schema(salida.model_json_schema())
        return self._esquemas[salida]

    async def generar(
        self, sistema: list[dict[str, Any]], mensajes: list[dict[str, Any]], salida: type[T], modelo: str, max_tokens: int
    ) -> Respuesta:
        # Streaming: una clase de tareas completa puede tardar varios minutos en generarse
        async with self.cliente.messages.stream(
            model=modelo,
            max_tokens=max_tokens,
            system=sistema,  # el último bloque lleva cache_control: se cobra una fracción al repetirse
            messages=mensajes,
            output_config={"format": {"type": "json_schema", "schema": self.esquema(salida)}},
        ) as stream:
            r = await stream.get_final_message()

        u = r.usage
        uso = Uso(
            entrada=u.input_tokens,
            salida=u.output_tokens,
            cache_escritura=u.cache_creation_input_tokens or 0,
            cache_lectura=u.cache_read_input_tokens or 0,
        )
        uso.costo_usd = costo(modelo, uso)
        texto = next((b.text for b in r.content if b.type == "text"), "")

        if r.stop_reason == "refusal":
            raise RuntimeError("El modelo rechazó la solicitud; revisa las indicaciones del instructor.")
        if r.stop_reason == "max_tokens":
            raise SalidaInvalida("La respuesta se cortó por longitud: sé más breve o genera menos elementos.", texto, uso, cortada=True)
        try:
            # Pydantic aplica aquí las restricciones que el esquema de salida no pudo expresar
            objeto = salida.model_validate_json(texto)
        except ValidationError as e:
            raise SalidaInvalida(resumir_errores(e), texto, uso) from e
        return Respuesta(objeto=objeto, texto=texto, uso=uso, modelo=r.model)
