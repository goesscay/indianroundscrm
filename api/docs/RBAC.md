# Role-based access control

Roles and permissions are admin-managed and data-driven.

- **Catalog** (code, single source of truth): `App\Support\Permissions::catalog()` (keys, labels, descriptions, grouped by module).
- **Tables** (migration `2026_10_06_100000`): `roles` (id, name, slug unique, description, is_system) and `role_permissions` (role_id cascade, permission; unique pair). `users.role` is still the role **slug** string (soft link, no FK; widened to 100 chars).
- **System roles**: `admin` (implicit ALL permissions, immutable: cannot be edited/deleted), `sales`, `operations` (undeletable, name/slug/description locked, permissions editable). Custom roles: slug = kebab(name), immutable; delete only with zero assigned users (409).
- **Enforcement**: route middleware `perm:a,b` (ANY-of; admin bypasses; unknown keys deny; 403 `{"detail": ...}`); `role:` alias still works. In controllers use `$user->hasPermission('key')` / `hasAnyPermission(...)`. `leads.edit` implies `leads.edit_limited`. Permissions are resolved from the DB once per request (`Permissions::flush()` runs at the start of each API request and on role edits), so edits take effect immediately.
- **Safety**: last active admin cannot be demoted/disabled/suspended/deleted (409); nobody can change their own role (403); invalid permission keys -> 422; unknown role slug on user create/update -> 400 `Invalid role`.
- **Audit** (ActivityLogger): `create_role`, `update_role`, `delete_role` (entity `role`), `change_user_role` (entity `user`).

## API (all `/api`, `auth:sanctum`)
| Endpoint | Permission |
|---|---|
| GET `/permissions` | roles.manage or users.manage |
| GET `/roles` | roles.manage or users.manage |
| POST `/roles`, PATCH/DELETE `/roles/{id}`, GET `/roles/{id}/users` | roles.manage |
| `/auth/me` and login response | gain `permissions: [keys]` and `role_name` |
| `/users` items | gain `role_name`; list also has `assignable` (can own leads) |

## Route -> permission map
| Route | Permission(s) (any-of) |
|---|---|
| GET /sessions | security.view |
| POST /sessions/{jti}/revoke (others' sessions) | users.manage |
| GET /users | users.view, users.manage |
| POST/PATCH/DELETE /users*, force-reset, unlock, status, logout | users.manage |
| GET /security-audit-log | security.view |
| GET /master, /destination-cities* | master.view, master.manage |
| POST/DELETE /master, PUT /destination-cities/{d} | master.manage |
| GET /allocations | master.view, allocations.manage |
| POST/DELETE /allocations | allocations.manage |
| GET /suppliers | suppliers.view, suppliers.manage |
| POST/DELETE /suppliers | suppliers.manage |
| GET /leads/duplicate-check, POST /leads | leads.create |
| GET /leads/calendar | calendar.view |
| GET /leads, /leads/{id}, follow-ups, notifications | any user (rows scoped: without leads.view_all only own leads) |
| PATCH /leads/{id} | leads.edit (all fields) or leads.edit_limited (sales field set) |
| DELETE /leads/{id} | leads.delete |
| POST /leads/{id}/reassign | leads.reassign (target must have leads.assignable) |
| lead notes GET/POST, DELETE /notes/{id} | leads.notes (deleting others' notes also needs leads.notes_delete_any) |
| lead contact-events | leads.contact_events |
| POST /leads?force=true (duplicate override) | leads.override_duplicate |
| POST /rsq-requests, GET /rsq-requests/{lead} | rsq.request or rsq.manage |
| GET /rsq-requests | rsq.manage |
| GET /lead-suppliers/dashboard/summary | supplier_dashboard.view |
| GET /lead-suppliers/{lead} | suppliers.view, suppliers.manage |
| PATCH /lead-suppliers/{id}, POST .../followup | suppliers.manage |
| POST /supplier-mail/send, GET /supplier-mail/preview/{lead} | supplier_mail.send |
| GET /supplier-mail/{lead} | suppliers.view, supplier_mail.send |
| GET /dashboard/stats | dashboard.view |
| GET /dashboard/leaderboard | dashboard.leaderboard |
| GET /activity, /activity/facets | activity.view |
| RSQ/supplier alerts in /notifications | rsq.manage |
| (none yet) | ai.use is reserved for the upcoming AI assistant panel; no route requires it currently |
| /chat/* | chat.use |
| /chat/groups (create/rename/delete), moderating group messages | chat.create_groups |
| leads.export | reserved (no export endpoint exists yet) |

## Default matrix (reproduces the pre-RBAC behaviour)
X = granted. Admin has every permission implicitly.

| Permission | admin | sales | operations |
|---|:-:|:-:|:-:|
| dashboard.view | X | X | X |
| dashboard.leaderboard | X | | |
| leads.view_all | X | | X |
| leads.create | X | X | X |
| leads.edit | X | | X |
| leads.edit_limited | X | X | X |
| leads.delete | X | | |
| leads.reassign | X | | X |
| leads.assignable | X | X | |
| leads.override_duplicate | X | | |
| leads.notes | X | X | X |
| leads.notes_delete_any | X | | |
| leads.contact_events | X | X | X |
| leads.export | X | | |
| calendar.view | X | X | X |
| rsq.request | X | X | X |
| rsq.manage | X | | X |
| supplier_mail.send | X | | X |
| suppliers.view | X | X | X |
| suppliers.manage | X | | X |
| supplier_dashboard.view | X | | X |
| master.view | X | X | X |
| master.manage | X | | |
| allocations.manage | X | | |
| users.view / users.manage | X | | |
| roles.manage | X | | |
| activity.view | X | | |
| security.view | X | | |
| ai.use | X | X | X |
| chat.use | X | X | X |
| chat.create_groups | X | | |
