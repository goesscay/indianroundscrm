# Internal team chat

Direct messages (any two active users) + admin-created groups. Polling fallback plus real-time push via Laravel Reverb (see docs/REALTIME.md).
Routes: `routes/api_chat.php` -> `App\Http\Controllers\Chat\ChatController`. All under `/api/chat`, `auth:sanctum`, errors `{"detail": "..."}`, ids are strings.

## Tables (migration 2026_10_05_200000)
- `chat_conversations`: id, type `direct|group`, name (groups), direct_key unique (`"12:57"` sorted user ids, DMs only), created_by (nullOnDelete), last_message_at, timestamps.
- `chat_participants`: conversation_id (cascade), user_id (cascade), last_read_message_id, created_at; unique(conversation_id,user_id).
- `chat_messages`: conversation_id (cascade), sender_id (nullOnDelete), body, deleted_at (soft delete), created_at; index(conversation_id,id).

## Endpoints
| Method / path | Notes |
|---|---|
| GET `/users` | active users except me `[{id,name,role}]` |
| GET `/conversations` | mine, newest activity first; `name` of a DM = other user's name; includes `members`, `last_message`, `unread_count`, `updated_at` |
| POST `/conversations/direct` `{user_id}` | get-or-create (idempotent via direct_key); 400 self, 404 missing/inactive |
| POST `/groups` (admin) `{name 1-80, member_ids[]}` | creator auto-added; >=1 other active user |
| PATCH `/groups/{id}` (admin) `{name?, add_member_ids?, remove_member_ids?}` | group must stay >= 1 member (422); 400 if not a group |
| DELETE `/groups/{id}` (admin) | deletes group + messages |
| GET `/conversations/{id}/messages?after_id&before_id&limit` | `{messages,has_more}`, ascending; limit default 50, max 100; deleted -> body "" + `deleted:true` |
| POST `/conversations/{id}/messages` `{body}` | trimmed, 1-4000 chars; throttle `chat-send` 60/min/user (429); marks conversation read for sender |
| DELETE `/messages/{id}` | soft delete; sender, or an admin who is a participant of that *group*; else 403 |
| POST `/conversations/{id}/read` `{message_id?}` | default latest; never moves backwards; returns `{ok, unread_count}` |
| GET `/unread-count` | `{total}` single join query (polled every ~15s) |

## Rules
- Non-participants get 404 (existence not leaked). Admins have no special read access to other people's DMs/groups; an admin may manage (rename/members/delete) any group but sees no messages through it unless a member.
- Removed members lose access immediately. Members added later start with existing history marked read.
- Unread = non-deleted messages from others with id > my `last_read_message_id`.
- Sending in a DM whose other user is inactive -> 403. Inactive users cannot be DM'd or added to groups.
- Group create/rename/member changes/delete are logged to `activity_log` (entity `chat_group`); message bodies are never logged.
