# Agente de CLT4BP (FastAPI) y su conector de relevo con la plataforma en la nube (ADR 0008).
# La misma imagen sirve para los dos: el agente con la orden por omisión, el conector con `python -m app.relevo`.
# Se usa en infra/agente-local (equipo del instructor); el modelo corre en Ollama, fuera del contenedor (usa la GPU).
FROM python:3.13-slim

COPY --from=ghcr.io/astral-sh/uv:0.12.18 /uv /usr/local/bin/uv

WORKDIR /app
ENV UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PROJECT_ENVIRONMENT=/app/.venv \
    PYTHONUNBUFFERED=1 \
    PATH="/app/.venv/bin:$PATH"

# Primero las dependencias (capa que casi no cambia), después el código
COPY pyproject.toml uv.lock ./
RUN uv sync --locked --no-dev --no-install-project

COPY app ./app

# Sin privilegios: el agente no necesita escribir fuera de /tmp
RUN useradd --create-home --uid 10020 agente
USER agente

EXPOSE 8100
CMD ["fastapi", "run", "app/main.py", "--host", "0.0.0.0", "--port", "8100"]
