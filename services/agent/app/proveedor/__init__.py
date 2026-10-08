"""Elige el proveedor según PROVEEDOR: «local» (Ollama) o «claude»."""

from app.config import Ajustes

from .base import ProveedorLLM, ProveedorNoDisponible
from .claude import ProveedorClaude
from .ollama import ProveedorOllama


def crear_proveedor(a: Ajustes) -> ProveedorLLM:
    if a.proveedor == "local":
        return ProveedorOllama(
            a.ollama_url, a.ollama_num_ctx, pensar=a.pensar(), timeout=a.ollama_timeout, keep_alive=a.ollama_keep_alive
        )
    if not a.anthropic_api_key:
        raise ProveedorNoDisponible("Falta ANTHROPIC_API_KEY en services/agent/.env (o usa PROVEEDOR=local).")
    return ProveedorClaude(a.anthropic_api_key)
