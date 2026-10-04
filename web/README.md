# Indian Rounds Travel CRM — Next.js frontend

Next.js (App Router) + TypeScript (strict) + Tailwind CSS v4 port of the original CRA/craco React frontend.
It talks to the Laravel API (Sanctum SPA cookie authentication) through same-origin `/api/*` calls.

## Requirements

- Node.js 20+
- The Laravel API running (default `http://127.0.0.1:8012`, e.g. `php artisan serve --port=8012` inside `../api`)

## Run

```bash
cd web
npm install
npm run dev -- -p 3000        # http://localhost:3000
```

Production:

```bash
npm run build
npm run start -- -p 3000
```

Checks: `npm run lint`, `npx tsc --noEmit`, `npm run build`.

## Environment variables

| Variable      | Default                 | Used by                                                       |
| ------------- | ----------------------- | ------------------------------------------------------------- |
| `LARAVEL_URL` | `http://127.0.0.1:8012` | `next.config.ts` rewrites (read at server start / build time) |

Copy `.env.example` to `.env.local` to override. No other configuration is needed: the browser only ever talks to
the Next.js origin.

## API proxy and auth

`next.config.ts` defines `rewrites()` that proxy

- `/api/:path*`     -> `${LARAVEL_URL}/api/:path*`
- `/sanctum/:path*` -> `${LARAVEL_URL}/sanctum/:path*`

so cookies are first-party and no CORS setup is required. Authentication is Laravel Sanctum SPA (cookie) auth:

1. Before login the client calls `GET /sanctum/csrf-cookie`.
2. Mutating requests (`POST/PUT/PATCH/DELETE`) send `X-XSRF-TOKEN` (URL-decoded `XSRF-TOKEN` cookie) with
   `credentials: include` (`src/lib/api.ts`). A `419` triggers one automatic CSRF refresh + retry.
3. No bearer tokens are stored in the browser.

Laravel side: the SPA origin must be a stateful Sanctum domain and the session cookie must be same-site, e.g. in
`api/.env`:

```
SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000
SESSION_DOMAIN=localhost
```

Errors are normalised by `formatApiError` which understands both `{"detail": ...}` and Laravel 422
`{message, errors}` bodies.

## Layout

```
src/app/
  layout.tsx                 root layout (AuthProvider, toaster, PWA registration)
  login/                     /login
  forgot-password/           /forgot-password
  reset-password/[token]/    /reset-password/:token
  change-password/           /change-password (auth required, no sidebar)
  (app)/                     authenticated shell (sidebar layout + ProtectedRoute)
    page.tsx                 /  (dashboard, draggable KPI cards)
    leads/ , leads/new/ , leads/[leadId]/
    calendar/ suppliers/ supplier-dashboard/ master/ users/ activity/ security-log/ profile/ ai/
src/components/ui/           shadcn/ui components (TypeScript)
src/components/app/          Layout, ProtectedRoute, StatusPill, SupplierTracker, LeadNotes, ContactTimeline, ...
src/context/AuthContext.tsx  auth state, idle-timeout warning (59 min warn / 60 min logout)
src/lib/api.ts               axios client + Sanctum CSRF handling + formatApiError
src/types/index.ts           TypeScript models (Lead, User, Supplier, ...)
src/constants/testIds/       data-testid constants
```

Role restrictions (same as the old `ProtectedRoute`): `users`, `master`, `activity`, `security-log` are admin-only;
`suppliers` and `supplier-dashboard` are admin/operations; `leads/new` is sales/admin. Restrictions live in the
nested route `layout.tsx` files; they are UI guards only — the API enforces permissions.

## Styling notes

Theme tokens (HSL CSS variables), fonts (Cabinet Grotesk + Manrope via Fontshare) and utility classes
(`label-caps`, `kpi-value`, `font-display`, `grid-borders`) from the old `index.css` / `tailwind.config.js` live in
`src/app/globals.css` using Tailwind v4 `@theme`. The v3 shadow scale and default cursor/placeholder/border-color
behaviour are restored there so the shadcn components render as before.
