# Real-time chat (Laravel Reverb)

Chat messages are pushed over WebSockets with Laravel Reverb (Pusher protocol). REST remains the source of truth; polling can stay as a fallback.

## How it works
- `ChatController` dispatches events **after** the DB write succeeds. They implement `ShouldBroadcastNow` (immediate, independent of `QUEUE_CONNECTION=sync`). Each event is one HTTP call to Reverb with all recipients as channels.
- Broadcast errors are caught and logged as warnings (`Chat broadcast failed: ...`); they never fail the request. Guzzle timeouts are 1s connect / 2s total (`config/broadcasting.php`). With `BROADCAST_CONNECTION=null|log` nothing is pushed.
- Channel: private `chat.user.{userId}` (Pusher name `private-chat.user.{userId}`), authorised only if the authenticated user id equals `{userId}` (`routes/channels.php`).
- Auth endpoint: `POST /api/broadcasting/auth` (`auth:sanctum` inside the `api` group, so the SPA cookie session + XSRF header work). 401 unauthenticated, 403 for another user's channel.
- Events go to every participant, including the sender (multi-device). Event names have no leading dot in the payload, so with laravel-echo listen with a leading dot: `.listen('.message.sent', ...)`.

| `broadcastAs()` | Payload | Sent when |
|---|---|---|
| `message.sent` | `{conversation_id, message:{id,conversation_id,sender_id,sender_name,body,created_at,deleted}}` | message sent (message = REST JSON) |
| `message.deleted` | `{conversation_id, message_id}` | message soft-deleted |
| `conversation.updated` | `{conversation_id}` | DM first created; group created / renamed / members added or removed (removed members included) / deleted |

Classes: `app/Events/Chat/{MessageSent,MessageDeleted,ConversationUpdated}.php`.

## Environment variables
```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID= REVERB_APP_KEY= REVERB_APP_SECRET=   # random values; the frontend uses the KEY as its public key
REVERB_HOST=127.0.0.1 REVERB_PORT=8085 REVERB_SCHEME=http   # how Laravel reaches Reverb (server side)
REVERB_SERVER_HOST=127.0.0.1 REVERB_SERVER_PORT=8085        # where the Reverb process listens
REVERB_ALLOWED_ORIGINS=   # optional extra origin HOSTNAMES, comma separated
```
Allowed websocket origins always include the hosts of `APP_URL` and `FRONTEND_URL` (Reverb matches hostname only, no port/scheme). The browser connects through the public site (same origin), e.g. `wss://crm.example.com/app/{KEY}`.

## Run locally
```
php artisan reverb:start --host=127.0.0.1 --port=8085   # add --debug to see frames
```
Set the `REVERB_*` values in `.env` (see `.env.example`), keep `SANCTUM_STATEFUL_DOMAINS` and `FRONTEND_URL` set for the SPA origin. Clients: host 127.0.0.1, port 8085, `forceTLS=false`, `authEndpoint=/api/broadcasting/auth` (with credentials + XSRF header).

## Production
- nginx: proxy `/app` (websocket) and `/apps` (HTTP API, optional) to `127.0.0.1:8085`:
  ```
  location /app { proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade";
                  proxy_set_header Host $host; proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
                  proxy_set_header X-Forwarded-Proto $scheme; proxy_read_timeout 120s; proxy_pass http://127.0.0.1:8085; }
  location /apps { proxy_set_header Host $host; proxy_pass http://127.0.0.1:8085; }
  ```
- systemd unit running as the app user, `WorkingDirectory=<api path>`, `ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8085`, `Restart=always`, `LimitNOFILE=10000`.
- Production `.env`: `BROADCAST_CONNECTION=reverb`, fresh random `REVERB_APP_*`, `REVERB_HOST=127.0.0.1 REVERB_PORT=8085 REVERB_SCHEME=http`. Run `php artisan config:cache` after changing it.
- After every deploy: `php artisan reverb:restart` (workers keep old code until restarted).

## Tests
`tests/Feature/ChatBroadcastTest.php` (event dispatch/payloads/channels, channel auth, `/api/broadcasting/auth` over the SPA session, broadcaster failure tolerance).
