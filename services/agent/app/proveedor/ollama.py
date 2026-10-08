"""Proveedor local: un modelo abierto servido por Ollama, con salida restringida al JSON Schema pedido.

Nada sale del servidor. No hay caché de prompts ni costo por token: el uso se registra igual
(tokens de entrada y salida) para comparar con Claude en la regresión.
"""

from typing import Any

import httpx
from pydantic import BaseModel, ValidationError

from .base import ProveedorNoDisponible, Respuesta, SalidaInvalida, T, Uso, resumir_errores


def texto_sistema(sistema: list[dict[str, Any]]) -> str:
    """Los bloques de sistema de Claude (con cache_control) se unen en un solo texto."""
    return "\n\n".join(b["text"] for b in sistema if b.get("type") == "text")


class ProveedorOllama:
    def __init__(
        self, url: str, num_ctx: int = 16384, temperatura: float = 0.3, pensar: bool | None = False,
        timeout: float = 900, keep_alive: str = "30m", transporte: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        # timeout: una generación local larga puede tardar varios minutos
        self.cliente = httpx.AsyncClient(base_url=url, timeout=httpx.Timeout(timeout, connect=5), transport=transporte)
        self.num_ctx, self.temperatura, self.pensar, self.keep_alive = num_ctx, temperatura, pensar, keep_alive
        self._esquemas: dict[type[BaseModel], dict[str, Any]] = {}

    def esquema(self, salida: type[BaseModel]) -> dict[str, Any]:
        if salida not in self._esquemas:
            self._esquemas[salida] = salida.model_json_schema()
        return self._esquemas[salida]

    def presupuesto(self, textos: list[str], max_tokens: int) -> int:
        """Tokens de salida que caben en el contexto. Los max_tokens de las plantillas (hasta 32 000) están pensados
        para Claude; aquí prompt y respuesta comparten num_ctx, y lo que no cabe desbordaría el contexto a media
        respuesta. El prompt se estima en 3 caracteres por token: en español, qwen3 da ~4, así que sobra margen."""
        estimado = sum(len(t) for t in textos) // 3
        return max(512, min(max_tokens, self.num_ctx - estimado))

    async def generar(
        self, sistema: list[dict[str, Any]], mensajes: list[dict[str, Any]], salida: type[T], modelo: str, max_tokens: int
    ) -> Respuesta:
        mensajes_completos = [{"role": "system", "content": texto_sistema(sistema)}, *mensajes]
        num_predict = self.presupuesto([str(m["content"]) for m in mensajes_completos], max_tokens)
        cuerpo: dict[str, Any] = {
            "model": modelo,
            "messages": mensajes_completos,
            "stream": False,
            "keep_alive": self.keep_alive,
            "format": self.esquema(salida),
            "options": {"num_ctx": self.num_ctx, "temperature": self.temperatura, "num_predict": num_predict},
        }
        # Qwen3 y otros «razonan» antes de responder: más lento y no aporta con salida restringida.
        # None = no mandar el campo (modelos que no admiten razonamiento lo rechazan).
        if self.pensar is not None:
            cuerpo["think"] = self.pensar

        try:
            r = await self.cliente.post("/api/chat", json=cuerpo)
        except httpx.ReadTimeout as e:
            # Responde, pero demasiado lento: casi siempre, el modelo no cabe en la memoria de la GPU
            raise ProveedorNoDisponible(
                f"Ollama tardó más de {self.cliente.timeout.read:.0f} s con «{modelo}»: revisa con `ollama ps` que diga "
                "100% GPU, o usa un modelo más pequeño (MODELO_LOCAL_NORMAL) o menos contexto (OLLAMA_NUM_CTX)."
            ) from e
        except httpx.HTTPError as e:
            raise ProveedorNoDisponible(f"Ollama no responde en {self.cliente.base_url} ({e.__class__.__name__}).") from e
        if r.status_code == 404:
            raise ProveedorNoDisponible(f"Ollama no tiene el modelo «{modelo}»: instálalo con `ollama pull {modelo}`.")
        if r.status_code >= 400 and "repeat" in r.text:
            # «token repeat limit reached»: el modelo se quedó repitiendo y Ollama cortó la respuesta (tareas T5 a T8,
            # 2026-10-06). Es una mala respuesta, no un Ollama caído: se reintenta pidiéndola más concisa
            raise SalidaInvalida("El modelo se quedó repitiendo el mismo texto y la respuesta se cortó.", "", Uso(), cortada=True)
        if r.status_code >= 400:
            raise ProveedorNoDisponible(f"Ollama respondió {r.status_code}: {r.text[:300]}")

        datos = r.json()
        uso = Uso(entrada=datos.get("prompt_eval_count", 0), salida=datos.get("eval_count", 0))
        texto = datos.get("message", {}).get("content", "")

        if datos.get("done_reason") == "length":
            raise SalidaInvalida("La respuesta se cortó por longitud: sé más breve o genera menos elementos.", texto, uso, cortada=True)
        try:
            objeto = salida.model_validate_json(texto)
        except ValidationError as e:
            raise SalidaInvalida(resumir_errores(e), texto, uso) from e
        return Respuesta(objeto=objeto, texto=texto, uso=uso, modelo=f"ollama:{datos.get('model', modelo)}")
