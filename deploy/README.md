# Déploiement Arche sur AWS EC2 (t3.micro)

> Ubuntu 26.04 · 1 GB RAM · 8 GB EBS · Paris (eu-west-3) · IP publique sans domaine

## Avant tout — sur la console AWS

1. **Allouer une Elastic IP** et l'attacher à l'instance (sinon l'IP change à chaque redémarrage)
2. **Security Group** : autoriser entrant
   - SSH (22) depuis ton IP perso
   - HTTP (80) depuis 0.0.0.0/0
   - HTTPS (443) depuis 0.0.0.0/0
3. **Étendre l'EBS** à au moins 16 GB (8 GB seront vite trop justes : OS + Node modules + logs)
4. Récupérer la **clé `.pem`** générée et garder la `<TON_IP_PUBLIQUE>`

## Connexion SSH

```bash
ssh -i ~/.ssh/ta-cle.pem ubuntu@<TON_IP_PUBLIQUE>
```

## 1. Bootstrap système (une seule fois)

```bash
# Récupérer le script
curl -O https://raw.githubusercontent.com/daniel-paynala/outil/main/deploy/setup.sh
sudo bash setup.sh
```

Le script installe : Nginx, PHP 8.4 + extensions, Composer, Node 24 + PM2, Redis, ufw, et crée un swap de 2 GB.

## 2. Cloner le repo

```bash
sudo mkdir -p /var/www/arche
sudo chown ubuntu:ubuntu /var/www/arche
cd /var/www/arche
git clone https://github.com/daniel-paynala/outil.git source
```

## 3. Configurer Nginx

```bash
sudo cp /var/www/arche/source/deploy/nginx.conf /etc/nginx/sites-available/arche
sudo ln -sf /etc/nginx/sites-available/arche /etc/nginx/sites-enabled/arche
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

## 4. Configurer les `.env`

```bash
# Laravel
cp /var/www/arche/source/deploy/api.env.example /var/www/arche/source/api/.env
nano /var/www/arche/source/api/.env
# → Remplir mot de passe DB Supabase, JWT secret, anon key, service role key, IP publique

# Générer APP_KEY
cd /var/www/arche/source/api
php artisan key:generate

# Next.js
cp /var/www/arche/source/deploy/web.env.example /var/www/arche/source/web/.env.local
nano /var/www/arche/source/web/.env.local
# → Remplir anon key, NEXT_PUBLIC_API_URL = http://<TON_IP>
```

## 5. Premier déploiement

```bash
sudo bash /var/www/arche/source/deploy/deploy.sh
```

Cela fait : `git pull`, `composer install`, `php artisan migrate`, caches Laravel, `npm ci`, `npm run build`, lance Next.js via PM2, reload Nginx.

## 6. Activer le queue worker (optionnel mais recommandé)

```bash
sudo cp /var/www/arche/source/deploy/arche-queue.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now arche-queue
sudo systemctl status arche-queue   # vérifier
```

## 7. Vérification

```bash
curl http://127.0.0.1/api/health
# → {"status":"ok","service":"Arche","time":"..."}

curl -I http://127.0.0.1/
# → HTTP/1.1 307 (redirection vers /login = OK)
```

Depuis ton navigateur : **http://`<TON_IP_PUBLIQUE>`** → page login Arche.

## Re-déploiements suivants

```bash
ssh ubuntu@<TON_IP>
sudo bash /var/www/arche/source/deploy/deploy.sh
```

## Surveiller la consommation

```bash
free -h            # RAM + swap
htop               # processus en temps réel (apt install htop)
df -h              # disque
pm2 list           # état Next.js
sudo systemctl status php8.4-fpm nginx redis-server arche-queue
```

## Logs utiles

```bash
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/nginx/access.log
sudo tail -f /var/log/arche-queue.log
tail -f /var/www/arche/source/api/storage/logs/laravel.log
pm2 logs arche-web
```

## Limites du t3.micro à surveiller

- **RAM** : si swap > 50% utilisé en permanence → upgrade vers t3.small ou t3.medium
- **CPU credits** : t3.micro est *burstable*. Surveille les CPU credits sur CloudWatch — sous trafic continu tu peux te faire throttler. Solution : t3.medium standard ou bascule en `t3.micro` *unlimited mode* (coût supplémentaire à l'usage).
- **Disque** : 8 GB se rempliront vite (logs, cache, builds Node). Étends à 16-30 GB tôt.
- **Meilisearch désactivé** : la recherche unifiée renvoie toujours un message *"moteur indisponible"*. Pour l'activer, upgrade RAM + suis le doc principal.

## Activer un domaine + HTTPS plus tard

Quand tu auras un nom de domaine :

1. DNS : 2 records A pointant vers `<TON_IP>` :
   - `arche.tondomaine.com`
   - `api.arche.tondomaine.com` (optionnel, on peut rester en path-routing)
2. Adapter `nginx.conf` (changer `server_name _;` → `server_name arche.tondomaine.com;`)
3. Lancer certbot :
   ```bash
   sudo apt install certbot python3-certbot-nginx
   sudo certbot --nginx -d arche.tondomaine.com
   ```
4. Adapter les `.env` : `APP_URL` et `NEXT_PUBLIC_API_URL` en `https://...`

## Troubleshooting express

| Symptôme | À vérifier |
|---|---|
| `502 Bad Gateway` sur le front | `pm2 list` (Next.js up?), `pm2 logs arche-web` |
| `502 Bad Gateway` sur `/api/*` | `sudo systemctl status php8.4-fpm`, logs Laravel |
| `OOM killed` dans dmesg | RAM saturée, augmenter swap ou upgrade instance |
| Migrations qui plantent | DB Supabase pooler injoignable (vérif credentials .env) |
| Login impossible | Cookies bloqués par cross-site, vérifier que NEXT_PUBLIC_API_URL et le browser sont sur la même IP |
