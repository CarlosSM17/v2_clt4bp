# 0008 — Plataforma en Railway y agente en el equipo del instructor, unidos por un relevo

Fecha: 2026-10-07 · Estado: aceptada

## Contexto

Se pidió una versión para desplegar la plataforma web en Railway, con la consola del instructor y el agente local en
cualquier equipo, todo conectado a la plataforma en Railway. Dos supuestos del diseño original dejan de valer:

1. **Laravel llama al agente** (`AGENTE_URL`): con Laravel en la nube y el agente en el equipo del instructor (detrás
   de NAT o cortafuegos, sin IP pública), la nube no puede abrir esa conexión. Las generaciones tardan hasta 900 s.
2. **Piston ejecuta el código**: Piston exige un contenedor privilegiado (isolate) y Railway no los permite.

## Decisión

**Relevo** (`AGENTE_MODO=relevo`). Toda llamada de `ClienteAgente` al agente se guarda como solicitud pendiente
(`agent_relay_requests`); un **conector** en el equipo del agente (`services/agent/app/relevo.py`) la reclama con un
sondeo largo (`GET /api/v1/relevo/siguiente`, hasta 25 s, `FOR UPDATE SKIP LOCKED`), la reenvía al agente local tal
cual y devuelve su respuesta (`POST /api/v1/relevo/{id}/respuesta`); los documentos del material se descargan aparte
(`GET /api/v1/relevo/{id}/archivo`). Quien llamó recibe la misma `Response` que en el modo directo: los reintentos de la
cola, `->throw()` y el manejo de errores no cambian, y el agente no cambia en nada.

- El cuerpo viaja como texto JSON, sin decodificarse en PHP: `{}` sigue siendo `{}` (pydantic lo exige).
- Las rutas del relevo exigen `X-Agente-Token` = `AGENTE_TOKEN` (403 sin él) y solo existen en modo relevo (404).
- Lo que alguien espera en pantalla (verificar, plantillas, trazas, estadísticas) falla en el acto si ningún conector
  preguntó en los últimos 90 s (`AGENTE_RELEVO_AUSENTE`); generaciones, embeddings y material esperan hasta su plazo.
- Cada solicitud se borra al leerse su respuesta; los restos, a las 24 horas (`model:prune` cada hora).
- El conector usa varios hilos (3): una generación de minutos no bloquea una verificación. Solo reenvía rutas `/v1/`.
- `infra/agente-local` empaqueta trazador, agente y conector con Docker Compose; Ollama queda nativo (GPU).

**Ejecutor sin privilegios.** El contenedor del trazador (gcc/g++, gdb y Python, sin privilegios ni secretos) ahora
también responde `POST /api/v2/execute` y `GET /api/v2/runtimes` con la forma de Piston (`services/trazador/ejecutor.py`):
basta con apuntar `PISTON_URL` a él. Límites por ejecución: CPU según `run_timeout` (máximo 5 s) más reloj de pared,
256 MB de memoria virtual, 1 MB de salida escrita (a archivos, no a tuberías), 16 procesos por encima de los existentes,
carpeta temporal propia. Escucha en IPv6 e IPv4 (la red privada de Railway es IPv6).

**Railway.** Tres servicios: `web` (`apps/web/Dockerfile`: FrankenPHP con PHP 8.4; al arrancar migra, siembra si
`CLT4BP_SEMBRAR=si` y corre la web, las colas `default` y `agente` y el programador en un contenedor, con volumen en
`/app/storage`), `db` (`pgvector/pgvector:pg17` con volumen) y `ejecutor` (`services/trazador`, solo red privada).
Laravel confía en el proxy de Railway con `CLT4BP_PROXIES_CONFIABLES=*`.

**Consola.** La dirección de la API se puede fijar al abrirla (`CLT4BP_API_URL`) o con `servidor.json` en su carpeta de
datos, además de al compilar: un mismo instalador sirve para cualquier plataforma.

## Alternativas descartadas

- **Túnel hacia el agente** (Cloudflare Tunnel, ngrok, Tailscale): Laravel seguiría llamando al agente, pero cada
  equipo necesita una cuenta y una URL estable que la plataforma conozca, y algunos túneles cortan las peticiones a los
  100 s (las generaciones tardan hasta 900 s).
- **Agente en Railway**: sin GPU, `qwen3:4b` en CPU es inviable, y la decisión del proyecto es que el modelo corra en el
  equipo del instructor (ADR 0006). Con `PROVEEDOR=claude` sería posible, pero ya no sería un agente local.
- **Piston en Railway**: no arranca sin `privileged`. Piston en otro servidor sigue siendo posible (`PISTON_URL`) y da
  más aislamiento.

## Consecuencias

- La plataforma no depende de que un equipo con agente esté encendido salvo para lo que hace el agente: el aula, la
  calificación y la publicación funcionan siempre. Sin agente conectado, verificar y aprobar en la consola no responde.
- Riesgo aceptado: sin isolate, un programa de un estudiante podría abrir conexiones de salida durante sus segundos de
  ejecución desde el contenedor `ejecutor`, que no tiene secretos ni dominio público. Se documenta en
  `docs/despliegue-railway.md` con la alternativa de un Piston propio.
- Verificado en local (2026-10-07) con la imagen web en modo relevo y el kit del agente: plantillas, embeddings,
  verificador, traza y estadística (0.3–6 s), una generación de objetivos con `qwen3:4b` por la cola (15 s), un PDF
  procesado (8 s), y C++, C y Python ejecutados por el ejecutor desde la web, con el ciclo infinito cortado como límite
  excedido. `PistonRealTest` de Laravel pasa contra el ejecutor.
- No verificado: un despliegue real en Railway (requiere la cuenta del proyecto).
