# Agente local de CLT4BP

Corre el agente de IA en este equipo y lo conecta a la plataforma en la nube (Railway). Guía completa:
`docs/despliegue-railway.md`; diseño: `docs/decisiones/0008-railway-y-agente-por-relevo.md`.

1. Instala Docker y [Ollama](https://ollama.com/download).
2. Copia `ejemplo.env` a `.env` y llena `RELEVO_URL` (la dirección de la plataforma) y `AGENTE_TOKEN` (el mismo que
   en Railway).
3. Windows: `./iniciar.ps1` · macOS y Linux: `./iniciar.sh`.

Ver la actividad: `docker compose logs -f conector` · Detener: `docker compose down` · Actualizar: `git pull` y otra
vez el script.
