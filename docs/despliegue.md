# Despliegue en producción

Todo lo que se instala en el servidor vive en `infra/prod/` del repositorio, así que queda
versionado y se puede repetir en otra máquina. En el servidor, `/srv/clt4bp` queda así: `repo/`
(copia espejo del repositorio), `releases/` (una carpeta por despliegue), `current` (enlace a la
vigente) y `shared/` (lo que sobrevive entre despliegues: `.env`, archivos subidos, bitácoras y
descargas de la consola).

| Archivo | Destino en el servidor | Para qué |
|---|---|---|
| `preparar-servidor.sh` | se corre una vez | Instala y configura todo lo de esta tabla |
| `nginx/clt4bp.conf` | `/etc/nginx/sites-available/clt4bp` | Sitio web, descargas de la consola y HSTS |
| `php/clt4bp.conf` | `/etc/php/8.5/fpm/pool.d/` | Pool de PHP-FPM que corre como `deploy` |
| `supervisor/clt4bp.conf` | `/etc/supervisor/conf.d/` | Trabajadores de las colas `default` y `agente` |
| `systemd/clt4bp-agente.service` | `/etc/systemd/system/` | Servicio del agente en 127.0.0.1:8100 |
| `piston.yml` | `/opt/clt4bp-piston/compose.yml` | Piston con sus límites de producción |
| `desplegar.sh`, `regresar.sh` | `/srv/clt4bp/` | Desplegar una etiqueta y volver a la anterior |
| `web.env.ejemplo`, `agente.env.ejemplo` | `/srv/clt4bp/shared/` (sin «ejemplo») | Configuración de Laravel y del agente |

## 1. El servidor

Ubuntu Server 24.04 LTS (soporte hasta 2029), 4 vCPU, 8 GB de RAM, 100 GB de disco (más si se
subirán muchos videos), IP pública, un nombre en el DNS (p. ej. `clt4bp.midominio.mx`) y acceso
por SSH con llave. Si no hay infraestructura institucional, revisa con el comité dónde quedan
físicamente los datos y deja constancia en el protocolo. Pide también una cuenta de correo (SMTP
institucional o un proveedor transaccional) con los registros SPF, DKIM y DMARC del dominio
remitente ya configurados: sin ellos, las verificaciones de correo caen en spam.

## 2. Preparar el servidor

Con el DNS ya apuntando a la IP del servidor (compruébalo con `Resolve-DnsName
clt4bp.midominio.mx`), desde la raíz del repositorio en tu PC:

```
scp -r infra/prod tu-usuario@clt4bp.midominio.mx:/tmp/clt4bp-prod
ssh tu-usuario@clt4bp.midominio.mx
```

Y ya en el servidor:

```
sudo bash /tmp/clt4bp-prod/preparar-servidor.sh clt4bp.midominio.mx tu@midominio.mx
```

Tarda de 10 a 15 minutos. Si se detiene, el mensaje dice en qué bloque: corrígelo y vuelve a
correrlo, porque cada paso se puede repetir. Aprovecha para cerrar el acceso por contraseña al
SSH (`PasswordAuthentication no` en `/etc/ssh/sshd_config.d/`) si tu institución no lo hizo ya.

## 3. Base de datos y secretos

Genera dos valores y guárdalos en tu gestor de contraseñas: la contraseña de PostgreSQL y el
secreto compartido entre Laravel y el agente.

```
openssl rand -base64 24   # contraseña de la base
openssl rand -hex 32      # AGENTE_TOKEN, el mismo en los dos .env

sudo -u postgres createuser --pwprompt clt4bp
sudo -u postgres createdb -O clt4bp clt4bp
sudo -u postgres psql -d clt4bp -c "CREATE EXTENSION IF NOT EXISTS vector"

sudo -u deploy cp /tmp/clt4bp-prod/web.env.ejemplo /srv/clt4bp/shared/web.env
sudo -u deploy cp /tmp/clt4bp-prod/agente.env.ejemplo /srv/clt4bp/shared/agente.env
sudo chmod 600 /srv/clt4bp/shared/web.env /srv/clt4bp/shared/agente.env
sudo -u deploy nano /srv/clt4bp/shared/web.env
sudo -u deploy nano /srv/clt4bp/shared/agente.env
```

En `web.env` llena `APP_URL`, `DB_PASSWORD`, los `MAIL_*`, `AGENTE_TOKEN`,
`CLT4BP_CONTACTO_PRIVACIDAD` y los `ADMIN_*`; `APP_KEY` se genera sola en el primer despliegue.
En `agente.env`, el mismo secreto en `AGENTE_TOKEN` y el proveedor (`PROVEEDOR=local`, con Ollama
instalado por `preparar-servidor.sh`, o `claude`; ADR 0006). Con el modelo local, en `web.env`
`AGENTE_TIMEOUT=900` y `DB_QUEUE_RETRY_AFTER=1000`. Si usas Claude, para producción crea en
la Claude Console un espacio de trabajo aparte (p. ej. `clt4bp-produccion`) con su propio límite
de gasto mensual, y dentro de él una clave nueva: así el gasto del piloto no se mezcla con el de
desarrollo y una fuga de la clave de desarrollo no toca producción. El material del curso (RAG) no
necesita clave: sus embeddings se calculan con Ollama en el servidor.

### Trazador

Calcula los pasos reales de las trazas de código (gdb) y se construye desde la versión desplegada. Después del
primer despliegue, y cada vez que cambie `services/trazador`, como root:

```bash
docker compose -f /opt/clt4bp-trazador/compose.yml up -d --build
iptables -I DOCKER-USER -s 172.29.10.0/24 -m conntrack ! --ctstate ESTABLISHED,RELATED -j DROP  # sin salida a internet
netfilter-persistent save
curl -s http://127.0.0.1:2010/salud   # {"estado": "ok"}
```

A diferencia de Piston, el contenedor no corta su red por sí mismo: la regla de iptables bloquea sus conexiones
salientes (el código que ejecuta no es confiable) sin impedir que el agente lo llame.

## 4. Primer despliegue

El servidor baja el código con una llave propia de solo lectura:

```
sudo -u deploy ssh-keygen -t ed25519 -N "" -f /home/deploy/.ssh/id_ed25519
sudo cat /home/deploy/.ssh/id_ed25519.pub
```

En GitHub, en el repositorio: *Settings → Deploy keys → Add deploy key*, pega la llave y deja sin
marcar *Allow write access*. Luego:

```
sudo -u deploy ssh -T git@github.com   # acepta la huella de GitHub (responde yes)
sudo -u deploy git clone --mirror git@github.com:TU-USUARIO/clt4bp.git /srv/clt4bp/repo
```

En tu PC, etiqueta la versión candidata y súbela: `git tag v0.7.0-rc.1; git push --tags`. En el
servidor:

```
sudo -iu deploy /srv/clt4bp/desplegar.sh v0.7.0-rc.1
sudo supervisorctl reread && sudo supervisorctl update   # arranca los trabajadores de las colas

alias artisan='sudo -u deploy php /srv/clt4bp/current/apps/web/artisan'
artisan db:seed --force   # roles y administrador
for f in /srv/clt4bp/current/instruments/*.json; do artisan instrumentos:cargar "$f"; done
sudo -u deploy sed -i 's/^ADMIN_PASSWORD=.*/ADMIN_PASSWORD=/' /srv/clt4bp/shared/web.env
artisan optimize   # vuelve a cachear la configuración, ya sin la contraseña

# Lenguajes de Piston (los mismos que en desarrollo)
curl -s -X POST http://127.0.0.1:2000/api/v2/packages -H 'Content-Type: application/json' -d '{"language":"gcc","version":"10.2.0"}'
curl -s -X POST http://127.0.0.1:2000/api/v2/packages -H 'Content-Type: application/json' -d '{"language":"python","version":"3.12.0"}'
```

El `artisan optimize` final importa: `desplegar.sh` guardó la configuración en caché cuando
`ADMIN_PASSWORD` todavía estaba en el archivo. Después entra con el administrador en
`https://clt4bp.midominio.mx`: el sistema obliga a activar la verificación en dos pasos antes de
seguir (Etapa 1).

## 5. Despliegues siguientes

El ciclo es siempre el mismo: fusionar en `main` con la integración continua en verde, etiquetar
y desplegar esa etiqueta.

```
git switch main; git pull
git tag v1.0.1; git push --tags
ssh -t tu-usuario@clt4bp.midominio.mx sudo -iu deploy /srv/clt4bp/desplegar.sh v1.0.1
```

Tres reglas mientras haya estudiantes usando el sistema:

- **Las migraciones solo agregan** (tablas, columnas que admiten nulo, índices). Quitar o
  renombrar se hace en dos despliegues: primero el código deja de usar la columna, después una
  migración la borra. Así `regresar.sh` siempre funciona.
- **Si una versión sale mal**: `ssh -t tu-usuario@clt4bp.midominio.mx sudo -iu deploy
  /srv/clt4bp/regresar.sh` y después investigar con calma.
- **Para un cambio delicado**, poner la plataforma en mantenimiento antes (`artisan down
  --refresh=15`) y quitarlo al terminar (`artisan up`). Hacerlo fuera del horario de clase y
  avisarlo con un día de anticipación.

## 6. Respaldos cifrados

`infra/prod/respaldar.sh` vuelca PostgreSQL y respalda el volcado, los archivos subidos y las
dos configuraciones con [restic](https://restic.net/), cifrados, en un repositorio **fuera** de
este servidor (un SFTP de la institución o un almacenamiento compatible con S3): un respaldo en
el mismo disco no sobrevive a lo que más importa. Para instalarlo, sube de nuevo `infra/prod` a
`/tmp/clt4bp-prod` (el servidor borra `/tmp` al reiniciar) y, en el servidor:

```
sudo mkdir -p /etc/clt4bp && sudo chmod 700 /etc/clt4bp
sudo cp /tmp/clt4bp-prod/restic.env.ejemplo /etc/clt4bp/restic.env && sudo nano /etc/clt4bp/restic.env
openssl rand -base64 32 | sudo tee /etc/clt4bp/restic.clave > /dev/null
sudo chmod 600 /etc/clt4bp/restic.env /etc/clt4bp/restic.clave
sudo cat /etc/clt4bp/restic.clave  # cópiala a tu gestor de contraseñas: sin ella, nadie puede leer los respaldos

# Solo con SFTP: una llave para root, autorizada en el servidor de respaldos
sudo ssh-keygen -t ed25519 -N "" -f /root/.ssh/id_ed25519 && sudo cat /root/.ssh/id_ed25519.pub

sudo bash -c 'set -a; source /etc/clt4bp/restic.env; restic init'
sudo install -m 755 /tmp/clt4bp-prod/respaldar.sh /usr/local/sbin/clt4bp-respaldar
sudo install -m 755 /tmp/clt4bp-prod/probar-restauracion.sh /usr/local/sbin/clt4bp-probar-restauracion
sudo install -m 644 /tmp/clt4bp-prod/systemd/clt4bp-respaldo.service /tmp/clt4bp-prod/systemd/clt4bp-respaldo.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now clt4bp-respaldo.timer
sudo systemctl start clt4bp-respaldo # el primero, ahora
```

Verifica: `journalctl -u clt4bp-respaldo -n 20` termina con «respaldo…»; `systemctl list-timers
clt4bp-respaldo` muestra la próxima ejecución; y `sudo clt4bp-probar-restauracion` muestra los
mismos conteos en producción y en el respaldo (o casi: lo que entró después del respaldo).

Antes del piloto haz además un simulacro completo: en una máquina virtual nueva corre
`preparar-servidor.sh`, restaura el volcado y los archivos, despliega la misma etiqueta y entra.
Anota cuánto tardaste: es tu tiempo real de recuperación y va en el plan de gestión de datos. La
restauración, como root (`sudo -i`):

```
set -a; source /etc/clt4bp/restic.env; set +a
restic restore latest --tag diario --target /tmp/r
sudo -u postgres createuser --pwprompt clt4bp && sudo -u postgres createdb -O clt4bp clt4bp
runuser -u postgres -- pg_restore --no-owner --role=clt4bp --dbname=clt4bp < /tmp/r/var/backups/clt4bp/clt4bp.dump
cp -a /tmp/r/srv/clt4bp/shared/storage/app/. /srv/clt4bp/shared/storage/app/
cp /tmp/r/srv/clt4bp/shared/*.env /srv/clt4bp/shared/ && chown -R deploy:deploy /srv/clt4bp/shared
```

## Verificación

- Desde tu PC, `curl.exe -sI https://clt4bp.midominio.mx/login` responde 200 con
  `Strict-Transport-Security` y `Content-Security-Policy`; `curl.exe -sI
  http://clt4bp.midominio.mx` responde 301 hacia `https://`. La prueba de SSL Labs da A o mejor.
- En el servidor, `sudo supervisorctl status` muestra tres procesos `RUNNING`; `systemctl status
  clt4bp-agente` dice `active (running)`; `docker ps` muestra `clt4bp-piston`; y `artisan about`
  dice `production`, *Debug Mode* `OFF` y la configuración, eventos, rutas y vistas `CACHED`.
- `sudo ss -tlnp` muestra 2000 (Piston), 8100 (agente) y 5432 (PostgreSQL) solo en `127.0.0.1`;
  al exterior, solo 22, 80 y 443.
- La prueba de red de la Etapa 7.1 (aislamiento de Piston), ahora con `curl` contra
  `127.0.0.1:2000` en el servidor, responde «sin red».
- «¿Olvidaste tu contraseña?» con tu correo: el mensaje llega a la bandeja de entrada y, en sus
  encabezados, SPF y DKIM dicen `pass`.
- Desplegar una segunda etiqueta (`v0.7.0-rc.2`) y regresar con `regresar.sh`: la web sigue
  arriba en los dos cambios.
