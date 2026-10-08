#!/usr/bin/env bash
# Respaldo diario (lo lanza clt4bp-respaldo.timer, como root): volcado de PostgreSQL, archivos
# subidos y configuración, cifrados con restic en un repositorio FUERA de este servidor.
# Lee /etc/clt4bp/restic.env.
set -euo pipefail
set -a
# shellcheck source=/dev/null
source /etc/clt4bp/restic.env
set +a

DIR=/var/backups/clt4bp
mkdir -p "$DIR"
chmod 700 "$DIR"
runuser -u postgres -- pg_dump --format=custom --dbname=clt4bp > "$DIR/clt4bp.dump"

restic backup --tag diario "$DIR/clt4bp.dump" /srv/clt4bp/shared/storage/app \
    /srv/clt4bp/shared/web.env /srv/clt4bp/shared/agente.env
restic forget --tag diario --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune
restic check --read-data-subset=1/20 # lee al azar 5 % de los datos: detecta corrupción sin bajarlo todo

# Aviso de "sigo vivo": si un día no llega, el servicio de monitoreo te escribe (7.5)
if [[ -n "${PING_RESPALDO:-}" ]]; then curl -fsS -m 10 "$PING_RESPALDO" > /dev/null; fi
echo "respaldo $(date -u +%FT%TZ)"
