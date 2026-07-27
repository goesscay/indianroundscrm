# Indian Rounds (IR) — Travel Lead Management CRM

## Problem Statement
Enterprise-grade Travel CRM with 3 roles (Sales / Operations / Admin). Auto-generated Lead IDs, smart destination-aware lead form, controlled supplier email workflow (RSQ) & instant packaged quoting (NRSQ), dashboards, master data, audit log, AI itinerary/quote suggestions.

## Architecture
- Frontend: React 19 + Tailwind + shadcn/ui + Recharts + lucide-react
- Backend: FastAPI + Motor (async MongoDB)
- Auth: JWT + Emergent-managed Google OAuth
- AI: Claude Sonnet 4.5 via emergentintegrations
- Email: Resend, plain-text only

## Completed
- JWT + Google auth + 3 seeded users
- Lead CRUD + auto-ID + duplicate detection + destination allocation
- Role-based dashboards + KPIs + charts + calendar view
- Suppliers / Users / Master Data / Activity Log
- AI Assistant + PWA + Contact Timeline + Follow-up countdowns + Notes
- NRSQ vs RSQ quote-type workflow with `canQuote` role gate (sales/ops/admin)
- Plain-text Supplier RFQ email (Trip Enquiry subject; UPPERCASE headings; N/A defaults; editable + Copy + Reset)
- Supplier Mail Builder & RSQ workflow overhaul: sales raises RSQ request → Ops sends mail → blinking pulse/ping indicators → auto lane transitions
- `/supplier-dashboard` (Ops+Admin): 4-lane kanban with auto-refresh, notification bell alerts
- **Smart Lead Form** (2026-02):
  - Travel Start Date + No.of Days → auto-calc Trip End Date + `3N / 4D` duration label (nights = days-1)
  - Destination → smart city load with 16 preseeded destinations (Thailand, Bali, Dubai, Singapore, Malaysia, Sri Lanka, Vietnam, Maldives, Europe, Kashmir, Kerala, Goa, Andaman, Rajasthan, Himachal, Uttarakhand)
  - Multi-city stay selection with optional per-city nights + `+ Other / Manual Entry` free-text
  - Live "Bangkok 2N + Pattaya 1N" stay summary + non-blocking mismatch warning
  - Passport Yes / No with 6 preset reasons (Not Applied / In Progress / Expired / Renewal / Will Apply Later / Other) + custom entry
  - All new fields auto-populate the RSQ plain-text email (TRIP DETAILS with Trip End + Duration lines, STAY DETAILS block, PASSPORT block)
  - Admin can add / remove / reorder cities per destination via Master Data → Destination → Cities Mapping editor
  - Backend endpoints: GET /api/destination-cities, GET /:destination, PUT /:destination (admin)
  - Sales redirect to /leads (instead of forbidden lead detail) when auto-allocated to another consultant

## Backlog / Roadmap
- P1: Customer Quotation PDF + Email Customer flow
- P1: Deeper AI hook — package + pricing suggestion inside NRSQ dialog
- P2: Attach documents to RSQ (Resend attachments)
- P2: Automated overdue reminder scheduler
- P2: Excel/PDF export for dashboard reports
- P2: Refactor server.py (~1600 lines) into routers
- P2: Fix pre-existing Dashboard.jsx nested-anchor hydration warning
- P2: Radix Dialog aria-describedby a11y warnings
- P3: One-time migration to backfill duration/end_date for legacy leads (currently shows "—")

## Test Credentials
See /app/memory/test_credentials.md

## Update — 2026-02 iteration 11
- [x] Backend: LeadCreate/LeadUpdate extended (duration_days, stay_cities, passport_available, passport_reason); _compute_trip_fields auto-derives trip_end_date, duration_label, stay_summary; destination-cities endpoints + seed of 16 destinations
- [x] Backend: render_supplier_rfq_text now includes Trip End Date, Duration, STAY DETAILS block, PASSPORT block
- [x] Frontend: LeadForm.jsx full rewrite with smart destination-aware city picker, per-city nights, custom entry, passport Y/N with reason flow, live duration calc
- [x] Frontend: LeadDetail.jsx KV rows for Trip End Date, Duration, Stay Cities, Passport (visible to Sales + Ops)
- [x] Frontend: MasterData.jsx adds DestinationCitiesEditor (Admin only)
- [x] UX fix: Sales redirected to /leads (not lead detail) when created lead is auto-allocated to another consultant
- [x] Tested via testing_agent iteration_11 → Backend 9/9 + Frontend 100% pass; `test_smart_lead_form.py` added
