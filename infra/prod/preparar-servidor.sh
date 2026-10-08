#!/usr/bin/env bash
# Prepara un Ubuntu Server 24.04 LTS recién instalado para CLT4BP. Se corre una sola vez, como root,
# desde la copia de infra/prod que subiste al servidor:
#   sudo bash /tmp/clt4bp-prod/preparar-servidor.sh clt4bp.midominio.mx tu@midominio.mx
set -euo pipefail

DOMINIO="${1:?Uso: preparar-servidor.sh <dominio> <correo para avisos del certificado>}"
CORREO="${2:?Falta el correo para los avisos del certificado}"
PHP=8.5
BASE=/srv/clt4bp
AQUI="$(cd "$(dirname "$0")" && pwd)"
export DEBIAN_FRONTEND=noninteractive

echo "== 1. Sistema base, actualizaciones automáticas y cortafuegos"
timedatectl set-timezone UTC
apt-get update
apt-get -y upgrade
apt-get -y install ca-certificates curl gnupg unzip git acl ufw fail2ban unattended-upgrades software-properties-common restic
dpkg-reconfigure -f noninteractive unattended-upgrades
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo "== 2. Nginx, PHP $PHP, Supervisor y certbot"
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get -y install nginx supervisor certbot python3-certbot-nginx \
  "php$PHP-fpm" "php$PHP-cli" "php$PHP-pgsql" "php$PHP-mbstring" "php$PHP-xml" \
  "php$PHP-curl" "php$PHP-zip" "php$PHP-intl" "php$PHP-bcmath"
if ! command -v composer > /dev/null; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm /tmp/composer-setup.php
fi

echo "== 3. Node 24 (para compilar la interfaz en cada despliegue)"
curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
apt-get -y install nodejs

echo "== 4. PostgreSQL 17 con pgvector (repositorio oficial PGDG; puede pedirte Enter)"
apt-get -y install postgresql-common
/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh
apt-get -y install postgresql-17 postgresql-17-pgvector

echo "== 5. Docker (solo para Piston)"
command -v docker > /dev/null || curl -fsSL https://get.docker.com | sh

echo "== 5b. Ollama: modelo local del agente y embeddings del material (ADR 0006)"
# El instalador oficial crea el servicio «ollama», que escucha solo en 127.0.0.1:11434 y usa la GPU si hay
command -v ollama > /dev/null || curl -fsSL https://ollama.com/install.sh | sh
# Flash attention: menos memoria para el contexto. qwen3:8b con 16k de contexto necesita ~8 GB de VRAM libres;
# con menos, usa qwen3:4b (ADR 0006). No comprimir la caché (q8_0): en la prueba volvió lentísima la lectura del prompt
mkdir -p /etc/systemd/system/ollama.service.d
# Un solo modelo cargado: el de generación y bge-m3 no se reparten la VRAM (alternar cuesta unos segundos).
# Con una GPU de 16 GB o más puedes quitar OLLAMA_MAX_LOADED_MODELS y dejar ambos cargados
printf '[Service]\nEnvironment=OLLAMA_FLASH_ATTENTION=1\nEnvironment=OLLAMA_MAX_LOADED_MODELS=1\n' \
  > /etc/systemd/system/ollama.service.d/clt4bp.conf
systemctl daemon-reload
systemctl enable ollama && systemctl restart ollama
# Los de ~8B caben en una GPU de 8 GB; sin GPU corren en CPU (lento). bge-m3 hace falta aunque PROVEEDOR=claude
for modelo in qwen3:8b qwen3:4b bge-m3; do ollama pull "$modelo"; done

echo "== 6. Usuario deploy y carpetas"
id deploy > /dev/null 2>&1 || adduser --disabled-password --gecos "" deploy
mkdir -p "$BASE"/releases "$BASE"/shared/logs \
  "$BASE"/shared/descargas/consola \
  "$BASE"/shared/storage/app/{private,public} \
  "$BASE"/shared/storage/framework/{cache/data,sessions,views} \
  "$BASE"/shared/storage/logs
install -m 755 "$AQUI/desplegar.sh" "$BASE/desplegar.sh"
install -m 755 "$AQUI/regresar.sh" "$BASE/regresar.sh"
chown -R deploy:deploy "$BASE"
sudo -u deploy bash -c 'command -v uv > /dev/null || curl -LsSf https://astral.sh/uv/install.sh | sh'

echo "== 7. Configuración de servicios"
# PHP-FPM: un pool propio que corre como deploy (así web, colas y despliegues comparten dueño de archivos)
install -m 644 "$AQUI/php/clt4bp.conf" "/etc/php/$PHP/fpm/pool.d/clt4bp.conf"
systemctl restart "php$PHP-fpm"
# Nginx
sed "s/DOMINIO/$DOMINIO/g" "$AQUI/nginx/clt4bp.conf" > /etc/nginx/sites-available/clt4bp
ln -sfn /etc/nginx/sites-available/clt4bp /etc/nginx/sites-enabled/clt4bp
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
# Colas (Supervisor: se cargan con «supervisorctl update» después del primer despliegue), agente y Piston
install -m 644 "$AQUI/supervisor/clt4bp.conf" /etc/supervisor/conf.d/clt4bp.conf
install -m 644 "$AQUI/systemd/clt4bp-agente.service" /etc/systemd/system/clt4bp-agente.service
systemctl daemon-reload
systemctl enable clt4bp-agente # arranca después del primer despliegue
mkdir -p /opt/clt4bp-piston
install -m 644 "$AQUI/piston.yml" /opt/clt4bp-piston/compose.yml
docker compose -f /opt/clt4bp-piston/compose.yml up -d
# Trazador (pasos reales de las trazas): se construye desde la versión desplegada, así que se levanta después del
# primer despliegue con «docker compose -f /opt/clt4bp-trazador/compose.yml up -d --build» (docs/despliegue.md)
mkdir -p /opt/clt4bp-trazador
install -m 644 "$AQUI/trazador.yml" /opt/clt4bp-trazador/compose.yml
# El programador de tareas de Laravel, cada minuto
echo "* * * * * deploy cd $BASE/current/apps/web && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/clt4bp
# deploy puede recargar PHP-FPM y reiniciar el agente sin contraseña, y nada más
cat > /etc/sudoers.d/clt4bp <<EOF
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php$PHP-fpm, /usr/bin/systemctl restart clt4bp-agente
EOF
chmod 440 /etc/sudoers.d/clt4bp
visudo -cf /etc/sudoers.d/clt4bp

echo "== 8. Certificado TLS (Let's Encrypt)"
certbot --nginx -d "$DOMINIO" --redirect --agree-tos -m "$CORREO" -n || \
  echo "!! certbot falló: revisa que el DNS de $DOMINIO apunte a este servidor y corre: certbot --nginx -d $DOMINIO --redirect"

echo "✔ Listo. Sigue con la base de datos y el primer despliegue (7.2, paso 3)."
