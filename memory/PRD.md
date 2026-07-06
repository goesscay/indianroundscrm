# Indian Rounds (IR) — Travel Lead Management CRM

## Problem Statement
Enterprise-grade Travel CRM with 3 roles (Sales / Operations / Admin). Auto-generated Lead IDs (IR + MM + running), supplier email dispatch, quote & booking pipeline, dashboards with KPIs & charts, master data, audit log, AI itinerary/quote suggestions.

## Architecture
- Frontend: React 19 + Tailwind + shadcn/ui + Recharts + lucide-react
- Backend: FastAPI + Motor (async MongoDB)
- Auth: JWT (httpOnly cookies + Bearer fallback), bcrypt
- AI: Claude Sonnet 4.5 via emergentintegrations (Emergent LLM key)
- Design: Cabinet Grotesk (Fontshare) + Manrope, brand #106EBE + #0FFCBE

## Personas
- **Sales Executive** — creates leads, sees only own leads, updates status/followups
- **Operations** — sees all leads, sends supplier RFQ emails, updates quotes/pricing
- **Admin** — full access, manages users, master data, audit log

## Completed (2026-02)
- [x] JWT auth + 3 seeded demo users (admin/sales/ops)
- [x] Lead auto-ID generator (IR + MM + 3-digit, resets monthly)
- [x] Sales lead entry form (all specified fields + Save Draft)
- [x] Role-based dashboards (KPIs + charts + leaderboard for admin)
- [x] Lead list with search / status filter / CSV export
- [x] Lead detail with pipeline updates + supplier mail composer (MOCKED send)
- [x] Suppliers CRUD, Users CRUD (admin), Master Data CRUD
- [x] Activity Log (audit trail)
- [x] AI Assistant (itinerary + quote recommendation via Claude Sonnet 4.5)
- [x] WhatsApp click-to-chat
- [x] Sales privacy enforced backend+frontend

## Backlog
- P1: Real email sending (SendGrid/Resend) — currently MOCKED
- P1: PDF export for lead detail
- P2: Dashboard drag-and-drop widget customization
- P2: PWA offline support
- P2: Calendar view for travel dates / follow-ups
- P2: Duplicate lead detection by phone/email
- P3: Google Sheets sync

## Test Credentials
See `/app/memory/test_credentials.md`
