# Opt-in pagination

Endpoints: `GET /api/leads`, `GET /api/activity`, `GET /api/security-audit-log`.

- No `page` param: legacy bare array with the legacy default `limit` (unchanged).
- With `page` (>=1; lower values are clamped to 1) the response is
  `{"items": [...], "total": int, "page": int, "per_page": int, "pages": int}`.
- `per_page`: default 25, clamped to 1..200 (non-numeric -> 25). `limit` is ignored in paged mode.
- A page past the end returns `items: []` with correct `total`/`pages`.
- All existing filters/search apply in both modes; `total` is computed after filters and permission
  scoping (e.g. leads without `leads.view_all` count only the user's own leads), via one COUNT query.
- Order is newest-first with `id DESC` tie-break, using DB `LIMIT/OFFSET`. Lead items keep computed
  fields (`profit`, `quote_pending_days`, `stay_cities`, ...); stay cities are eager loaded.
- Permissions are unchanged (`activity.view`, `security.view`).
- Implementation: `App\Support\Paging`.
