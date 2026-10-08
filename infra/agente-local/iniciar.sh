#!/usr/bin/env bash
# Arranca el agente local de CLT4BP y su conector con la plataforma en la nube (ADR 0008). macOS y Linux.
# Requisitos: Docker y Ollama (https://ollama.com/download). En Linux, Ollama con OLLAMA_HOST=0.0.0.0.
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -f .env ]; then
    cp ejemplo.env .env
    echo 'Creé .env a partir de ejemplo.env: llena RELEVO_URL y AGENTE_TOKEN y vuelve a ejecutar este script.'
    exit 1
fi
valor() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | tr -d '[:space:]'; }
if [ -z "$(valor AGENTE_TOKEN)" ] || [ -z "$(valor RELEVO_URL)" ] || valor RELEVO_URL | grep -q tu-app; then
    echo 'Faltan RELEVO_URL o AGENTE_TOKEN en .env.'
    exit 1
fi

docker info >/dev/null 2>&1 || { echo 'Docker no está corriendo.'; exit 1; }

if [ "$(valor PROVEEDOR)" != "claude" ]; then
    modelos=$(curl -fsS --max-time 5 http://127.0.0.1:11434/api/tags) \
        || { echo 'Ollama no responde en el puerto 11434: instálalo (https://ollama.com/download) y ábrelo.'; exit 1; }
    normal=$(valor MODELO_LOCAL_NORMAL); normal=${normal:-qwen3:4b}
    for modelo in "$normal" bge-m3; do
        if ! echo "$modelos" | grep -Eq "\"($modelo|$modelo:latest)\""; then
            echo "Descargando el modelo $modelo (una sola vez)…"
            ollama pull "$modelo"
        fi
    done
fi

docker compose up -d --build
echo 'Esperando al agente…'
for _ in $(seq 1 30); do
    if salud=$(curl -fsS --max-time 2 http://127.0.0.1:8100/salud); then break; fi
    sleep 2
done
[ -n "${salud:-}" ] || { echo 'El agente no arrancó: revisa «docker compose logs agente».'; exit 1; }
echo "Agente listo: $salud"
docker compose logs --tail 5 conector
echo 'Listo. Para ver la actividad: docker compose logs -f conector · Para detenerlo: docker compose down'
