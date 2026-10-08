#!/usr/bin/env bash
# Arranque del contenedor de CLT4BP en Railway (ADR 0008): prepara Laravel y corre la web, las dos colas y el
# programador de tareas. Las colas se relanzan solas al cumplir su hora (--max-time); si la web cae, el contenedor
# termina y Railway lo reinicia.
set -euo pipefail
cd /app

# El volumen de Railway se monta vacío sobre storage/: la estructura que Laravel espera se recrea en cada arranque
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

# Las migraciones solo agregan (docs/despliegue.md): aplicarlas al arrancar es seguro y deja la base al día
php artisan migrate --force
php artisan storage:link >/dev/null 2>&1 || true

# Datos base (roles, catálogo de efectos, administrador inicial e instrumentos). Todo es idempotente: se puede dejar
# encendido, pero basta con el primer arranque (CLT4BP_SEMBRAR=si y después quitarlo)
if [ "${CLT4BP_SEMBRAR:-no}" = "si" ]; then
    php artisan db:seed --force
    for instrumento in mslq cs paas; do
        php artisan instrumentos:cargar "/instrumentos/${instrumento}.json"
    done
fi

php artisan optimize

# Calificación de envíos, análisis y avisos
(while true; do php artisan queue:work database --queue=default --sleep=3 --max-time=3600; sleep 1; done) &
# Agente: generaciones y material (por el relevo, esperan al agente local; ver AGENTE_MODO)
(while true; do php artisan queue:work database --queue=agente --sleep=3 --max-time=3600; sleep 1; done) &
# Tareas programadas: avisos de apertura, revisión de operación, poda del relevo
(while true; do php artisan schedule:work; sleep 5; done) &

exec frankenphp php-server --listen ":${PORT:-8080}" --root /app/public
