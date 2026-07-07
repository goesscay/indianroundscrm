# Indian Rounds (IR) — Travel Lead Management CRM

## Problem Statement
Enterprise-grade Travel CRM with 3 roles (Sales / Operations / Admin). Auto-generated Lead IDs (IR + Consultant Code + MM + running), supplier email dispatch (RSQ) and instant packaged quoting (NRSQ), quote & booking pipeline, dashboards with KPIs & charts, master data, audit log, AI itinerary/quote suggestions.

## Architecture
- Frontend: React 19 + Tailwind + shadcn/ui + Recharts + lucide-react
- Backend: FastAPI + Motor (async MongoDB)
- Auth: JWT (httpOnly cookies + Bearer fallback), bcrypt + Emergent-managed Google OAuth
- AI: Claude Sonnet 4.5 via emergentintegrations (Emergent LLM key)
- Email: Resend (real send when RESEND_API_KEY set, else simulation)
- Design: Cabinet Grotesk (Fontshare) + Manrope, brand #106EBE + #0FFCBE

## Personas
- **Sales Executive** — creates leads, sees only own leads, updates status/followups, can generate NRSQ (instant) or trigger RSQ mail
- **Operations** — sees all leads, sends supplier RFQ emails, updates quotes/pricing (Supplier ₹ field)
- **Admin** — full access, manages users, master data, audit log

## Completed
- JWT auth + Google OAuth + 3 seeded demo users
- Lead auto-ID with consultant code (IR + KA/RK + MM + 3-digit)
- Sales lead entry form + Save Draft + dynamic child ages + duplicate detection
- Role-based dashboards (KPIs + charts + leaderboard + draggable widgets)
- Lead list with search / filter / CSV export
- Lead detail with pipeline updates + Reassign + Contact Timeline
- Suppliers, Users, Master Data CRUD
- Activity Log with filters + CSV export
- AI Assistant (itinerary + quote via Claude Sonnet 4.5)
- Resend integration with branded HTML RFQ template
- Supplier Response Tracker with dashboard alerts
- PWA (manifest + service worker)
- Follow-up countdowns + Lead Notes
- Calendar view (travel dates + follow-ups)
- **NRSQ vs RSQ quotation workflow** (2026-02):
  - Fresh leads show a choice card + top-bar CTAs for NRSQ (instant packaged quote) vs RSQ (supplier email flow)
  - NRSQ dialog captures package + selling_amount, sets lead_status='Quote Sent to Customer'
  - RSQ opens supplier mail composer and auto-marks quote_type='rsq' (triggering SupplierTracker)
  - Sales, Operations, and Admin can all generate NRSQ/RSQ quotes (canQuote); Supplier ₹ field remains ops-only (canOps)

## Backlog / Roadmap
- P1: AI-generated travel itinerary suggestions & AI quote recommendation on lead detail (agreed initial scope, wiring exists as AI Assistant page — needs deeper hook into NRSQ package suggestions)
- P1: Customer Quotation PDF generation + "Email Customer" flow with branded PDF
- P2: Automated scheduler for overdue follow-up reminder emails
- P2: Excel/PDF export for Dashboard reports and lead lists
- P2: Refactor `server.py` (1200+ lines) into dedicated routers (leads, users, master, activity, mail)
- P2: Add DialogDescription / aria-describedby to Radix Dialogs (a11y)
- P3: Google Sheets sync

## Test Credentials
See `/app/memory/test_credentials.md`

## Update — 2026-02 iteration 9
- [x] Fixed runtime error `nrsqOpen is not defined` on LeadDetail — added missing `useState` hooks for `nrsqOpen` and `nrsqForm`
- [x] Added `canQuote` role gate (sales+ops+admin) so sales users can now generate NRSQ/RSQ from top-bar buttons (backend already permitted it); `canOps` still gates Supplier ₹ input
- [x] Full end-to-end test via testing agent: backend 4/4 + frontend 11/11 assertions PASS across ops and sales roles
