#!/usr/bin/env bash
# Despliega una etiqueta de git en el servidor. Como el usuario deploy:
#   /srv/clt4bp/desplegar.sh v1.0.0
# Cada versión queda en releases/<fecha>-<etiqueta>; «current» apunta a la vigente. Las migraciones deben ser
# compatibles con la versión anterior (solo agregar), porque corren antes de cambiar el enlace.
set -euo pipefail

VERSION="${1:?Uso: desplegar.sh <etiqueta, p. ej. v1.0.0>}"
BASE=/srv/clt4bp
DESTINO="$BASE/releases/$(date -u +%Y%m%d-%H%M%S)-$VERSION"
export PATH="$HOME/.local/bin:$PATH"

echo "== Código de $VERSION"
git -C "$BASE/repo" remote update --prune
mkdir -p "$DESTINO"
git -C "$BASE/repo" archive "$VERSION" | tar -x -C "$DESTINO"

echo "== Web"
cd "$DESTINO/apps/web"
rm -rf storage
ln -s "$BASE/shared/storage" storage
ln -s "$BASE/shared/web.env" .env
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force # solo la primera vez
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules
php artisan migrate --force
php artisan optimize

echo "== Agente"
cd "$DESTINO/services/agent"
uv sync --frozen --no-dev

echo "== Cambio de versión"
ln -sfn "$DESTINO" "$BASE/current.nuevo"
mv -Tf "$BASE/current.nuevo" "$BASE/current"
sudo /usr/bin/systemctl reload php8.5-fpm
sudo /usr/bin/systemctl restart clt4bp-agente
php "$BASE/current/apps/web/artisan" queue:restart # los trabajadores terminan su trabajo y vuelven con el código nuevo

# Se conservan las 5 versiones más recientes
find "$BASE/releases" -mindepth 1 -maxdepth 1 -type d | sort | head -n -5 | xargs -r rm -rf

echo "== Comprobación"
URL=$(grep '^APP_URL=' "$BASE/shared/web.env" | cut -d= -f2-)
sleep 3
curl -fsS "$URL/up" > /dev/null && echo "✔ web"
curl -fsS http://127.0.0.1:8100/salud > /dev/null && echo "✔ agente"
echo "✔ $VERSION desplegada en $DESTINO"
