# Indian Rounds (IR) — Travel Lead Management CRM

## Problem Statement
Enterprise-grade Travel CRM with 3 roles (Sales / Operations / Admin). Auto-generated Lead IDs (IR + Consultant Code + MM + running), supplier email dispatch (RSQ, plain-text) and instant packaged quoting (NRSQ), pipeline, dashboards, master data, audit log, AI itinerary/quote suggestions.

## Architecture
- Frontend: React 19 + Tailwind + shadcn/ui + Recharts + lucide-react
- Backend: FastAPI + Motor (async MongoDB)
- Auth: JWT (httpOnly cookies + Bearer) + Emergent-managed Google OAuth
- AI: Claude Sonnet 4.5 via emergentintegrations (Emergent LLM key)
- Email: Resend, plain-text bodies (real send when RESEND_API_KEY set, else simulation)

## Completed
- JWT + Google auth + 3 seeded users
- Lead CRUD + auto-ID + mobile validation + duplicate detection + destination allocation
- Role-based dashboards, KPIs, charts, draggable widgets, calendar view
- Suppliers / Users / Master Data / Activity Log
- AI Assistant (itinerary + quote)
- PWA (manifest + SW), Contact Timeline, Follow-up countdowns, Lead Notes
- NRSQ (instant quote) vs RSQ (supplier mail) workflow with `canQuote` role gate (sales/ops/admin)
- **Plain-text Supplier RFQ email** (2026-02):
  - Subject: `Trip Enquiry - {Lead ID} - {Destination}`
  - UPPERCASE section headings (TRIP DETAILS, ACCOMMODATION, GUEST DETAILS, SPECIAL REQUESTS)
  - Auto-populated from lead; blank fields → N/A; Children/Special Requests default to Nil
  - Signature: sri / +91 7305669082 / sales operation team / Indian Rounds
  - Fully editable body Textarea + one-click Copy + Reset to template
  - Backend `send_email()` sends via Resend `text` (no HTML fallback)
  - Multi-supplier send in one click, saved to mail_history with sent_at + status, tracked in lead_suppliers

## Backlog
- P1: Customer Quotation PDF + Email Customer flow
- P1: Deeper AI hook — package + pricing suggestion inside NRSQ dialog
- P2: Attach itinerary/documents to RSQ (Resend attachments)
- P2: Automated overdue reminder scheduler
- P2: Excel/PDF export for dashboard reports
- P2: Refactor `server.py` into routers
- P2: Radix Dialog aria-describedby a11y warnings

## Test Credentials
See `/app/memory/test_credentials.md`

## Update — 2026-02 iteration 10
- [x] Removed HTML supplier RFQ template; replaced with plain-text `render_supplier_rfq_text` in `email_service.py`
- [x] `/supplier-mail/preview` now returns `{text, subject}` (subject prefix changed from "Travel Enquiry" to "Trip Enquiry")
- [x] `LeadDetail.jsx` mail dialog: iframe HTML preview → editable Textarea; added Copy + Reset buttons
- [x] Backend defensively regenerates plain-text body if empty on send
- [x] Verified via curl + screenshot on IRKA07003 — matches user spec exactly
