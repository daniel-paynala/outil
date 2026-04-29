#!/usr/bin/env bash
# Arche — bootstrap d'une instance Ubuntu 26.04 fraîche
# Usage : sudo bash setup.sh
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "✗ Lance avec sudo." >&2
  exit 1
fi

DEPLOY_USER="${DEPLOY_USER:-ubuntu}"
APP_ROOT="/var/www/arche"
APP_USER="${APP_USER:-www-data}"

echo "▶ 1/9  Mise à jour système"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq

echo "▶ 2/9  Swap 2 GB (compense les 1 GB RAM du t3.micro)"
if ! swapon --show | grep -q "/swapfile"; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  if ! grep -q "/swapfile" /etc/fstab; then
    echo "/swapfile none swap sw 0 0" >> /etc/fstab
  fi
  sysctl vm.swappiness=10 >/dev/null
  echo "vm.swappiness=10" > /etc/sysctl.d/99-arche-swap.conf
  echo "  ✓ swap activé"
else
  echo "  ✓ swap déjà présent"
fi

echo "▶ 3/9  Outils de base"
apt-get install -y -qq \
  curl wget git unzip ca-certificates gnupg lsb-release \
  build-essential software-properties-common ufw

echo "▶ 4/9  Nginx"
apt-get install -y -qq nginx
systemctl enable --now nginx

echo "▶ 5/9  PHP 8.4 + extensions Laravel"
add-apt-repository -y ppa:ondrej/php
apt-get update -qq
apt-get install -y -qq \
  php8.4-fpm php8.4-cli php8.4-common \
  php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-bcmath \
  php8.4-curl php8.4-zip php8.4-gd php8.4-intl \
  php8.4-redis php8.4-opcache

# Composer
if ! command -v composer >/dev/null; then
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm composer-setup.php
fi

# PHP-FPM tuning t3.micro
PHP_FPM_POOL="/etc/php/8.4/fpm/pool.d/www.conf"
if [ -f "$PHP_FPM_POOL" ]; then
  sed -i 's/^pm = .*/pm = ondemand/' "$PHP_FPM_POOL"
  sed -i 's/^pm.max_children = .*/pm.max_children = 4/' "$PHP_FPM_POOL"
  sed -i 's/^pm.process_idle_timeout = .*/pm.process_idle_timeout = 10s/' "$PHP_FPM_POOL"
  sed -i 's/^;pm.max_requests = .*/pm.max_requests = 200/' "$PHP_FPM_POOL"
fi

# php.ini : upload limit
PHP_INI="/etc/php/8.4/fpm/php.ini"
if [ -f "$PHP_INI" ]; then
  sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 50M/' "$PHP_INI"
  sed -i 's/^post_max_size = .*/post_max_size = 50M/' "$PHP_INI"
  sed -i 's/^memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
fi

systemctl enable --now php8.4-fpm
systemctl restart php8.4-fpm

echo "▶ 6/9  Node 24 LTS via NodeSource + PM2"
if ! command -v node >/dev/null || ! node --version | grep -q "^v24"; then
  curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
  apt-get install -y -qq nodejs
fi
npm install -g pm2 >/dev/null
pm2 startup systemd -u "$DEPLOY_USER" --hp "/home/$DEPLOY_USER" >/dev/null || true

echo "▶ 7/9  Redis"
apt-get install -y -qq redis-server
sed -i 's/^supervised .*/supervised systemd/' /etc/redis/redis.conf
sed -i 's/^# maxmemory <bytes>/maxmemory 128mb/' /etc/redis/redis.conf
sed -i 's/^# maxmemory-policy noeviction/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf
systemctl enable --now redis-server
systemctl restart redis-server

echo "▶ 8/9  Pare-feu (UFW)"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 'Nginx Full'  # 80 + 443
ufw --force enable

echo "▶ 9/9  Création arborescence app"
mkdir -p "$APP_ROOT"
chown "$DEPLOY_USER:$DEPLOY_USER" "$APP_ROOT"

cat <<EOF

═══════════════════════════════════════════════════════════
✓ Bootstrap terminé.

Étapes suivantes :
  1) Cloner le repo :
       sudo -u $DEPLOY_USER git clone https://github.com/daniel-paynala/outil.git $APP_ROOT/source

  2) Copier la conf nginx :
       sudo cp $APP_ROOT/source/deploy/nginx.conf /etc/nginx/sites-available/arche
       sudo ln -sf /etc/nginx/sites-available/arche /etc/nginx/sites-enabled/arche
       sudo rm -f /etc/nginx/sites-enabled/default
       sudo nginx -t && sudo systemctl reload nginx

  3) Configurer Laravel (.env api) et Next.js (.env.local web)

  4) Déployer une 1ère fois :
       sudo -u $DEPLOY_USER bash $APP_ROOT/source/deploy/deploy.sh

  5) Monter la queue Laravel en service :
       sudo cp $APP_ROOT/source/deploy/arche-queue.service /etc/systemd/system/
       sudo systemctl enable --now arche-queue

═══════════════════════════════════════════════════════════
EOF
