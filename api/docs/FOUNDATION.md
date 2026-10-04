# Foundation (Backend-A) - what Backend-B can rely on

## Auth model
Sanctum SPA cookie auth (`web` guard). Use `->middleware('auth:sanctum')` on every protected route;
the `api` group already has `statefulApi()` + `App\Http\Middleware\EnforceSession` (token_version, per-session
revocation, 60-min idle timeout, account_status gate). Unauthenticated => 401 `{"detail":"Not authenticated"}`.

Route files: `routes/api.php` requires `routes/api_core.php` (mine). Add your file with
`require __DIR__.'/api_leads.php';` in `routes/api.php`. All routes are under `/api`.

## Role middleware
`->middleware('role:admin,sales')` (alias registered in bootstrap/app.php). `ops` is accepted as alias of `operations`.
Roles stored in `users.role`: `admin | sales | operations` (same values as the old API - NOT "ops").
401 if unauthenticated, 403 `{"detail":"Forbidden: insufficient role"}` otherwise.
`$request->user()->hasRole('admin','operations')`, `->isAdmin()`.

## Errors
All `api/*` errors render `{"detail": "..."}` (validation 422 also includes `errors`). Use `abort(400, 'msg')` for
FastAPI-style HTTPException. 404 model-not-found => `{"detail":"Not found"}`.
`App\Support\Ids::parse($id)` -> int, aborts 400 "Invalid ID format" on non-numeric path ids.

## String ids
`use App\Models\Concerns\StringId;` in a model => `id` serialises as a string. For FK columns add a cast
(`'consultant_id' => 'string'`) when you want them as strings in JSON.

## Helpers
- `App\Support\ActivityLogger::log(?User $user, string $action, string $entity, string|int $entityId = '', ?array $before = null, ?array $after = null)` - writes `activity_log` (port of log_activity).
- `App\Support\SecurityLogger::log(?User $user, string $event, ?Request $request = null, array $extra = [])` - writes `security_audit_log`.
- `App\Support\PasswordPolicy::validate($pw)` - aborts 400 on policy failure.
- Models: `ActivityLog` (table `activity_log`; JSON keys user_id,user_email,user_name,action,entity,entity_id,old_value,new_value,timestamp) - Backend-B can build `GET /activity-log` + facets on it.
- `User::factory()->admin()|sales()|operations()->create()` (default password `Passw0rd!x`).

## Tables (migrations dated 2026_10_04_19*, run before the CRM leads migrations)
- `users`: id, name, email(unique), password(bcrypt, `hashed` cast), role, staff_code, consultant_code(2 chars), mobile, active, account_status, token_version, failed_login_attempts, locked_until, last_login_at, last_logout_at, password_changed_at, remember_token, timestamps. JSON hides password/remember_token/token_version.
  Use `nullOnDelete()` (or cascade deliberately) for FKs to users.id: deleting a user is allowed (`DELETE /users/{id}`).
- `sessions` (Laravel DB session driver), `user_sessions` (per-login tracking: jti, session_id, ip, user_agent, device, last_active_at, revoked, revoked_at, revoke_reason).
- `password_reset_tokens` (token_hash sha256, user_id, email, expires_at, used, used_at, created_at).
- `security_audit_log`, `activity_log`.
- `suppliers` (name, email unique, phone, destination, country) - model `App\Models\Supplier`.
- `master` (category, value; unique pair) - `App\Models\Master`.
- `destination_cities` + `destination_city_items` (ordered cities) - `App\Models\DestinationCity`.
- `allocations` (destination unique, consultant_id FK users) - `App\Models\Allocation`.
- Backend-B owns counters/leads/etc.

## Seeding
`php artisan db:seed` -> `DatabaseSeeder` calls `CoreSeeder` first (owner omnamasiviya73@gmail.com, ADMIN_EMAIL/ADMIN_PASSWORD
(default admin@indianrounds.com/admin123), sales@indianrounds.com / Ir@2026Safe, ops@indianrounds.com / ops123, master data,
4 demo suppliers, destination cities). Add your seeders to the `$this->call([...])` list after `CoreSeeder::class`.

## Config knobs (env)
`SANCTUM_STATEFUL_DOMAINS=localhost:3000`, `SESSION_DOMAIN`, `FRONTEND_URL` (reset links), `IDLE_TIMEOUT_MINUTES` (60),
`LOCKOUT_MAX_ATTEMPTS` (5), `LOCKOUT_MINUTES` (15), `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `MAIL_MAILER` (log by default).

## Tests
`php artisan test`. phpunit.xml uses PostgreSQL schema `testing` inside `indianrounds_laravel` (the DB user cannot
create databases) via `DB_SEARCH_PATH=testing`. Feature tests use `RefreshDatabase`; SPA-style requests need the header
`Referer: http://localhost:3000` (see `Tests\Concerns\SpaRequests`, which also keeps a per-"browser" cookie jar;
note Laravel only sends cookies on JSON requests when `withCredentials()` is set).
Gotchas on this Windows box: (1) two agents running tests at once share the `testing` schema and wipe each other's tables -
use your own schema, e.g. `DB_SEARCH_PATH=testing_a php -d opcache.enable_cli=0 vendor/bin/phpunit` (schema `testing_a` exists;
create others with `CREATE SCHEMA`). (2) CLI opcache is shared across processes with `revalidate_freq=60`, so edited files can
run stale for a minute: use `-d opcache.enable_cli=0`.
The pgsql connection sets session `timezone=UTC` (config/database.php) - required because Laravel writes naive timestamps.
