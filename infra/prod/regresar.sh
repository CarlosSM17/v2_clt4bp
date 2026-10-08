#!/usr/bin/env bash
# Regresa a la versión desplegada antes de la vigente. Como deploy: /srv/clt4bp/regresar.sh
# No deshace migraciones: por eso las migraciones de un despliegue solo agregan (desplegar.sh).
set -euo pipefail

BASE=/srv/clt4bp
VIGENTE=$(readlink -f "$BASE/current")
ANTERIOR=$(find "$BASE/releases" -mindepth 1 -maxdepth 1 -type d | sort | grep -B1 -Fx "$VIGENTE" | head -n 1)

if [[ -z "$ANTERIOR" || "$ANTERIOR" == "$VIGENTE" ]]; then
  echo "No hay una versión anterior a $VIGENTE" >&2
  exit 1
fi

ln -sfn "$ANTERIOR" "$BASE/current.nuevo"
mv -Tf "$BASE/current.nuevo" "$BASE/current"
sudo /usr/bin/systemctl reload php8.5-fpm
sudo /usr/bin/systemctl restart clt4bp-agente
php "$BASE/current/apps/web/artisan" queue:restart
echo "✔ De nuevo en $ANTERIOR"
