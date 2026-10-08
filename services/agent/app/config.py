from functools import lru_cache
from typing import Literal

from pydantic_settings import BaseSettings, SettingsConfigDict


class Ajustes(BaseSettings):
    """Configuración por variables de entorno (o services/agent/.env en desarrollo)."""

    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    # Secreto compartido con Laravel: solo Laravel llama a este servicio
    agente_token: str = "cambia-este-secreto"

    # Quién genera: «local» (Ollama en este servidor; nada sale de él) o «claude» (API de Anthropic)
    proveedor: Literal["local", "claude"] = "local"

    # Claude. La clave vive solo aquí; nunca en Laravel ni en la consola.
    anthropic_api_key: str = ""
    modelo_normal: str = "claude-sonnet-5"  # generación de material
    modelo_ligero: str = "claude-haiku-4-5"  # resúmenes y tareas cortas
    modelo_alto: str = "claude-opus-5-5"  # solo si el instructor pide calidad alta

    # Ollama. Los modelos se instalan con `ollama pull <modelo>`. Por omisión, para una GPU de 8 GB (ADR 0006):
    # normal y ligero son el mismo modelo, así nunca se recarga entre plantillas. Con 12 GB o más de VRAM libre,
    # MODELO_LOCAL_NORMAL=qwen3:8b.
    ollama_url: str = "http://localhost:11434"
    modelo_local_normal: str = "qwen3:4b"
    modelo_local_ligero: str = "qwen3:4b"
    modelo_local_alto: str = "qwen3:8b"  # solo si el instructor pide calidad alta; en 8 GB no cabe entero: lento
    # Contexto: prompt de sistema + diseño + material + la respuesta anterior al corregir. Con 16k, el tercer
    # intento de una clase de tareas se cortaba; 24k cabe entero en 8 GB con qwen3:4b (32k ya no: ADR 0006)
    ollama_num_ctx: int = 24576
    # Modo de razonamiento: «omitir» para modelos que no lo admiten (Ollama rechaza el campo)
    ollama_pensar: Literal["no", "si", "omitir"] = "no"
    ollama_timeout: int = 900
    # Cuánto sigue cargado el modelo sin uso: evita ~15 s de carga en cada propuesta (el de Ollama es 5 min)
    ollama_keep_alive: str = "30m"

    # Material del curso (RAG): los embeddings siempre son locales, con cualquier proveedor.
    # La dimensión (1024) está fija en la migración de Laravel: cambiar de modelo exige migrar y reprocesar.
    modelo_embeddings: str = "bge-m3"

    # Trazador (services/trazador): calcula los pasos reales de las trazas de código con gdb
    trazador_url: str = "http://localhost:2010"

    # Piston: el agente prueba el código que genera antes de entregarlo
    piston_url: str = "http://localhost:2000"

    # Una clase de tareas se completa en la misma propuesta con sus conceptos y su información procedimental
    # (app/etapas.py). Apagado por omisión: la consola genera el tema completo como una cadena de propuestas (ADR 0007)
    clase_completa: bool = False
    # Una clase de tareas trae T1–T4 y, en una segunda solicitud dentro de la misma propuesta, T5–T8: ocho tareas en una
    # sola respuesta excedían a qwen3:4b (se cortaba y repetía el mismo ejercicio, ADR 0007)
    tareas_complementarias: bool = True
    # Segundos tras los cuales ya no se lanzan más solicitudes de seguimiento: la cola de Laravel corta en AGENTE_TIMEOUT
    presupuesto_clase_s: int = 600

    # Intentos por generación (1 + 2 correcciones, como dice la propuesta)
    max_intentos: int = 3

    # Monitoreo de errores (Etapa 7): vacío en desarrollo
    sentry_dsn: str = ""

    def pensar(self) -> bool | None:
        return {"no": False, "si": True, "omitir": None}[self.ollama_pensar]

    def modelo(self, nivel: str, calidad: str) -> str:
        if self.proveedor == "local":
            normal, ligero, alto = self.modelo_local_normal, self.modelo_local_ligero, self.modelo_local_alto
        else:
            normal, ligero, alto = self.modelo_normal, self.modelo_ligero, self.modelo_alto
        if calidad == "alta":
            return alto
        return ligero if nivel == "ligero" else normal


@lru_cache
def ajustes() -> Ajustes:
    return Ajustes()
