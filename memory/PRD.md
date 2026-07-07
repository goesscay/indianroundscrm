# Indian Rounds (IR) — Travel Lead Management CRM

## Problem Statement
Enterprise-grade Travel CRM with 3 roles. Auto-generated Lead IDs, controlled supplier email workflow (RSQ) & instant packaged quoting (NRSQ), pipeline, dashboards, master data, audit log, AI itinerary/quote suggestions.

## Architecture
- Frontend: React 19 + Tailwind + shadcn/ui + Recharts + lucide-react
- Backend: FastAPI + Motor (async MongoDB)
- Auth: JWT + Emergent-managed Google OAuth
- AI: Claude Sonnet 4.5 via emergentintegrations
- Email: Resend, plain-text only

## Completed
- JWT + Google auth + 3 seeded users
- Lead CRUD + auto-ID + duplicate detection + destination allocation
- Role-based dashboards, KPIs, charts, calendar view
- Suppliers / Users / Master Data / Activity Log
- AI Assistant
- PWA + Contact Timeline + Follow-up countdowns + Notes
- NRSQ vs RSQ quote-type workflow with `canQuote` gate
- Plain-text supplier RFQ email with editable body + Copy + Reset
- **Supplier Mail Builder & RSQ workflow overhaul** (2026-02):
  - Sales role CANNOT open mail composer or view supplier lists / supplier dashboard
  - Sales RSQ button creates an RSQ request record (POST /api/rsq-requests) — Ops team notified via in-app bell
  - Lead detail shows amber `Awaiting Ops to send mail` pulse badge (animate-pulse) while status=`new`
  - Ops+Admin retain full mail composer; after send → red `Awaiting supplier reply` ping badge (animate-ping) until any reply
  - **New page `/supplier-dashboard`** (Ops+Admin only): 4 lanes — New Requests / Pending Supplier Replies / Responses Received / Completed — with auto-refresh every 30s, card counts, and click-through to lead detail
  - Automatic lane transitions: new → pending_reply (on mail send) → response_received (on supplier reply) → completed (on Quote Sent to Customer / Booked / Closed / Cancelled)
  - Notifications endpoint extended with `rsq_created` alerts for Ops+Admin
  - Sidebar hides Supplier Dashboard + Suppliers links from Sales role
  - Sales-side idempotent RSQ (repeat clicks show "already raised" info toast)

## Backlog
- P1: Customer Quotation PDF + Email Customer flow
- P1: Deeper AI hook — package + pricing suggestion inside NRSQ dialog
- P2: Attach documents to RSQ (Resend attachments)
- P2: Automated overdue reminder scheduler
- P2: Excel/PDF export for dashboard reports
- P2: Refactor `server.py` into routers
- P2: Fix pre-existing Dashboard.jsx nested-anchor hydration warning (tel:/wa.me: inside <Link>)
- P2: Radix Dialog aria-describedby a11y warnings
- P3: Loading indicator on Supplier Dashboard 30s auto-refresh

## Test Credentials
See `/app/memory/test_credentials.md`

## Update — 2026-02 iteration 10
- [x] Backend: new endpoints `POST/GET /api/rsq-requests`, `GET /api/rsq-requests/{lead_id}`; `rsq_requests` collection; hooks in send_supplier_mail, update_supplier_response, webhook inbound reply, lead PATCH to auto-transition lanes; extended notifications with `rsq_created`
- [x] Frontend: `SupplierDashboard.jsx` (4-lane kanban, auto-refresh, cards link to lead detail); `LeadDetail.jsx` splits RSQ button between sales (creates request) vs ops (opens composer); pulse/ping badges for `rsq_status`
- [x] Layout: `nav-supplier-dashboard` added for admin+ops; hidden from sales; sales also loses `nav-suppliers`
- [x] Tested end-to-end via testing_agent_v3 iteration_10 → Backend 13/13 + Frontend 100% for RSQ workflow. `test_rsq_workflow.py` added.
