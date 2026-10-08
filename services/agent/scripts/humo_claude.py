"""Prueba de humo: una llamada real y barata a Claude (menos de un centavo de dólar)."""

import asyncio

from pydantic import BaseModel

from app.config import ajustes
from app.proveedor.claude import ProveedorClaude


class Verbos(BaseModel):
    verbos: list[str]
    nota: str


async def main() -> None:
    a = ajustes()
    r = await ProveedorClaude(a.anthropic_api_key).generar(
        [{"type": "text", "text": "Eres un asistente de diseño instruccional. Responde en español."}],
        [{"role": "user", "content": "Da cinco verbos observables para objetivos de un curso de programación."}],
        Verbos,
        a.modelo_ligero,
        500,
    )
    print(r.objeto)
    print(r.modelo, r.uso)


asyncio.run(main())
