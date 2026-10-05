# Activities (tasks, calls, meetings, emails, follow-ups)

Table `activities` (migration `2026_10_07_100000`): type (task|call|meeting|email|follow_up), title (200), details, priority (low|medium|high), `due_at`/`completed_at` (timestamptz, UTC), `assigned_to`/`created_by`/`completed_by` (users, null on delete), optional `lead_id` (-> leads.lead_id, set null on delete). Controller: `App\Http\Controllers\Crm\ActivityController`, routes in `routes/api_leads.php`. Permissions: see `docs/RBAC.md`.

## Endpoints (`/api`, auth:sanctum, errors `{"detail": ...}`)
- `GET /activities` filters `q` (title/details/lead name/lead id, ILIKE), `type`, `owner` (all|me|unassigned|user id), `lead_id`, `tab`, `day_start`, `day_end`, `sort` (due_at|created_at|title), `dir`. With `page` returns `{items,total,page,per_page,pages,counts}`, else a bare array (limit 500).
- `GET /activities/calendar?from&to` due_at in `[from,to)`, max 1000, ordered by due_at (done ones included).
- `GET /activities/options`, `POST /activities`, `GET|PATCH|DELETE /activities/{id}`, `GET /leads/{lead_id}/activities`.

## Visibility and rights
Without `activities.view_all` a user sees only activities assigned to them, created by them, or linked to a lead they own (always, whatever `owner` says). Invisible -> 404. Visible but not editable (not creator/assignee and no `activities.manage`) -> 403. Non-managers may only assign to themselves or leave unassigned (403 otherwise); unknown/inactive assignee or invisible lead -> 422.

## Tab semantics (now = request time; day_end = end of caller's local day, default UTC day)
Only open = `completed_at IS NULL` activities are in these tabs:
- `overdue`: due_at < now
- `today`: now <= due_at <= day_end
- `upcoming`: due_at > day_end (and >= now)
- `no_date`: due_at null
- `open` (default): all of the above; `done`: completed; `all`: everything.
`overdue + today + upcoming + no_date = open`. `day_start` is accepted (and defaults day_end = day_start + 1 day - 1s) but the partition itself relies on now and day_end. `counts` ignore `tab` but respect scope, q, type, owner, lead_id.
Ordering: `due_at` NULLS LAST (also for desc), id asc as tie-break.

## Audit
`create_activity`, `update_activity`, `complete_activity`, `delete_activity` (entity `activity`); the `details` text is never logged.
