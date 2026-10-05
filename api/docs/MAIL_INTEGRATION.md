# Supplier mail via Microsoft 365 (Graph)

The CRM sends quote-enquiry emails to suppliers **from the shared mailbox `enquiry@indianrounds.in`** and pulls supplier **replies** back from that mailbox, matches them to the right lead / supplier, stores them and notifies the lead owner. Resend is no longer used (no `RESEND_*` variables, no `/webhooks/resend`).

## Architecture

```
SupplierMailController::send ──> MailSender ──> MailTransport (interface)
                                                  ├─ GraphMailTransport   (MAIL_TRANSPORT=graph)
                                                  └─ LogMailTransport     (default without MS_* values; dev / tests)

schedule:run (every minute) ─> mail:poll-replies ─> ReplyPoller ─> ReplyIngestor
        POST /mail/poll ────────────────────────────┘     │ classify (ReplyClassifier) -> match (ReplyMatcher)
                                                          │ attachments (AttachmentStore, private disk)
                                                          └─> ReplyProcessor (tracker / RSQ / notify / Activity)  <── also used by POST /supplier-replies/{id}/link
```

* `App\Services\Mail\MailTransport`: `sendMessage`, `fetchNewMessages`, `getAttachments`, `markProcessed` (+ `countUnprocessed`, `name`, `isConfigured`).
* `GraphMailTransport`: OAuth2 client-credentials, token cached (`Cache`) until 2 min before expiry and refreshed once on a 401; every call has a timeout; 429 / 5xx / connection errors are retried (`MS_HTTP_RETRIES`, default 3) honouring `Retry-After` (capped by `MS_HTTP_RETRY_MAX_WAIT`, 30 s).
* `LogMailTransport`: writes the mail to the Laravel log, returns synthetic ids, status `simulated_sent`, never touches the network. Selected automatically when the `MS_*` values are empty.
* Tests use `Tests\Support\FakeMailTransport` (bound in the container) or `Http::fake` against the Graph transport.

## Sending

`POST /api/supplier-mail/send` (unchanged path/shape):

1. Creates a **draft** in the shared mailbox (`POST /users/{mailbox}/messages`, `from` = mailbox, to/cc/bcc, plain-text body, `replyTo` = mailbox), re-reads it to get `id`, `conversationId`, `internetMessageId`, then **sends** it (`POST /messages/{id}/send`). If the send fails the draft is deleted again.
2. Stores `graph_message_id`, `internet_message_id`, `conversation_id` on the outbound `mail_history` row (plus `status` = `sent` | `failed` | `simulated_sent`, `provider` = `graph` | `log`, `error`). Failures never return 500: the row is saved with `status='failed'` and the error is in the response.
3. Per-supplier tracker rows (`lead_suppliers`) are created/updated exactly as before and also receive `conversation_id`.
4. The subject always contains the lead id (the default is `Trip Enquiry - IRMH10023 - Vietnam`; if a user edits it away, ` - <lead id>` is appended).

## Reply ingestion (`php artisan mail:poll-replies`)

Scheduled every minute (`withoutOverlapping`). One pass:

* Reads the Inbox of `MS_MAILBOX` (`MS_INBOX_FOLDER`, default `Inbox`) with `receivedDateTime ge <checkpoint - overlap>` ordered ascending, following `@odata.nextLink` (batches of 100, max 20 batches / 50 s per run).
* Checkpoint, last poll time, last success and last error live in the `mail_sync_state` table. The first ever run only looks back `MS_POLL_LOOKBACK_HOURS` (default 48 h), never the whole mailbox. The checkpoint is overlapped by `MS_POLL_OVERLAP_MINUTES` (default 10), so the overlap re-reads a few messages; `supplier_replies.graph_message_id` is UNIQUE and the ingest is idempotent, so re-runs and parallel runs (cache lock + unique key) are safe. A message that errored is retried on the next runs for as long as it is inside the overlap window.
* Messages sent **by the mailbox itself** and drafts are skipped.
* Body: plain text preferred (`Prefer: outlook.body-content-type="text"`). If only HTML arrives it is converted to plain text (scripts/styles dropped, tags stripped, entities decoded). Raw HTML is never stored or rendered.
* Attachments (file attachments only, inline images ignored): allowed = pdf, jpeg/png/gif/webp, doc/docx, xls/xlsx, ppt/pptx, csv, txt, zip (extension AND content type must agree); max 15 MB each and 30 MB per message. Anything else is recorded in `supplier_reply_attachments` with `skipped=true` and a `skip_reason`. Files are stored on the private disk `supplier_replies` (`storage/app/supplier-replies/{yyyy}/{mm}/{random}`, no extension, never public); filenames are sanitised. Download is through `GET /api/supplier-replies/attachments/{id}/download` (attachment disposition + `nosniff`).
* After a message is stored it gets the category `MS_MAIL_CATEGORY_PROCESSED` (default `ERP-Processed`) in the mailbox. It stays in the Inbox for staff. If marking fails it is retried on the next run.

### Matching (first hit wins; `matched_by` is recorded)

| # | Rule | `matched_by` |
|---|------|--------------|
| a | the reply's `conversationId` equals an outbound `mail_history.conversation_id` (or a `lead_suppliers.conversation_id`) | `thread` |
| b | `In-Reply-To` / `References` contains a stored outbound `internet_message_id` | `reference` |
| c | a lead id (`/IR[A-Z]{0,2}\d{4,8}/`) in the subject that exists in `leads` | `subject` |
| d | the sender address equals a `lead_suppliers.supplier_email` and that address is tracked on exactly one lead | `sender` |
| - | manual link from the Supplier Inbox | `manual` |

Once the lead is known the **sender address picks the supplier tracker row** (case-insensitive; falls back to the supplier with that address, then to the single supplier the matched mail was sent to). No lead found -> status `unmatched` (visible in the Supplier Inbox for linking or ignoring).

Classification happens first:

* **auto-reply** (`Auto-Submitted` other than `no`, `X-Auto-Response-Suppress`, `Precedence: bulk|auto_reply|junk`, subjects like "Automatic reply", "Out of office", "Autoreply") -> status `auto_reply`: stored and attached to the lead if matched, **no** notification and **no** tracker change.
* **bounce / NDR** (sender MAILER-DAEMON / postmaster, subjects "Undeliverable", "Delivery Status Notification", "Mail delivery failed", or a `multipart/report` content type) -> status `bounce` when the lead is found (otherwise `unmatched`). The matched tracker row gets `lead_suppliers.delivery_status = 'bounced'` (new nullable column; the tracker `status` itself is left alone; for NDRs from MAILER-DAEMON the tracker is found by the supplier address quoted in the bounce text) and the lead owner is alerted.
* otherwise `matched` (or `unmatched`).

### What a matched real reply does (one shared service: `ReplyProcessor`, used by ingestion and by manual linking; runs exactly once per reply)

* Tracker (`lead_suppliers`): `reply_received=true`, `reply_date`, `last_update`, `inbound_snippet` (first ~300 chars of the new text), status `Email Sent` / `Follow-up Required` -> `Awaiting Quote`. Further states (Quote Received, Accepted, ...) are never downgraded.
* RSQ request: `new` / `pending_reply` -> `response_received` (+ `first_reply_at`).
* Notifications: persistent unread rows in `supplier_reply_reads` for the **lead owner (if active) and every active user holding `rsq.manage`**; the header bell (`GET /api/notifications`) gets items `kind: 'supplier_reply'` (`Reply from <Supplier>`, `<Lead id> · <customer>`, plus `reply_id`) or `kind: 'supplier_bounce'`; a live push is broadcast (below).
* An Activity is created once per reply: type `email`, title `Review reply from <supplier> — <lead id>`, priority medium, due now, assigned to the lead owner if active (otherwise unassigned), `created_by` null.

### Live push

Event `supplier.reply` (`App\Events\SupplierReplyReceived`, `ShouldBroadcastNow`, dispatched inside try/catch) on the private channel `notifications.user.{userId}` (authorised for the user's own id only, `routes/channels.php`). Payload: `{reply:{id, lead_id, customer_name, supplier_name, subject, preview, received_at, kind}}` where `kind` is `supplier_reply` or `supplier_bounce`. See `docs/REALTIME.md` for the Reverb setup.

## Environment variables

```
MAIL_TRANSPORT=graph            # graph | log. Empty/log = log (default when the MS_* values below are empty)
MS_TENANT_ID=                   # Directory (tenant) ID
MS_CLIENT_ID=                   # Application (client) ID
MS_CLIENT_SECRET=               # client secret VALUE
MS_MAILBOX=enquiry@indianrounds.in
MS_INBOX_FOLDER=Inbox
MS_MAIL_CATEGORY_PROCESSED=ERP-Processed
# optional: MS_POLL_OVERLAP_MINUTES=10  MS_POLL_LOOKBACK_HOURS=48  MS_HTTP_TIMEOUT=25  MS_HTTP_RETRIES=3  MS_HTTP_RETRY_MAX_WAIT=30
```

Run `php artisan config:clear` (or `config:cache`) after changing them.

## Entra ID (Azure AD) setup, for the owner

1. Entra admin center -> **App registrations -> New registration** (single tenant). Note the **Application (client) ID** and **Directory (tenant) ID**.
2. **Certificates & secrets -> New client secret**; copy the secret **Value** (shown once) into `MS_CLIENT_SECRET`.
3. **API permissions -> Add a permission -> Microsoft Graph -> Application permissions**: add **`Mail.Send`** and **`Mail.ReadWrite`**, then **Grant admin consent** for the tenant.
4. **Restrict the app to the one mailbox** (otherwise these permissions cover every mailbox in the tenant). In Exchange Online PowerShell:
   ```powershell
   Connect-ExchangeOnline
   New-DistributionGroup -Name "ERP Mail Apps" -Type Security -PrimarySmtpAddress erp-mail-apps@indianrounds.in
   Add-DistributionGroupMember -Identity "ERP Mail Apps" -Member enquiry@indianrounds.in
   New-ApplicationAccessPolicy -AppId <CLIENT_ID> -PolicyScopeGroupId erp-mail-apps@indianrounds.in -AccessRight RestrictAccess -Description "ERP: enquiry mailbox only"
   Test-ApplicationAccessPolicy -Identity enquiry@indianrounds.in -AppId <CLIENT_ID>   # AccessCheckResult: Granted
   Test-ApplicationAccessPolicy -Identity someoneelse@indianrounds.in -AppId <CLIENT_ID> # Denied
   ```
   (The policy can take up to ~1 hour to take effect.)
5. Put the values in `.env`, set `MAIL_TRANSPORT=graph`, then verify:
   ```
   php artisan mail:test-graph
   ```
   It prints `[OK]`/`[FAIL]` per step (token, mailbox access) and finishes with `OK - Microsoft Graph mail is working.` or `FAIL`; exit code 0/1.
6. Make sure the scheduler runs (below), then send a test enquiry and reply to it from a supplier address.

## Scheduler requirement

Replies are only collected if Laravel's scheduler runs every minute. Add one cron entry on the server:

```
* * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
```

(`php artisan schedule:list` must show `mail:poll-replies` every minute.) Admins can also trigger a pass with `POST /api/mail/poll` and see health in `GET /api/mail/status` (`mail.manage`).

## API summary

| Endpoint | Permission |
|----------|------------|
| `POST /supplier-mail/send`, `GET /supplier-mail/preview/{lead}`, `GET /supplier-mail/{lead}` | unchanged |
| `GET /leads/{lead_id}/supplier-replies` | `rsq.request` or `rsq.manage` and the lead visible to the caller |
| `GET /supplier-replies?page&per_page&status&q` (Supplier Inbox, with `counts`) | `rsq.manage` |
| `POST /supplier-replies/{id}/read`, `POST /supplier-replies/read-all?lead_id=` | readers of the reply / own rows only |
| `POST /supplier-replies/{id}/link {lead_id, supplier_id?}`, `POST /supplier-replies/{id}/ignore` | `rsq.manage` (audited as entity `supplier_reply`; bodies are never logged) |
| `GET /supplier-replies/attachments/{id}/download` | same as reading the parent reply |
| `GET /mail/status`, `POST /mail/poll` | `mail.manage` (admin-only by default) |

## Troubleshooting

* **`mail:test-graph` says token request failed (AADSTS7000215 / invalid client)**: wrong `MS_CLIENT_SECRET` (use the secret *Value*, not the ID) or it expired.
* **403 `ErrorAccessDenied` / `ErrorSendAsDenied` on the mailbox**: admin consent missing, or the ApplicationAccessPolicy does not include the mailbox (wait up to an hour after changing it).
* **404 `ErrorInvalidUser` / `MailboxNotFound`**: `MS_MAILBOX` is wrong or not licensed / a shared mailbox without an Exchange Online plan.
* **Sends work but no replies appear**: scheduler not running (see `/mail/status` -> `last_poll_at`), or `MS_INBOX_FOLDER` is not a well-known folder name.
* **Reply shows as unmatched**: the supplier started a new thread without the lead id. Link it from the Supplier Inbox (it then runs the normal tracker / RSQ / notification / Activity flow).
* **`last_error` in `/mail/status`**: the last poll's error (auth, throttling, Graph outage). Throttling (429) is retried automatically; the next minute's run continues from the checkpoint.
* With `MAIL_TRANSPORT=log` (or no `MS_*` values) mails are only written to `storage/logs` and reported as `simulated_sent`.
