# Indian Rounds (IR) — Travel Lead Management CRM

## Problem Statement
Enterprise-grade Travel CRM with 3 roles (Sales / Operations / Admin) + Owner. Auto-generated Lead IDs, smart destination-aware lead form, controlled supplier email workflow, quotation pipeline, dashboards, comprehensive security & user account management.

## Architecture
- Frontend: React 19 + Tailwind + shadcn/ui + Recharts + lucide-react
- Backend: FastAPI + Motor (async MongoDB)
- Auth: JWT (HS256) + bcrypt + Emergent-managed Google OAuth + session invalidation via token_version + per-session jti tracking + failed-login lockout
- AI: Claude Sonnet 4.5 via emergentintegrations
- Email: Resend (plain-text) — supplier RFQ + password reset

## Completed
- Full lead workflow: CRUD, auto-ID, smart form, supplier RFQ (RSQ) + instant NRSQ quoting, supplier dashboard with 4 lanes, follow-ups, contact timeline
- Role-based dashboards, calendar, PWA, AI Assistant, Master Data with Destination→Cities editor
- **Phase 1 Security** (iter 13): password policy, change-password, admin force-reset, account statuses (active/inactive/suspended/locked/password_reset_required), failed-login lockout (5→15min), 1h inactivity auto-logout, server-side session invalidation via token_version, audit log
- **Phase 2A Security** (iter 14):
  - **Forgot Password** with Resend email (plain-text) — cryptographically secure `secrets.token_urlsafe(48)`, 1-hour TTL, one-time use, no user enumeration in `/api/auth/forgot-password` response
  - New pages: `/forgot-password` + `/reset-password/:token` with live policy indicators
  - **Active Sessions** — new `sessions` collection tracks jti + device (parsed UA) + IP + created/last-active. Every login/refresh creates a row; get_current_user validates it (revoked=true → 401 "signed out") and heartbeats last_active_at (throttled to 60s)
  - Backwards compatible: JWTs without jti still work via token_version check
  - `/sessions/mine` shows own devices with `is_current` flag + per-session revoke + "Sign out other devices"
  - Admin `/api/sessions` returns all active sessions enriched with email/name/role; filterable by user_id + active_only
  - Refresh rotates jti (old revoked with reason=rotated_on_refresh)
  - Logout revokes own session (reason=user_logout); admin logout-user revokes all target sessions (reason=admin_kick_all); password change/reset revokes all (reason=password_reset)
  - **Security Log** admin-only page `/security-log` — reuses security_audit_log, filters by event + user_email, CSV export
  - Admin nav gets "Security Log" link (Shield icon)

## Backlog / Roadmap
- **P1 Phase 2B Security**: 2FA (TOTP + email OTP with pyotp/QR); fine-grained per-user permission overrides (`useCan` hook, `require_perm` decorator, hide profit/supplier cost from sales)
- **P1 Multi-Destination Lead ID**: One customer → one main lead ID → N destination sub-refs (awaiting your data-model pick)
- **P1 Customer Quotation PDF + Email Customer**
- **P1 AI package + pricing suggestion inside NRSQ dialog**
- **P2** Refactor server.py (~2200 lines) into routers; Radix Dialog a11y; overdue reminder scheduler
- **P3** Backfill duration/end_date on legacy leads

## Test Credentials
See /app/memory/test_credentials.md

## Update — 2026-02 iteration 14 (Phase 2A Security)
- [x] Backend `/app/backend/auth.py` — jti in JWT + session validation in get_current_user + throttled last_active heartbeat
- [x] Backend `/app/backend/email_service.py` — send_password_reset_email (plain-text)
- [x] Backend `/app/backend/server.py` — /auth/forgot-password + verify-reset-token + reset-password endpoints; /sessions/mine + revoke + admin list; login/refresh/google/logout all track jti; password reset revokes all sessions
- [x] Frontend new pages: ForgotPassword.jsx, ResetPassword.jsx, SecurityLog.jsx
- [x] Frontend Profile.jsx — Active Sessions card with revoke buttons + "Sign out other devices"
- [x] Frontend Login.jsx — Forgot Password link
- [x] Frontend App.js — /forgot-password, /reset-password/:token public routes; /security-log admin route
- [x] Frontend Layout.jsx — admin nav-security-log link
- [x] E2E tested via testing_agent iteration_14
