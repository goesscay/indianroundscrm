# Indian Rounds (IR) — Travel Lead Management CRM

## Problem Statement
Enterprise-grade Travel CRM with 3 roles (Sales / Operations / Admin) + Owner. Auto-generated Lead IDs, smart destination-aware lead form, controlled supplier email workflow, quotation pipeline, dashboards, comprehensive security & user account management, master data, audit log, AI itinerary suggestions.

## Architecture
- Frontend: React 19 + Tailwind + shadcn/ui + Recharts + lucide-react
- Backend: FastAPI + Motor (async MongoDB)
- Auth: JWT (HS256) + bcrypt + Emergent-managed Google OAuth + session invalidation via token_version + failed-login lockout
- AI: Claude Sonnet 4.5 via emergentintegrations
- Email: Resend, plain-text only

## Completed
- JWT + Google auth + 4 seeded users (owner + admin/sales/ops demos)
- Lead CRUD + auto-ID + duplicate detection + destination allocation
- Role-based dashboards + KPIs + charts + calendar view
- Suppliers / Users / Master Data / Activity Log
- AI Assistant + PWA + Contact Timeline + Follow-up countdowns + Notes
- NRSQ vs RSQ quote-type workflow with `canQuote` role gate
- Plain-text Supplier RFQ email (Trip Enquiry subject, uppercase sections, N/A defaults, editable + Copy + Reset)
- Supplier Mail Builder & RSQ workflow: sales raises request → Ops sends → blinking indicators → auto lane transitions
- /supplier-dashboard (Ops+Admin): 4-lane kanban + auto-refresh + notification bell
- Smart Lead Form: auto Trip End Date + `3N/4D` duration + smart destination→cities picker (16 preseeded) + multi-city stay + passport Yes/No with 6 preset reasons + custom entry; RSQ email auto-populates
- **Phase 1 Security & User Account Management** (2026-02):
  - Password policy: min 8 + upper + lower + digit + special (Ir@2026Safe format)
  - Change Password endpoint + `/profile` page + `/change-password` page (forced + non-forced)
  - Admin Force Password Reset: bumps token_version + sets status='password_reset_required'; user forced to change on next login (amber banner, no current-password field)
  - Account Status enum: active / inactive / suspended / locked / password_reset_required — enforced on every request
  - Failed-login lockout: 5 attempts → 15min auto-unlock OR admin unlock; HTTP 423
  - Auto-logout: 1h inactivity with 60s warning modal (Stay Logged In / Logout); backend token TTL 1h; refresh endpoint rotates cookies
  - Server-side session invalidation via `token_version` in JWT payload; bumped on password change, admin force-reset, admin logout-user, status change to inactive/suspended
  - Security Audit Log collection: login_success/login_failed/login_locked/logout/password_changed/admin_force_reset/admin_status_change/admin_session_terminated/admin_unlock with IP + user_agent + timestamp
  - Admin Users page controls: force-reset, unlock, terminate-sessions, status dropdown (Active/Inactive/Suspended); Locked users show Unlock button
  - Owner account (real developer): omnamasiviya73@gmail.com seeded as admin/active
  - Legacy demo passwords (admin123/ops123) preserved; sales updated to Ir@2026Safe during testing

## Backlog / Roadmap
- **P1 Phase 2 Security**: Forgot Password (Resend email link); Active Sessions dashboard for admin; multi-device policy toggle; 2FA (TOTP + email OTP); fine-grained per-user permission checkboxes; full Login History browser
- **P1 Multi-Destination Lead ID**: One customer → one main lead ID → N destination sub-refs (IRKA07001-01 Thailand + IRKA07001-02 Singapore + …). Awaiting user pick on data-model split (embedded vs new collection).
- **P1 Customer Quotation PDF + Email Customer** flow
- **P1 AI package + pricing suggestion inside NRSQ dialog**
- **P2** Attach documents to RSQ (Resend attachments); overdue reminder scheduler; Excel/PDF export for dashboards
- **P2** Refactor server.py (1890 lines) into routers/{auth,users,leads,suppliers,security}.py
- **P2** Radix Dialog aria-describedby a11y warnings; Dashboard.jsx nested-anchor hydration warning
- **P3** Backfill duration/end_date on legacy leads

## Test Credentials
See /app/memory/test_credentials.md (sales password updated to `Ir@2026Safe`)

## Update — 2026-02 iteration 13
- [x] Backend `/app/backend/auth.py` rewrite: password policy, token_version, lockout constants, owner seeding
- [x] Backend `/app/backend/server.py`: /auth/login with lockout+status, /auth/refresh, /auth/change-password, /auth/logout with audit; /users/{uid}/force-reset,unlock,status,logout admin endpoints; /security-audit-log; log_security helper; /auth/me includes account_status
- [x] Frontend AuthContext: 1h inactivity timer + warn modal + refresh + password_reset_required routing
- [x] Frontend `/profile` (Profile.jsx) + `/change-password` (ChangePassword.jsx with policy indicators)
- [x] Frontend Users.jsx admin controls: force-reset, unlock, terminate-sessions, status dropdown
- [x] Frontend Layout.jsx: user-name area is now Link to /profile (data-testid='profile-link')
- [x] Testing agent iteration_13 fixed 4 critical bugs: BrowserRouter/AuthProvider ordering, hardcoded forced prop in App.js, Login.jsx redirect fight, profile-link missing. Backend 19/19 pass, frontend 100% verified
