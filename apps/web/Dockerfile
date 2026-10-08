# Plataforma web de CLT4BP (Laravel) para Railway (ADR 0008): aula, API de la consola, colas y tareas programadas en
# un solo contenedor con FrankenPHP. El contexto de construcción es la RAÍZ del repositorio (necesita instruments/):
#   docker build -f apps/web/Dockerfile -t clt4bp-web .
FROM dunglas/frankenphp:1-php8.4-bookworm AS base
RUN install-php-extensions pdo_pgsql zip pcntl intl opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /app

# ---------- Construcción: dependencias de PHP y recursos del aula (Vite+) ----------
FROM base AS construccion
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY apps/web/composer.json apps/web/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci
COPY apps/web ./
# El plugin de Wayfinder ejecuta artisan durante el build: necesita un .env con llave (se borra al terminar)
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && cp .env.example .env && php artisan key:generate --force \
    && npm run build \
    && rm -rf node_modules .env storage/logs/* bootstrap/cache/*.php

# ---------- Imagen final ----------
FROM base
COPY --from=construccion /app /app
COPY instruments /instrumentos
COPY apps/web/railway/php.ini "$PHP_INI_DIR/conf.d/zz-clt4bp.ini"
COPY --chmod=755 apps/web/railway/iniciar.sh /usr/local/bin/clt4bp-iniciar
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
EXPOSE 8080
CMD ["clt4bp-iniciar"]
