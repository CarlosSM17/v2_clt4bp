#!/usr/bin/env bash
# Prueba de restauración (mensual, a mano, como root): carga el último respaldo en una base
# aparte y compara el número de filas con producción. Un respaldo que nunca se restauró no es
# un respaldo.
set -euo pipefail
set -a
# shellcheck source=/dev/null
source /etc/clt4bp/restic.env
set +a

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"; runuser -u postgres -- dropdb --if-exists clt4bp_restauracion' EXIT

restic restore latest --tag diario --target "$TMP" --include /var/backups/clt4bp/clt4bp.dump
runuser -u postgres -- createdb clt4bp_restauracion
runuser -u postgres -- pg_restore --no-owner --dbname=clt4bp_restauracion < "$TMP/var/backups/clt4bp/clt4bp.dump"

for tabla in users enrollments task_submissions learning_events instrument_responses assessment_attempts; do
    prod=$(runuser -u postgres -- psql -tAc "select count(*) from $tabla" clt4bp)
    resp=$(runuser -u postgres -- psql -tAc "select count(*) from $tabla" clt4bp_restauracion)
    printf '%-22s producción %8s · respaldo %8s\n' "$tabla" "$prod" "$resp"
done
echo "Archivos subidos en el respaldo: $(restic ls latest --tag diario | grep -c '^/srv/clt4bp/shared/storage/app/')"
