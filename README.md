# Indian Rounds Travel ERP

Lead, supplier-quote (RSQ) and sales-team ERP for a travel agency.

| Layer | Stack |
| --- | --- |
| Frontend (`web/`) | Next.js (App Router) + TypeScript + Tailwind CSS |
| Backend (`api/`) | Laravel 12, PHP 8.3+, REST API |
| Database | PostgreSQL |
| Auth | Laravel Sanctum (SPA cookie + CSRF) |

The browser only talks to the Next.js origin; `/api/*` and `/sanctum/*` are proxied to Laravel
(see `web/next.config.ts`), so cookies are same-origin.

## Prerequisites

PHP 8.3+ (with `pdo_pgsql`), Composer, Node.js 20+, PostgreSQL 14+.

## Setup

```bash
# 1. Database (example)
psql -U postgres -c "CREATE USER indianrounds WITH PASSWORD 'change-me'"
psql -U postgres -c "CREATE DATABASE indianrounds_laravel OWNER indianrounds"

# 2. API
cd api
composer install
cp .env.example .env            # then set DB_* and the values below
php artisan key:generate
php artisan migrate --seed

# 3. Web
cd ../web
npm install
```

Add to `api/.env`:

```
DB_CONNECTION=pgsql
DB_DATABASE=indianrounds_laravel
DB_USERNAME=indianrounds
DB_PASSWORD=change-me
FRONTEND_URL=http://localhost:3000
SANCTUM_STATEFUL_DOMAINS=localhost:3000
SESSION_DOMAIN=localhost
SESSION_DRIVER=database
# Optional
ANTHROPIC_API_KEY=              # AI itinerary / quote assistant
RESEND_API_KEY=                 # supplier mail via Resend (otherwise Laravel's mailer, `log` by default)
RESEND_FROM=
ADMIN_EMAIL=admin@indianrounds.com
ADMIN_PASSWORD=admin123         # change for any non-local use
```

## Run

```bash
cd api && php artisan serve --port=8012
cd web && npm run dev -- -p 3000      # http://localhost:3000
```

Set `LARAVEL_URL` (in `web/.env.local`) if the API is not at `http://127.0.0.1:8012`.

Seeded demo accounts (local use only): `admin@indianrounds.com` / `admin123`,
`sales@indianrounds.com` / `Ir@2026Safe`, `ops@indianrounds.com` / `ops123`.

## Tests and checks

```bash
cd api && php artisan test
cd web && npm run lint && npx tsc --noEmit && npm run build
```

More detail: [`web/README.md`](web/README.md), [`api/docs/FOUNDATION.md`](api/docs/FOUNDATION.md).
