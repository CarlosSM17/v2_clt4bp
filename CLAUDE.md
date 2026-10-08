# CLT4BP — Plataforma híbrida

Aula virtual (Laravel) + consola del instructor (Electron) + agente IA (FastAPI)
basados en el modelo instruccional CLT4BP (TCC, 4C/ID, ARCS, DI de Tomlinson,
protocolos verbales). Idioma de la interfaz y del código de dominio: español.

## Estructura
- apps/web: Laravel 13 + Inertia 3 + Vue 3 + TypeScript. API para la consola
  en /api/v1 (Sanctum).
- apps/desktop: Electron + electron-vite + Vue 3. La lógica con red y disco
  vive en el proceso main.
- services/agent: FastAPI. Solo lo llama Laravel. Nunca accede a la base de
  datos de estudiantes. Proveedor configurable: `PROVEEDOR=local` (Ollama, por omisión) o `claude`.
  RAG local: el agente solo fragmenta y calcula embeddings (`bge-m3`); Laravel es dueño del índice en
  pgvector y elige qué material viaja en cada solicitud (`BuscadorMaterial`). Ver ADR 0006.
- services/trazador: contenedor aislado (gcc/g++ + gdb + Python) que ejecuta un programa línea por línea y
  devuelve sus pasos reales. Lo llama solo el agente: los pasos de las trazas (bloques ```traza) nunca los escribe
  el modelo. También ejecuta programas con la API de Piston (`ejecutor.py`, `/api/v2/execute`): sustituye a Piston
  donde no hay contenedores privilegiados (Railway). Pruebas:
  `MSYS_NO_PATHCONV=1 docker exec clt4bp-trazador python3 -m unittest discover -s /app/pruebas`.
- Despliegue en Railway (ADR 0008, `docs/despliegue-railway.md`): `web` (`apps/web/Dockerfile`, contexto raíz),
  `db` (pgvector) y `ejecutor` (`services/trazador`). El agente corre en el equipo del instructor (`infra/agente-local`:
  trazador + agente + conector) y Laravel le llega por el relevo (`AGENTE_MODO=relevo`): el conector
  (`services/agent/app/relevo.py`) reclama las solicitudes de `/api/v1/relevo`, nunca al revés. En local y en el
  servidor propio sigue `AGENTE_MODO=directo`.
- packages/contracts: JSON Schema (fuente de verdad del contenido 4C/ID).
  Tras cambiarlo: `npm run gen`.

## Comandos
- Servicios: `docker compose -f infra/docker-compose.yml up -d`
- Web: `cd apps/web; composer run dev` · pruebas: `php artisan test`
- Consola: `cd apps/desktop; npm run dev` · pruebas: `npm test`
- Agente: `cd services/agent; uv run fastapi dev app/main.py --port 8100` ·
  pruebas: `uv run pytest` · modelos locales (uno por comando): `ollama pull qwen3:4b`, `qwen3:8b`, `bge-m3`

## Notas del entorno local
- PostgreSQL del contenedor: 127.0.0.1:**5433** (no 5432: este equipo tiene un
  PostgreSQL de Windows en el 5432). Usar `DB_PORT=5433` en los `.env`.
- PHP: Laravel 13 exige 8.3+. `php` ya resuelve al 8.3 de winget (`php -v` debe decir 8.3.x); el
  PHP 8.2 viejo de `C:\php` se renombró a `php8.2-viejo.exe` para que no interceptara el comando.
- `composer run dev` levanta también un worker para la cola `agente` (`AppServiceProvider::configureDevCommands`):
  el `queue:listen` por omisión solo atiende `default`, y sin ese worker las propuestas y el material se quedan
  «en cola» para siempre.
- Ollama nativo en Windows, con las variables de usuario `OLLAMA_FLASH_ATTENTION=1` y `OLLAMA_MAX_LOADED_MODELS=1`
  (un solo modelo en la GPU: `qwen3:4b` con 24k de contexto ocupa 6.3 GB y `bge-m3` no cabe a la vez; alternar
  cuesta ~6 s; `bge-m3` en CPU es 40× más lento). La RTX 4060 tiene 8 GB,
  compartidos con el escritorio (Acrobat, Photoshop Express…): con el prompt real (~4 200 tokens) `qwen3:8b`
  no cabe y cae a 5–6 tokens/s; `qwen3:4b` sí (100 % GPU, 63 tokens/s) y por eso es el modelo por omisión
  (normal y ligero; `qwen3:8b` solo para «calidad alta»). Sin razonamiento (`OLLAMA_PENSAR=no`): con él tarda
  el doble y se corta. Medido en ADR 0006. No usar `OLLAMA_KV_CACHE_TYPE=q8_0`: aquí vuelve lentísima la
  lectura del prompt (~75 tokens/s). Con el modelo local,
  `apps/web/.env` necesita `AGENTE_TIMEOUT=900` y `DB_QUEUE_RETRY_AFTER=1000`: si no, la cola corta o
  relanza una generación en curso.
- En Windows, la recarga de `fastapi dev` puede quedarse colgada («Reloading…» sin un nuevo «Started server
  process») y el proceso viejo sigue respondiendo con el código anterior. Tras cambiar el agente, reiniciarlo y
  comprobar `GET http://127.0.0.1:8100/salud`: su campo `prompt` debe decir la `VERSION` actual.
- Si Electron abre con `Cannot read properties of undefined (reading 'isPackaged')`, la variable
  `ELECTRON_RUN_AS_NODE` está definida en la terminal: quitarla.

## Reglas
- Toda ruta nueva tiene política de autorización y una prueba que verifica el
  403.
- Nada del agente se publica sin aprobación del instructor.
- Al agente solo se envían seudónimos y datos agregados.
- No edites archivos marcados como GENERADO.
- Migraciones: nunca modificar una ya aplicada en main; crear una nueva.

- Todo el cálculo pedagógico (puntuar, calificar, perfil, homogeneidad, agrupación) vive en
  `apps/web/app/Domain`, en PHP puro y con pruebas unitarias en `tests/Unit/Domain` (sin base de datos).
- Los instrumentos Likert son datos: `instruments/*.json` se generan con `uv run instruments/generar_*.py`
  y se cargan con `php artisan instrumentos:cargar`. El MSLQ de la tesis tiene 73 ítems y 13 subescalas
  (ver `docs/decisiones/0003-reglas-del-perfil.md`).
- `PistonRealTest` usa el Piston real y se omite solo si no está disponible; el resto usa un ejecutor falso.
- `resources/contracts` y `resources/js/contracts` son GENERADOS y están excluidos del formateador.

## Etapa actual
- Etapa 7 — Producción (código completo de 7.1 a 7.6): CSP con nonce y cabeceras de seguridad
  (`App\Domain\Seguridad\PoliticaContenido`, reaplicadas también en las respuestas de error vía
  `bootstrap/app.php`); página «Mis datos» (revocar el consentimiento de investigación, descargar un
  ZIP con todos los datos propios); auditoría semanal de dependencias (`composer audit`/`npm
  audit`/`pip-audit`) con Dependabot; infraestructura de producción completa en `infra/prod`
  (Nginx, PHP-FPM, Supervisor, systemd, límites de Piston, `desplegar.sh`/`regresar.sh`);
  instalador firmado de la consola con «fuses» de Electron y auto-actualización
  (`electron-updater`); suite de ensayo general (Playwright de extremo a extremo, k6, escaneo de
  ZAP documentado en `docs/seguridad/zap.md`, calculadora de SUS); respaldos cifrados fuera del
  servidor con restic y su prueba de restauración; revisión automática de operación cada 5 minutos
  (`App\Domain\Operacion\Diagnostico`) con aviso por correo y monitoreo de errores (Sentry/agente);
  protocolo del piloto en `docs/piloto.md`. Etiqueta: v0.7.0 creada; v0.1.0 a v0.5.0 siguen sin
  crearse.
  El piloto real (`v1.0.0`) exige la puesta a cero, la aprobación de un comité de ética y un
  servidor de producción real — ninguno ejecutable desde este entorno de desarrollo. Tampoco es
  ejecutable aquí el estudio de usabilidad SUS con participantes humanos (solo la calculadora de
  puntajes) ni el escaneo activo completo de ZAP (murió por falta de memoria del entorno local en
  la regla de XSS por DOM, que abre su propio navegador; el resto de las reglas activas sí
  completaron, sin alertas — ver `docs/seguridad/zap.md`).
  Agente local + RAG (ADR 0006): implementado y ajustado para esta laptop (`qwen3:4b`, prompt `sistema-v12`; forma del material del «Mapa de ruta CLT4BP» en ADR 0007: tema completo en cadena, ayudas del tema, tareas por ruta).
  Para que un modelo pequeño entregue material completo, el trabajo va por etapas (`app/etapas.py`): las
  salidas esperadas de los casos salen de ejecutar la solución; una clase de tareas se completa con su soporte
  y una ayuda por tarea en solicitudes aparte (`CLASE_COMPLETA`, `PRESUPUESTO_CLASE_S`); los ejemplos resueltos
  llevan una traza calculada, con diagrama de memoria (pila, arreglos, punteros). Falta: correr los 20
  casos de regresión, compararlos con Claude (`--proveedor claude`, requiere `ANTHROPIC_API_KEY`) y un
  recorrido manual en la consola (subir un PDF en Materiales, pedir una clase de tareas).
  Sigue pendiente de etapas previas: el recorrido manual completo de 6.5 (datos simulados,
  comparación con R/JASP, informe del agente, exportación en Excel), lo de la Etapa 4
  (`ANTHROPIC_API_KEY`, correr la regresión, su recorrido manual) y los recorridos manuales en
  navegador de las Etapas 3 y 5 — mitigados parcialmente por la cobertura de Playwright de la
  Etapa 7, pero no equivalentes al recorrido original.
