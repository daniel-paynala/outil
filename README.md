# outil

Super-plateforme interne regroupant tous les outils de gestion de projets dev.

## Stack

- **Frontend** : Next.js 15 (App Router, TypeScript, Tailwind) — déployé sur Cloudflare Pages
- **Mobile** : Flutter 3.35 (Riverpod, go_router, Dio) — iOS et Android
- **Backend API** : Laravel 11 (PHP 8.4) — hébergeur classique
- **Base de données** : Supabase (PostgreSQL)
- **Auth** : Supabase Auth (JWT)
- **Recherche** : Meilisearch
- **Queues / cache** : Redis

## Structure

```
outil/
├── api/      # Laravel 11 — API backend
├── web/      # Next.js 15 — frontend
├── mobile/   # Flutter — app iOS / Android (voir mobile/README.md)
└── docker-compose.yml   # Redis + Meilisearch pour le dev local
```

## Modules prévus

1. Projets & tâches (Trello-like)
2. Dashboard GitHub (read-only des commits/PRs)
3. Documentation (pages markdown par projet)
4. Coffre de secrets chiffré
5. Recherche unifiée (tâches + docs + commits + snippets)
6. Dashboard agrégé (flux d'activité)
7. Notifications centralisées
8. Suivi du temps par projet/tâche
9. Agenda / échéances
10. Snippets de code réutilisables
11. ADR (Architectural Decision Records)
12. Stockage fichiers
13. Monitoring / alertes prod

## Démarrage local

```bash
# Services (Redis + Meilisearch)
docker compose up -d

# API
cd api
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve

# Front
cd ../web
npm install
npm run dev

# Mobile
cd ../mobile
cp env/dev.example.json env/dev.json   # puis renseigner SUPABASE_ANON_KEY
flutter pub get
flutter run --dart-define-from-file=env/dev.json
```

## Rôles

- `admin` — accès complet (actuellement : 1 admin principal)
- `member` — accès selon attribution par projet (`project_members`)
