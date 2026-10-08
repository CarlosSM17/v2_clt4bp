# Despliegue en Railway con el agente en el equipo del instructor

La plataforma web (aula, API, base de datos y ejecución de código de los estudiantes) corre en Railway; el agente de
IA corre en el equipo de cada instructor, con su GPU y Ollama, y se conecta a la plataforma por su cuenta; la consola
del instructor corre en ese mismo equipo y habla con la plataforma por internet. El porqué está en el ADR 0008.

```
                    Railway (proyecto CLT4BP)
  ┌───────────────────────────────────────────────────────────┐
  │  web  (Laravel + colas + programador)  ── red privada ──┐  │
  │    │ https público                                     │  │
  │    ├── db        PostgreSQL 17 + pgvector (volumen)    │  │
  │    └── ejecutor  gcc/g++/Python, API de Piston  ◄──────┘  │
  └────▲──────────────────────▲───────────────────────────────┘
       │ HTTPS                │ HTTPS (solo de salida: sondeo largo)
  navegador de           equipo del instructor
  los estudiantes        consola Electron · conector → agente → Ollama (GPU) · trazador
```

| Qué | Dónde | Imagen o carpeta | Memoria medida en reposo |
|---|---|---|---|
| Plataforma web: aula, API de la consola, colas `default` y `agente`, programador | Railway, servicio `web` | `apps/web/Dockerfile` (contexto: raíz) | ~200 MB |
| Base de datos con pgvector | Railway, servicio `db` | `pgvector/pgvector:pg17` | — |
| Ejecución del código de los estudiantes | Railway, servicio `ejecutor` (solo red privada) | `services/trazador/Dockerfile` | ~15 MB |
| Agente, conector y trazador | Equipo del instructor (Docker) | `infra/agente-local` | ~210 MB en total |
| Modelos | Equipo del instructor (Ollama nativo) | `qwen3:4b`, `bge-m3` | 6.3 GB de VRAM con `qwen3:4b` |
| Consola del instructor | Equipo del instructor | `apps/desktop` | — |

## 1. Railway

Crea un proyecto vacío y, dentro, tres servicios con **estos nombres exactos** (las variables se refieren a ellos):
`db`, `ejecutor` y `web`. Conecta el repositorio de GitHub donde lo pide cada servicio.

### 1.1 `db`: PostgreSQL con pgvector

1. *New → Docker Image* → `pgvector/pgvector:pg17`; renómbralo `db`.
2. *Volumes → New Volume*, montado en `/var/lib/postgresql/data`.
3. Variables: `POSTGRES_DB=clt4bp`, `POSTGRES_USER=clt4bp`, `POSTGRES_PASSWORD=` (una larga y aleatoria) y
   `PGDATA=/var/lib/postgresql/data/pgdata` (el volumen trae una carpeta `lost+found` y PostgreSQL no inicia sobre un
   directorio que no está vacío).
4. Sin dominio público: la web lo alcanza por la red privada. La extensión `vector` la crea la primera migración.

### 1.2 `ejecutor`: el código de los estudiantes

Piston necesita un contenedor privilegiado y Railway no los permite; este servicio ejecuta el código con la misma API
(ver *Límites* al final).

1. *New → GitHub Repo* → este repositorio; renómbralo `ejecutor`.
2. *Settings → Root Directory*: `/services/trazador` (usa su `Dockerfile` y su `railway.json`).
3. Variables: `PUERTO=2010`.
4. **Sin dominio público** (*Networking*: no generes dominio). Solo `web` lo llama, por la red privada.

### 1.3 `web`: la plataforma

1. *New → GitHub Repo* → este repositorio; renómbralo `web`. *Root Directory*: déjalo en `/` (la imagen necesita
   `instruments/`).
2. *Settings → Config-as-code → Railway Config File*: `/apps/web/railway.json` (construye con `apps/web/Dockerfile`,
   revisa la salud en `/up`). Si tu panel no muestra esa opción, agrega la variable
   `RAILWAY_DOCKERFILE_PATH=apps/web/Dockerfile`.
3. *Volumes → New Volume*, montado en `/app/storage` (archivos subidos: medios y material del curso).
4. *Variables → Raw Editor*: pega `infra/railway/web.env.ejemplo` y llena lo marcado:
   - `APP_KEY`: en tu equipo, `cd apps/web; php artisan key:generate --show`.
   - `AGENTE_TOKEN`: un secreto largo (`openssl rand -hex 32`). Es el mismo que llevará cada equipo con el agente.
   - `ADMIN_EMAIL` y `ADMIN_PASSWORD`: la cuenta de administración inicial.
   - Correo (`MAIL_*`): el SMTP de la institución o de un proveedor transaccional. Sin correo no llegan las
     verificaciones de cuenta ni las invitaciones. Algunos planes de Railway restringen el SMTP saliente: si los
     correos no salen, revisa el plan o usa un proveedor que acepte otro puerto.
5. *Networking → Generate Domain* (o tu dominio propio con su registro CNAME). Ese dominio es la dirección de la
   plataforma para estudiantes, consola y agentes.
6. Deja **apagado** el modo que duerme servicios sin tráfico (*Serverless*): las colas y el programador deben seguir
   corriendo aunque nadie navegue.

### 1.4 Primer despliegue

Al arrancar, el contenedor aplica las migraciones, carga los datos base porque `CLT4BP_SEMBRAR=si` (roles, catálogo de
efectos, el administrador y los instrumentos MSLQ, CS y Paas) y levanta la web, las dos colas y el programador.

1. Abre `https://<tu-dominio>/up`: debe responder 200.
2. Entra con la cuenta de administración y activa la verificación en dos pasos (el personal la necesita para la
   consola).
3. Quita `CLT4BP_SEMBRAR`, `ADMIN_PASSWORD` y, si quieres, `ADMIN_EMAIL` de las variables. Railway vuelve a desplegar.
4. Da de alta a los instructores desde la consola (Instructores): reciben una invitación por correo.

## 2. El agente en el equipo del instructor

Requisitos: Docker (Desktop en Windows y macOS) y [Ollama](https://ollama.com/download) nativo. Con una GPU de 8 GB,
`qwen3:4b`; sin GPU funciona, pero mucho más lento; con `PROVEEDOR=claude` no hace falta GPU (requiere
`ANTHROPIC_API_KEY`).

1. Copia la carpeta del repositorio al equipo (o clónalo).
2. En `infra/agente-local`: copia `ejemplo.env` a `.env` y llena `RELEVO_URL` (`https://<tu-dominio>`) y
   `AGENTE_TOKEN` (el mismo de Railway).
3. Windows: `./iniciar.ps1`. macOS y Linux: `./iniciar.sh`. El script revisa Docker y Ollama, descarga los modelos que
   falten, construye y arranca el trazador, el agente y el conector, y espera a que el agente responda.
4. En Windows conviene definir las variables de usuario `OLLAMA_FLASH_ATTENTION=1` y `OLLAMA_MAX_LOADED_MODELS=1` y
   reiniciar Ollama. En Linux, Ollama debe escuchar en todas las interfaces (`OLLAMA_HOST=0.0.0.0` en su servicio) para
   que el contenedor lo alcance.

El conector abre la conexión hacia Railway (HTTPS de salida): funciona detrás de cualquier router o cortafuegos que
permita navegar, sin IP pública, túneles ni puertos abiertos. Su actividad: `docker compose logs -f conector`.

Puede haber varios equipos con agente a la vez: cada solicitud la atiende uno solo (el primero que la reclama). Si
ninguno está conectado, lo que el instructor espera en pantalla (verificar, calcular pasos, plantillas, estadísticas)
falla en el acto con un mensaje claro, y las generaciones y el material esperan en la cola hasta `AGENTE_TIMEOUT`
(900 s) a que un agente se conecte.

## 3. La consola del instructor

La consola necesita la dirección de la plataforma. Tres maneras, en orden de prioridad:

1. Variable `CLT4BP_API_URL=https://<tu-dominio>` al abrirla (útil con `npm run dev`).
2. Archivo `servidor.json` en la carpeta de datos de la consola, con `{"api_url": "https://<tu-dominio>"}`:
   Windows `%APPDATA%\CLT4BP Consola\`, macOS `~/Library/Application Support/CLT4BP Consola/`, Linux
   `~/.config/CLT4BP Consola/`. Así un mismo instalador sirve para cualquier plataforma.
3. Al compilar el instalador: `MAIN_VITE_API_URL=https://<tu-dominio>/api/v1` en `apps/desktop/.env.production` y
   `npm run build:win` (firmado, ver `docs/despliegue.md`).

Basta con el dominio: la consola agrega `/api/v1`.

## 4. Comprobar que todo está conectado

- [ ] `https://<tu-dominio>/up` responde 200 y la página de inicio carga con estilos.
- [ ] En el equipo del instructor, `http://127.0.0.1:8100/salud` dice `"prompt": "sistema-v12"` y el registro del
      conector dice «Conectado a https://<tu-dominio> con 3 hilos».
- [ ] En la consola, Estudio de un curso: «Verificar» responde (va por el relevo hasta el agente local).
- [ ] Una propuesta pequeña (Objetivos) llega en uno o dos minutos con `qwen3:4b`.
- [ ] En Materiales, un PDF pasa a «Listo».
- [ ] Un estudiante ejecuta y envía código en una tarea (va al servicio `ejecutor`).

## 5. Actualizar

- **Plataforma**: cada push a la rama que sigue Railway vuelve a construir y desplegar; el arranque aplica las
  migraciones nuevas. Mientras haya estudiantes, las migraciones solo agregan (ver `docs/despliegue.md`).
- **Agente**: en cada equipo, `git pull` y otra vez `./iniciar.ps1` (o `.sh`); reconstruye las imágenes.
- **Consola**: un instalador nuevo, o `npm run dev` desde el repositorio actualizado.

## 6. Límites y seguridad de esta modalidad

- **Ejecución sin isolate.** En Railway el código de los estudiantes corre como usuario sin privilegios, en un
  contenedor sin secretos ni dominio público, con límites de CPU (5 s), memoria (256 MB), procesos y salida (1 MB), y
  en una carpeta temporal por ejecución. Piston, además, aísla cada ejecución en su propio espacio de nombres y le quita
  la red; aquí un programa podría abrir conexiones de salida durante sus segundos de vida. Si eso no es aceptable para
  el piloto, `PISTON_URL` puede apuntar a un Piston en un servidor propio (`infra/prod/piston.yml`) y el resto no cambia.
- **El secreto del agente.** `AGENTE_TOKEN` autoriza a reclamar trabajo y a contestarlo: trátalo como una contraseña,
  cámbialo en Railway y en cada `.env` si se filtra. El conector, aun con el token, solo reenvía rutas `/v1/` al agente
  de su propio equipo.
- **Datos en tránsito.** Por el relevo viaja lo mismo que en el modo directo: el diseño del curso y resúmenes
  seudonimizados y agregados de los grupos, nunca nombres ni correos. Cada solicitud se borra en cuanto la plataforma
  lee su respuesta (y cualquier resto, a las 24 horas).
- **Respaldos.** El volumen de `db` es el dato que importa: usa los respaldos de volumen de Railway si tu plan los
  incluye, o un `pg_dump` periódico fuera de Railway (`infra/prod/respaldar.sh` muestra el procedimiento con restic).

## 7. Solución de problemas

| Síntoma | Causa probable | Arreglo |
|---|---|---|
| El despliegue de `web` no pasa la revisión de salud | Faltan variables (`APP_KEY`, `DB_*`) o la base no responde | Registro del despliegue en Railway; revisa que `db` esté arriba y los nombres de servicio sean exactos |
| PostgreSQL no inicia: «directory not empty» | Falta `PGDATA` con subcarpeta | `PGDATA=/var/lib/postgresql/data/pgdata` |
| «Ejecutar» falla para todos | `PISTON_URL` no apunta al `ejecutor` o falta `PUERTO=2010` | `http://${{ejecutor.RAILWAY_PRIVATE_DOMAIN}}:2010/api/v2` |
| Enlaces o medios con `http://`, firmas inválidas | Falta confiar en el proxy de Railway | `CLT4BP_PROXIES_CONFIABLES=*` |
| La consola dice «No hay ningún agente local conectado» | El conector no corre o no llega a la plataforma | `docker compose logs conector` en el equipo del instructor |
| El conector registra «rechazó el token» | `AGENTE_TOKEN` distinto en Railway y en `.env` | Igualarlos y `./iniciar.ps1` |
| El conector registra «no está en modo relevo» | Falta `AGENTE_MODO=relevo` en Railway | Agregarla |
| El agente responde 503 «no responde en http://agente:8100» | El contenedor del agente no arrancó | `docker compose logs agente` |
| Generación muy lenta | El modelo no cabe en la GPU | `ollama ps` debe decir 100 % GPU; usa `qwen3:4b` |
| No llegan correos | SMTP mal configurado o bloqueado por el plan | Revisa `MAIL_*` y el registro de `web` |
