# CLT4BP — Plataforma híbrida

Aula virtual (Laravel), consola del instructor (Electron) y agente IA (FastAPI) basados en el modelo instruccional CLT4BP.

## Requisitos

Windows 11, Docker Desktop, PHP 8.3+ con Composer, Node 24 LTS, uv (Python 3.13) y PowerShell 7.
Extensiones de PHP: `pdo_pgsql`, `pgsql`, `intl`, `zip`, `sodium`, `mbstring`, `fileinfo`, `curl`, `openssl`.

## Levantar el entorno local

1. **Servicios** (PostgreSQL + pgvector, Mailpit, Piston), desde la raíz:
   `docker compose -f infra/docker-compose.yml up -d`
   Piston arranca sin lenguajes; instálalos una vez con los comandos de la Etapa 0 y comprueba con `./infra/piston/probar.ps1`.
2. **Web y API** (`http://localhost:8000`):
   ```powershell
   cd apps/web
   composer install; npm install
   cp .env.example .env; php artisan key:generate   # ajusta DB_PORT si tu PostgreSQL local ocupa el 5432
   php artisan migrate --seed                       # requiere ADMIN_EMAIL y ADMIN_PASSWORD en .env
   composer run dev                                 # servidor + cola + Vite
   ```
   Pruebas: `php artisan test`. Los correos se ven en http://localhost:8025.
3. **Agente de IA** (FastAPI; necesario para generar y calificar tareas), con la web corriendo.
   Por omisión usa un modelo local con [Ollama](https://ollama.com/download) (instálalo nativo en
   Windows: usa la GPU sin configurar Docker) y los embeddings del material del curso también son locales:
   ```powershell
   ollama pull qwen3:4b; ollama pull qwen3:8b; ollama pull bge-m3
   cd services/agent
   uv sync
   cp .env.example .env   # AGENTE_TOKEN debe ser el MISMO valor que en apps/web/.env
   uv run fastapi dev app/main.py --host 127.0.0.1 --port 8100   # «dev» recarga los cambios del código
   ```
   En `apps/web/.env`, con el modelo local: `AGENTE_TIMEOUT=900` y `DB_QUEUE_RETRY_AFTER=1000`.
   Define también las variables de usuario de Windows `OLLAMA_FLASH_ATTENTION=1` y `OLLAMA_MAX_LOADED_MODELS=1`
   y reinicia Ollama. Los modelos
   por omisión (`qwen3:4b`; `qwen3:8b` solo para «calidad alta») están pensados para una GPU de 8 GB; con 12 GB
   o más libres, `MODELO_LOCAL_NORMAL=qwen3:8b`. Compruébalo con `ollama ps` mientras genera: `100% GPU`.
   Para usar Claude en lugar del modelo local: `PROVEEDOR=claude` y `ANTHROPIC_API_KEY` en
   `services/agent/.env` (ver `docs/decisiones/0006-agente-local-y-rag.md`). Pruebas: `uv run pytest`.
4. **Consola del instructor** (con la web corriendo):
   ```powershell
   cd apps/desktop
   npm install
   npm run dev
   ```
   Pruebas y tipos: `npm test`, `npm run typecheck`.
5. **Contratos**: tras cambiar un esquema de `packages/contracts`, ejecuta `npm run gen` y versiona los tipos generados.
6. **Instrumentos** (una vez, y tras cada `migrate:fresh`):
   ```powershell
   cd apps/web
   php artisan instrumentos:cargar ../../instruments/mslq.json
   php artisan instrumentos:cargar ../../instruments/cs.json
   php artisan instrumentos:cargar ../../instruments/paas.json
   ```
7. **Estudiantes simulados** (solo `APP_ENV=local`): `php artisan demo:diagnostico <id_curso> --tipo=bimodal`
   (`homogeneo`, `bimodal` o `disperso`) crea 30 estudiantes con perfil para probar la decisión y los grupos.

## Desplegar

- **Servidor propio** (Ubuntu, todo en una máquina): `docs/despliegue.md` e `infra/prod`.
- **Railway + agente en el equipo del instructor**: la web, la base y la ejecución de código en Railway; el agente,
  la consola y los modelos en cada equipo, conectados por un relevo de salida (sin IP pública ni túneles):
  `docs/despliegue-railway.md`, `infra/railway/web.env.ejemplo` e `infra/agente-local` (`./iniciar.ps1` o
  `./iniciar.sh`).

## Estado

Etapas 0 a 7 implementadas (entorno, núcleo, diagnóstico, agente de IA, aula, evaluación,
producción). Ver `CLAUDE.md` para el detalle de cada una y lo que sigue pendiente, y
`docs/decisiones/` para las decisiones de diseño registradas.
