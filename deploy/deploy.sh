#!/usr/bin/env bash
# Arche — déploiement (à lancer après chaque pull)
set -euo pipefail

APP_ROOT="/var/www/arche/source"
SUDO=""
[ "$(id -u)" -eq 0 ] && SUDO=""

cd "$APP_ROOT"

echo "▶ git pull"
git pull --ff-only

# ──────── API Laravel ────────
echo ""
echo "▶ API Laravel"
cd "$APP_ROOT/api"

# Composer (sans dev deps en prod)
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Migrations Postgres (Supabase cloud)
php artisan migrate --force

# Caches Laravel
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Permissions storage
chmod -R 775 storage bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Reload PHP-FPM (charge nouveau code OPcache)
PHP_VER="$(cat /etc/arche-php-version 2>/dev/null || echo 8.5)"
sudo systemctl reload "php${PHP_VER}-fpm"

# Redémarrage queue worker (s'il est monté en service)
sudo systemctl restart arche-queue 2>/dev/null || echo "  (arche-queue service pas encore configuré, ok)"

echo "  ✓ API déployée"

# ──────── Web Next.js ────────
echo ""
echo "▶ Web Next.js"
cd "$APP_ROOT/web"

# Install deps + build
npm ci --prefer-offline --no-audit --no-fund
npm run build

# Démarrer / reload via PM2
if pm2 describe arche-web >/dev/null 2>&1; then
  pm2 reload arche-web --update-env
else
  pm2 start npm --name arche-web -- start -- --port 3000
  pm2 save
fi

echo "  ✓ Web déployé"

# ──────── Reload Nginx ────────
echo ""
sudo nginx -t && sudo systemctl reload nginx
echo "✓ Nginx rechargé"

echo ""
echo "═══════════════════════════════════════════"
echo "✓ Déploiement terminé."
curl -sf http://127.0.0.1/api/health && echo ""
echo "═══════════════════════════════════════════"
