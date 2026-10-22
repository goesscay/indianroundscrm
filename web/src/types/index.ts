// TypeScript models mirroring the Pydantic models / JSON shapes of the API contract (backend/server.py).

/** Role slug. The built-in roles are "super_admin" (top-most) | "admin" | "sales" | "operations"; admins can create more. */
export type Role = string;

export type AccountStatus = "active" | "inactive" | "locked" | "suspended" | "password_reset_required";

export interface User {
  id: string;
  email: string;
  name: string;
  role: Role;
  role_name?: string;
  /** Permission keys granted to the user's role (admin receives every key). */
  permissions?: string[];
  staff_code?: string;
  consultant_code?: string;
  /** The destination this person works: pre-filled on their new leads, quotes and deals and in their lists. */
  destinations?: string[];
  /** The first of `destinations`. */
  default_destination?: string | null;
  mobile?: string;
  active?: boolean;
  account_status?: AccountStatus | string;
  password_reset_required?: boolean;
  last_login_at?: string | null;
  created_at?: string;
  [extra: string]: unknown;
}

export interface UserCreate {
  email: string;
  password: string;
  name: string;
  role: Role;
  staff_code?: string;
  consultant_code?: string;
  destinations?: string[];
}

export interface DestinationAllocation {
  id: string;
  destination: string;
  consultant_id: string;
  consultant_name?: string;
  consultant_code?: string;
}

export interface StayCity {
  city: string;
  nights?: number | null;
}

export interface LeadCreate {
  customer_name: string;
  phone: string;
  email: string;
  lead_date: string;
  travel_date: string;
  duration_days: number | null;
  destination: string;
  hotel_category: string;
  meal_plan: string;
  package_type: string;
  budget: string;
  adults: number;
  children: number;
  child_ages: string;
  rooms: number;
  room_type: string;
  departure_from: string;
  special_requests: string;
  lead_source: string;
  stay_cities: StayCity[];
  passport_available: boolean | null;
  passport_reason: string;
}

export interface Lead extends Partial<LeadCreate> {
  id?: string;
  customer_id?: string | null;
  deal_id?: string | null;
  converted_at?: string | null;
  archived_at?: string | null;
  /** The soonest open Follow-up activity of the lead (an ISO time), if it has one. */
  next_follow_up?: string | null;
  lost_reason?: string | null;
  lead_id: string;
  customer_name: string;
  phone: string;
  destination: string;
  staff_id?: string;
  staff_name?: string;
  staff_code?: string;
  lead_status: string;
  mail_status?: string;
  supplier_mail_date?: string | null;
  remarks?: string;
  supplier_quote_amount?: number;
  selling_amount?: number;
  quote_received?: boolean;
  quote_received_date?: string | null;
  quote_sent_to_customer?: boolean;
  quote_sent_date?: string | null;
  booking_status?: string;
  booking_amount_received?: number;
  follow_up_date?: string | null;
  follow_up_at?: string | null;
  payment_status?: string;
  quote_type?: string;
  rsq_status?: string;
  package_selected?: string;
  profit?: number;
  duration_label?: string;
  stay_summary?: string;
  trip_end_date?: string;
  quote_pending_days?: number | null;
  created_at?: string;
  updated_at?: string;
  updated_by?: string;
}

export type LeadUpdate = Partial<Omit<Lead, "lead_id" | "id">>;

export interface DuplicateInfo {
  lead_id?: string;
  customer_name?: string;
  staff_name?: string;
  destination?: string;
  lead_status?: string;
  follow_up_date?: string | null;
}

export interface Supplier {
  id: string;
  name: string;
  email: string;
  phone?: string;
  destination?: string;
  country?: string;
  portal_enabled?: boolean;
  group_ids?: string[];
}

export interface SupplierGroup {
  id: string;
  name: string;
  destination?: string;
  supplier_ids: string[];
}

export interface SupplierCreate {
  name: string;
  email: string;
  phone: string;
  destination: string;
  country: string;
  portal_enabled?: boolean;
  group_ids?: string[];
}

export interface PortalUser {
  id: string;
  name: string;
  email: string;
  active: boolean;
  last_login_at: string | null;
}

export interface MasterItem {
  id: string;
  category: string;
  value: string;
}

export type DestinationCities = Record<string, string[]>;

export interface LeadNote {
  id: string;
  lead_id: string;
  note: string;
  created_by: string;
  created_by_id: string;
  created_at: string;
}

export interface ContactEvent {
  id: string;
  lead_id: string;
  kind: string;
  channel: string;
  target: string;
  note: string;
  user_id: string;
  user_name: string;
  timestamp: string;
}

export interface LeadSupplier {
  id: string;
  lead_id: string;
  supplier_id?: string;
  supplier_name: string;
  supplier_email?: string;
  status: string;
  sent_at?: string | null;
  message_id?: string;
  reply_received?: boolean;
  first_reply_at?: string | null;
  quote_received?: boolean;
  quote_amount?: number | null;
  currency?: string;
  hotel_offered?: string;
  flight_included?: boolean;
  transfer_included?: boolean;
  activities_included?: string;
  valid_until?: string;
  remarks?: string;
  followups?: number;
  sequence?: number;
  hours_since_email?: number | null;
  /** supplier portal */
  portal?: boolean;
  messages?: number;
  respond_by?: string | null;
  portal_reminders?: number;
  quote_files?: { id: string; filename: string }[];
  [extra: string]: unknown;
}

export interface SupplierMailHistory {
  id: string;
  lead_id: string;
  subject?: string;
  to_suppliers?: string[];
  status?: string;
  provider?: string;
  message_id?: string;
  sent_at?: string;
  [extra: string]: unknown;
}

export interface SupplierMailPreview {
  subject: string;
  body: string;
  [extra: string]: unknown;
}

export interface SupplierMailSendResult {
  provider?: string;
  simulated?: boolean;
  sent?: number;
  [extra: string]: unknown;
}

export interface RsqRequest {
  id?: string;
  lead_id: string;
  status: string;
  note?: string;
  customer_name?: string;
  destination?: string;
  staff_name?: string;
  travel_date?: string;
  lead_status?: string;
  requested_at?: string;
  requested_by_name?: string;
  sent_at?: string | null;
  first_reply_at?: string | null;
  completed_at?: string | null;
  supplier_count?: number;
  reply_count?: number;
  created_at?: string;
  updated_at?: string;
}

export type RsqLaneKey = "new" | "pending_reply" | "response_received" | "completed";

/** GET /rsq-requests returns the requests grouped into dashboard lanes. */
export type RsqLanes = Record<RsqLaneKey, RsqRequest[]>;

export interface Notification {
  icon?: string;
  title: string;
  detail?: string;
  lead_id?: string;
  /** 'supplier_reply' | 'supplier_bounce' | other server-defined kinds. */
  kind?: string;
  reply_id?: string;
  [extra: string]: unknown;
}

export interface ActivityEntry {
  id: string;
  timestamp: string;
  user_name?: string;
  user_email?: string;
  action: string;
  entity?: string;
  entity_id?: string;
  new_value?: unknown;
  old_value?: unknown;
  [extra: string]: unknown;
}

export interface ActivityFacets {
  actions: string[];
  entities: string[];
  users?: string[];
  [extra: string]: unknown;
}

export interface SecurityLogEntry {
  id: string;
  timestamp: string;
  event: string;
  user_email?: string;
  user_name?: string;
  ip?: string;
  user_agent?: string;
  extra?: unknown;
  [extra: string]: unknown;
}

export interface SessionInfo {
  jti: string;
  device?: string;
  ip?: string;
  last_active_at?: string;
  is_current?: boolean;
  [extra: string]: unknown;
}

export interface CalendarEvent {
  lead_id: string;
  date: string;
  kind: string;
  customer_name?: string;
  destination?: string;
  lead_status?: string;
  [extra: string]: unknown;
}

export interface DashboardStats {
  total_leads: number;
  today_leads: number;
  booked: number;
  quote_pending: number;
  revenue: number;
  profit: number;
  followups_today: number;
  cancelled: number;
  status_counts?: Record<string, number>;
  monthly?: Array<{ month: string; leads: number; revenue: number; profit: number }>;
  top_destinations?: Array<{ destination: string; count: number }>;
  [extra: string]: unknown;
}

export interface LeaderboardRow {
  staff_id: string;
  staff_name?: string;
  leads: number;
  booked: number;
  conversion_rate: number;
  revenue: number;
}

export interface SupplierSummary {
  sent_today: number;
  awaiting_replies: number;
  quotes_received_today: number;
  overdue: number;
  need_followup: number;
  avg_response_hours: number;
  suppliers_no_email: number;
  top_fastest?: Array<{ name: string; avg_hours: number; replies: number }>;
  [extra: string]: unknown;
}

export interface UpcomingFollowup {
  lead_id: string;
  customer_name?: string;
  phone?: string;
  destination?: string;
  lead_status?: string;
  follow_up_at: string;
  overdue?: boolean;
}

// ---- Team chat ----
export interface ChatUser {
  id: string;
  name: string;
  role: Role | string;
}

export interface ChatLastMessage {
  id: string;
  body: string;
  sender_id: string;
  sender_name: string;
  created_at: string;
  deleted: boolean;
  kind?: "text" | "sticker";
}

export interface Conversation {
  id: string;
  type: "direct" | "group";
  name: string;
  members: ChatUser[];
  last_message: ChatLastMessage | null;
  unread_count: number;
  /** Unread messages that tag you with @. */
  unread_mentions?: number;
  updated_at: string;
}

export interface ChatReaction {
  emoji: string;
  user_ids: string[];
}

export interface ChatMessage {
  id: string;
  conversation_id: string;
  sender_id: string;
  sender_name: string;
  body: string;
  created_at: string;
  deleted: boolean;
  /** "sticker": body is the sticker's id. */
  kind?: "text" | "sticker";
  /** Ids of the people tagged with @. */
  mentions?: string[];
  reactions?: ChatReaction[];
  /** Client-only state for optimistic sends. */
  pending?: boolean;
  failed?: boolean;
}

export interface ChatMessagesResponse {
  messages: ChatMessage[];
  has_more: boolean;
  /** How far each person has read: userId -> last read message id. */
  reads?: Record<string, string | null>;
}

// ---- Roles & permissions ----
export interface PermissionItem {
  key: string;
  label: string;
  description?: string;
}

export interface PermissionGroup {
  key: string;
  label: string;
  items: PermissionItem[];
}

export interface RoleDef {
  id: string;
  name: string;
  slug: string;
  description?: string | null;
  is_system: boolean;
  user_count: number;
  permissions: string[];
  all_permissions: boolean;
}

export interface RoleInput {
  name?: string;
  description?: string;
  permissions?: string[];
}

export interface RoleUser {
  id: string;
  name: string;
  email: string;
  active?: boolean;
}

// ---- Chat realtime (Laravel Reverb) payloads ----
export interface ChatMessageSentEvent {
  conversation_id: string;
  message: ChatMessage;
}
export interface ChatMessageDeletedEvent {
  conversation_id: string;
  message_id: string;
}
export interface ChatConversationUpdatedEvent {
  conversation_id: string;
}
export interface ChatMessageReactedEvent {
  conversation_id: string;
  message_id: string;
  reactions: ChatReaction[];
}
export interface ChatConversationReadEvent {
  conversation_id: string;
  user_id: string;
  message_id: string;
}

// ---- Activities (tasks, calls, meetings) ----
export type ActivityType = "task" | "call" | "meeting" | "email" | "follow_up";
export type ActivityPriority = "low" | "medium" | "high";

export interface ActivityUserRef { id: string; name: string }

export interface Activity {
  id: string;
  type: ActivityType;
  title: string;
  details: string | null;
  priority: ActivityPriority;
  due_at: string | null;
  completed_at: string | null;
  done: boolean;
  overdue: boolean;
  assigned_to: ActivityUserRef | null;
  created_by: ActivityUserRef | null;
  completed_by: ActivityUserRef | null;
  lead: { lead_id: string; customer_name: string } | null;
  customer?: { id: string; name: string } | null;
  deal?: { id: string; name: string } | null;
  created_at: string;
}

export interface ActivityCounts {
  open: number; overdue: number; today: number; upcoming: number; no_date: number; done: number;
}

export interface ActivityOptions {
  users: { id: string; name: string; role: string }[];
  types: string[];
  priorities: string[];
}

export interface ActivityInput {
  type?: ActivityType;
  title?: string;
  details?: string | null;
  priority?: ActivityPriority;
  due_at?: string | null;
  assigned_to?: string | null;
  lead_id?: string | null;
  customer_id?: string | null;
  deal_id?: string | null;
  done?: boolean;
}


// ---- Supplier reply tracking ----
export type SupplierReplyStatus = "matched" | "unmatched" | "auto_reply" | "bounce" | "ignored";
export type SupplierReplyMatchedBy = "thread" | "reference" | "subject" | "sender" | "manual" | "portal";

export interface SupplierReplyAttachment {
  id: string;
  filename: string;
  content_type?: string | null;
  size?: number | null;
  skipped?: boolean;
  skip_reason?: string | null;
}

export interface SupplierReply {
  id: string;
  lead_id: string | null;
  customer_name: string | null;
  supplier: { id: string; name: string; email?: string | null } | null;
  from_email: string;
  from_name?: string | null;
  subject: string;
  body_text: string;
  preview?: string;
  received_at: string;
  status: SupplierReplyStatus;
  matched_by: SupplierReplyMatchedBy | null;
  /** quote = has a document or a price; chatter = internal forward / follow-up noise */
  relevance?: "normal" | "quote" | "chatter";
  is_final?: boolean;
  read: boolean;
  attachments: SupplierReplyAttachment[];
}

export interface SupplierReplyCounts {
  all: number;
  matched: number;
  unmatched: number;
  bounce: number;
  auto_reply: number;
  ignored: number;
  unread: number;
}

export interface SupplierReplyPage {
  items: SupplierReply[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  counts?: SupplierReplyCounts;
}

export interface MailStatus {
  transport: "graph" | "log";
  configured: boolean;
  mailbox?: string | null;
  last_poll_at?: string | null;
  last_success_at?: string | null;
  last_error?: string | null;
  unprocessed_count?: number;
  replies_total?: number;
  unmatched_count?: number;
}

export interface MailPollResult {
  fetched: number;
  new: number;
  matched: number;
  unmatched: number;
  errors: number;
}

/** Realtime payload on `notifications.user.{id}` event `.supplier.reply`. */
export interface SupplierReplyEvent {
  reply: {
    id: string;
    lead_id: string | null;
    customer_name?: string | null;
    supplier_name?: string | null;
    subject?: string;
    preview?: string;
    received_at?: string;
  };
}


// ---- Customers & Deals ----
export type CustomerType = "individual" | "family" | "corporate" | "agent";
export type DealStage = "enquiry" | "quoted" | "negotiation" | "won" | "lost";
export type TripStatus = "confirmed" | "docs_collected" | "travelling" | "completed";

export interface CustomerContact {
  id?: string;
  name: string;
  designation?: string | null;
  phone?: string | null;
  email?: string | null;
  is_primary?: boolean;
}

export interface CustomerTraveller {
  id?: string;
  name: string;
  relation?: string | null;
  gender?: string | null;
  dob?: string | null;
  nationality?: string | null;
  passport_no?: string | null;
  passport_masked?: boolean;
  passport_expiry?: string | null;
  preferences?: string | null;
}

export interface CustomerBrief { id: string; customer_no: string; name: string }

export interface Customer extends CustomerBrief {
  type: CustomerType;
  status: "active" | "inactive";
  company: string | null;
  phone: string | null;
  whatsapp: string | null;
  email: string | null;
  gstin: string | null;
  address_line: string | null;
  city: string | null;
  state: string | null;
  pincode: string | null;
  country: string | null;
  source: string | null;
  notes: string | null;
  owner: { id: string; name: string } | null;
  owner_id?: string | null;
  deals_count: number;
  open_pipeline: number;
  won_value: number;
  invoiced?: number;
  outstanding?: number;
  advances?: number;
  created_at: string;
  updated_at: string;
  contacts?: CustomerContact[];
  travellers?: CustomerTraveller[];
  deals?: Deal[];
  leads?: { lead_id: string; destination: string; travel_date: string; lead_status: string; converted_at: string | null }[];
}

export interface Deal {
  id: string;
  deal_no: string;
  name: string;
  customer: CustomerBrief | null;
  customer_id: string;
  lead_id: string | null;
  stage: DealStage;
  trip_status: TripStatus | null;
  probability: number;
  destination: string | null;
  travel_date: string | null;
  duration_days: number | null;
  adults: number;
  children: number;
  selling_amount: number;
  supplier_cost: number;
  margin: number;
  weighted: number;
  expected_close: string | null;
  closed_at: string | null;
  lost_reason: string | null;
  notes: string | null;
  owner: { id: string; name: string } | null;
  overdue: boolean;
  created_at: string;
  updated_at: string;
}

export interface CrmNote { id: string; note: string; created_by_id: string | null; created_by_name: string | null; created_at: string }

export interface CrmOptions {
  owners: { id: string; name: string }[];
  types: string[];
  sources: string[];
}

export interface ConvertPreview {
  lead: { lead_id: string; customer_name: string; lead_status: string };
  converted: boolean;
  customer: CustomerBrief | null;
  customer_suggestion: { name: string; phone: string; email: string; type: CustomerType; source: string | null; owner_id: string | null };
  matches: (CustomerBrief & { phone: string | null; email: string | null; visible: boolean })[];
  deal_suggestion: Partial<Deal> & { owner_id?: string | null };
}

// ---- Forecasts ----
export interface ForecastQuarter {
  quarter: number; label: string; months: string; start: string; end: string; current: boolean;
  target: number; margin_target: number | null;
  won: number; won_count: number; won_margin: number;
  open: number; open_count: number; weighted: number; overdue_count: number; overdue_value: number;
}
export interface ForecastOwnerRow { id: string | null; name: string; won: number; won_count: number; open: number; open_count: number; weighted: number; target: number }
export interface Forecast {
  fy: number; label: string; fys: number[]; owner: string; can_view_all: boolean; can_set_targets: boolean;
  quarters: ForecastQuarter[];
  totals: { target: number; won: number; won_count: number; won_margin: number; pct: number | null; open: number; weighted: number; open_count: number; overdue_count: number; overdue_value: number };
  undated: { count: number; value: number; weighted: number };
  owners: ForecastOwnerRow[];
  consultants: { id: string; name: string }[];
}

// ---- Packages & Quotes ----
export type PackageCategory = "domestic" | "international" | "honeymoon" | "group" | "pilgrimage" | "adventure" | "other";
export interface PackageSeason { id?: string; name: string; start_date: string; end_date: string; adult_price: number; child_price: number; adult_cost: number; child_cost: number }
export interface PackageDay { day_no?: number; title: string; description?: string | null }
export interface TravelPackage {
  id: string; code: string; name: string; destination: string | null; category: PackageCategory;
  nights: number; days: number; summary: string | null; inclusions: string | null; exclusions: string | null; terms: string | null;
  adult_price: number; child_price: number; adult_cost: number; child_cost: number; gst_rate: number; active: boolean;
  seasons?: PackageSeason[]; itinerary?: PackageDay[];
  /** "website" = brought in from indianrounds.in: its website fields are locked until a manager unlocks it. */
  source?: "local" | "website"; locked?: string[]; source_unlocked?: boolean; source_synced_at?: string | null;
  image_url?: string | null; website_url?: string | null; highlights?: string | null;
  departures?: { id: string; start_date: string; end_date: string | null; seats_left: number | null; status: string | null }[];
  /** only for people who manage packages */
  assignees?: { id: string; name: string }[]; assignees_count?: number;
}
export interface AssignableUser { id: string; name: string; code: string; destinations: string[] }
export interface WebsiteSyncResult { created: number; updated: number; unchanged: number; skipped_unlocked: number; deactivated: number; reactivated: number; failed: number; tours: number; departures: number }
export interface PackageRates { season: string | null; adult_price: number; child_price: number; adult_cost: number; child_cost: number; gst_rate: number }

export type QuoteStatus = "draft" | "sent" | "accepted" | "rejected" | "superseded";
export type QuoteDisplayStatus = QuoteStatus | "expired";
export type LineKind = "package" | "hotel" | "flight" | "transfer" | "visa" | "insurance" | "activity" | "other";
export interface QuoteLine { kind: LineKind; description: string; qty: number; unit_price: number; discount: number; tax_rate: number; amount?: number; tax?: number; total?: number }
export interface Quote {
  id: string; quote_no: string; version: number; status: QuoteStatus; display_status: QuoteDisplayStatus;
  customer: CustomerBrief | null; customer_id: string; deal: { id: string; deal_no: string; name: string } | null; deal_id: string | null;
  package_id: string | null; title: string; issue_date: string; valid_until: string | null; destination: string | null; travel_date: string | null;
  duration_days: number | null; adults: number; children: number; tcs_rate: number;
  subtotal: number; discount_total: number; tax_total: number; tcs_total: number; total: number;
  intro: string | null; inclusions: string | null; exclusions: string | null; terms: string | null; reject_reason: string | null;
  sent_at: string | null; accepted_at: string | null; owner: { id: string; name: string } | null;
  is_proposal?: boolean; lines?: QuoteLine[]; itinerary?: PackageDay[];
  emails?: { to: string; subject: string; status: string; error: string | null; with_pdf: boolean; created_at: string }[];
  created_at: string; updated_at: string;
}

// ---- Invoices, receipts, credit notes ----
export type InvoiceStatus = "draft" | "issued" | "cancelled";
export type PaymentStatus = "draft" | "cancelled" | "credited" | "paid" | "overdue" | "partial" | "unpaid";
export type PayMode = "upi" | "bank_transfer" | "card" | "cash" | "cheque" | "other";
export interface Installment { label: string; due_date: string; amount: number; paid?: number; status?: "paid" | "partial" | "due" | "overdue" }
export interface CreditNote { id: string; credit_note_no: string; issue_date: string; amount: number; taxable_part: number; tax_part: number; reason: string }
export interface Receipt {
  id: string; receipt_no: string; kind: "receipt" | "refund"; status: "valid" | "cancelled";
  customer: CustomerBrief | null; customer_id: string; invoice: { id: string; invoice_no: string; title: string } | null; invoice_id: string | null;
  amount: number; received_date: string; mode: PayMode; reference: string | null; deposited_to: string | null; notes: string | null;
  cancel_reason: string | null; created_by_name?: string | null; created_at: string;
}
export interface Invoice {
  id: string; invoice_no: string | null; status: InvoiceStatus; payment_status: PaymentStatus;
  customer: CustomerBrief | null; customer_id: string; deal: { id: string; deal_no: string; name: string } | null; deal_id: string | null; quote_id: string | null;
  title: string; issue_date: string | null; destination: string | null; travel_date: string | null; adults: number; children: number;
  bill_name: string | null; bill_gstin: string | null; bill_address: string | null; place_of_supply: string | null; sac_code: string | null;
  gst_mode: "cgst_sgst" | "igst" | "unspecified"; tcs_rate: number;
  subtotal: number; discount_total: number; tax_total: number; cgst_total: number; sgst_total: number; igst_total: number; tcs_total: number; total: number;
  paid_total: number; credited_total: number; balance: number; refundable: number; next_due: string | null;
  reminder_count?: number; last_reminder_at?: string | null; overdue_days?: number;
  reminders?: { created_at: string; to_list: string; status: string; trigger: "manual" | "auto"; sent_by: string | null }[];
  notes: string | null; terms: string | null; cancel_reason: string | null; owner: { id: string; name: string } | null;
  lines?: QuoteLine[]; installments?: Installment[]; receipts?: Receipt[]; credit_notes?: CreditNote[];
  /** Money the customer paid before this invoice that has not been applied to it yet. */
  unapplied_advances?: { id: string; receipt_no: string; amount: number; received_date: string; mode: string }[];
  /** Set on the response to issuing: the advances that went onto the new invoice. */
  advances_applied?: { count: number; amount: number };
  created_at: string; updated_at: string;
}

// ---- Accounts (finance) ----
export type AccountType = "asset" | "liability" | "equity" | "income" | "expense";
export interface LedgerAccount {
  id: string; code: string; name: string; type: AccountType; type_label: string; subtype: string | null; description: string | null;
  is_system: boolean; active: boolean; debit: number; credit: number; balance: number; used: boolean;
}
export interface BankAccountRow { id: string; account_id: string; account_code: string | null; name: string; kind: string; bank_name: string | null; last4: string | null; is_default: boolean; active: boolean; balance: number }
export interface JournalSummary {
  id: string; entry_no: string; entry_date: string; narration: string; source_type: string; source_id: string | null;
  total: number; reversed: boolean; is_reversal: boolean; editable_by_hand: boolean; reverses_id: string | null;
}
export interface JournalLineRow { account_id: string; account_code: string | null; account_name: string | null; debit: number; credit: number; memo: string | null; customer_id: string | null }
export interface JournalDetail extends JournalSummary { lines: JournalLineRow[] }
export interface FinanceDashboard {
  bills_outstanding?: { count: number; total: number };
  banks: { id: string | null; name: string; kind: string; balance: number }[]; bank_total: number;
  receivable: number; payable: number; advances: number; gst_payable: number; tcs_payable: number;
  month: { income: number; expenses: number }; year: { income: number; expenses: number; label: string };
  months: { month: string; label: string; income: number; expenses: number }[];
  invoices_outstanding: { count: number; total: number }; lock_date: string | null; entries: number;
}
export interface LedgerReport {
  account: LedgerAccount; opening: number; closing: number; total_debit: number; total_credit: number;
  items: { entry_id: string; entry_no: string; date: string; narration: string; source_type: string; memo: string | null; debit: number; credit: number; balance: number }[];
  total: number; page: number; per_page: number; pages: number;
}
export interface TrialBalance {
  as_of: string; total_debit: number; total_credit: number; balanced: boolean;
  items: { id: string; code: string; name: string; type: AccountType; debit: number; credit: number; closing_debit: number; closing_credit: number }[];
}
export interface BankOption { id: string; name: string; kind: string; is_default: boolean }

// ---- Accounts part B: payables, money in/out, reports ----
export type BillStatus = "open" | "partial" | "paid" | "overdue" | "cancelled";
export interface SupplierPaymentRow {
  id: string; payment_no: string; status: "valid" | "cancelled"; supplier: { id: string; name: string } | null; supplier_id: string;
  bill: { id: string; bill_no: string; supplier_ref: string | null } | null; bill_id: string | null;
  amount: number; paid_date: string; mode: PayMode; reference: string | null; notes: string | null; cancel_reason: string | null;
}
export interface SupplierBill {
  id: string; bill_no: string; created_by?: string | null; supplier_ref: string | null; supplier: { id: string; name: string } | null; supplier_id: string;
  deal: { id: string; deal_no: string; name: string } | null; deal_id: string | null; bill_date: string; due_date: string | null;
  total: number; paid_total: number; balance: number; payment_status: BillStatus; status: "open" | "cancelled"; notes: string | null; cancel_reason: string | null;
  lines?: { description: string; amount: number; account_id: string; account: string | null; deal_id: string | null }[];
  payments?: SupplierPaymentRow[];
}
export interface MoneyEntryRow {
  id: string; entry_no: string; kind: "expense" | "income"; status: "valid" | "cancelled"; entry_date: string; amount: number; mode: PayMode;
  account: { id: string; code: string; name: string } | null; payee: string | null; reference: string | null; notes: string | null; cancel_reason: string | null;
}
export interface ReportRow { id?: string; code: string; name: string; amount: number }
export interface ProfitLoss { from: string; to: string; income: ReportRow[]; cost_of_sales: ReportRow[]; expenses: ReportRow[]; total_income: number; total_cost_of_sales: number; gross_profit: number; total_expenses: number; net_profit: number }
export interface BalanceSheet { as_of: string; assets: ReportRow[]; liabilities: ReportRow[]; equity: ReportRow[]; profit_to_date: number; total_assets: number; total_liabilities: number; total_equity: number; balanced: boolean }
export interface AgeRow { balance: number; current: number; d1_30: number; d31_60: number; d61_90: number; d90p: number }
export interface ReceivablesAgeing { as_of: string; items: (AgeRow & { customer_id: string; customer: string | null; invoice_id: string; invoice_no: string | null; title: string; next_due: string | null })[]; totals: AgeRow }
export interface PayablesAgeing { as_of: string; items: (AgeRow & { supplier_id: string; supplier: string | null; bill_id: string; bill_no: string; supplier_ref: string | null; due_date: string })[]; totals: AgeRow }
export interface GstSummary { from: string; to: string; note: string; items: { month: string; taxable: number; cgst: number; sgst: number; igst: number; gst_other: number; gst_total: number; tcs: number }[]; totals: { taxable: number; cgst: number; sgst: number; igst: number; gst_other: number; gst_total: number; tcs: number } }
export interface PartyStatement {
  type: "customer" | "supplier"; party: { id: string; name: string }; opening: number; closing: number; total_debit: number; total_credit: number; position: string;
  items: { entry_id: string; entry_no: string; date: string; narration: string; source_type: string; memo: string | null; debit: number; credit: number; balance: number }[];
  total: number; page: number; per_page: number; pages: number;
}
export interface DealBooks {
  revenue: { code: string; name: string; amount: number }[]; costs: { code: string; name: string; amount: number }[];
  total_revenue: number; total_costs: number; profit: number; margin_pct: number | null; bills: SupplierBill[];
}

/** A call script: a team default, or a salesperson's own version. */
export interface CallScript {
  id: string;
  scope: "team" | "mine";
  /** null = a general script, used when nothing is written for the destination */
  destination: string | null;
  title: string;
  body: string;
  updated_at: string | null;
}

// ---- Team performance (Super Admin only) ----
export interface TeamMetrics {
  open_leads: number; new_leads: number; lost: number; untouched: number; untouched_24h: number; first_contact_hours: number | null;
  calls: number; whatsapps: number; emails: number; leads_touched: number; notes: number;
  fu_due: number; fu_done: number; fu_overdue: number; activities_overdue: number; activities_done: number;
  quotes_sent: number; booked: number; booked_value: number; payments: number; payments_value: number; active_days: number;
  supplier_mails: number; enquiry_leads: number; tasks_open: number; tasks_overdue: number; tasks_done: number; docs_verified: number;
}
export interface TeamPerson {
  id: string; name: string; role: string; role_name: string; group: "sales" | "operations" | "other"; last_login_at: string | null;
  m: TeamMetrics; prev: TeamMetrics; flags: { key: string; label: string }[];
}
export interface TeamReport { period: { from: string; to: string }; previous: { from: string; to: string }; people: TeamPerson[] }
export interface TeamSummary {
  period: { from: string; to: string }; previous: { from: string; to: string };
  tiles: Record<"new_leads" | "booked" | "booked_value" | "contacts" | "untouched_24h" | "fu_overdue" | "tasks_overdue", { now: number; prev: number | null }>;
  behind: { id: string; name: string; group: string; flags: { key: string; label: string }[] }[];
}
export interface TeamDrill {
  person: { id: string; name: string; role_name: string; group: string };
  untouched: { lead_id: string; customer_name: string; destination: string; created_at: string }[];
  overdue_activities: { id: string; type: string; title: string; lead_id: string | null; due_at: string }[];
  lost: { lead_id: string; customer_name: string; destination: string; lost_reason: string | null }[];
  lost_reasons: { reason: string; count: number }[];
  booked: { id: string; deal_no: string; customer: string; destination: string; amount: number }[];
  overdue_tasks: { id: string; title: string; due_date: string; deal_id: string; deal_no: string }[];
  daily: { date: string; actions: number }[];
}

// ---- Hotel master, photo library and quotation content ----
export interface MediaItem {
  id: string; hotel_id: string | null; destination: string | null; kind: string; caption: string | null; is_cover: boolean; position: number;
  width: number | null; height: number | null; size: number; url: string; thumb_url: string; original_name: string | null;
}
export type MealPlan = "EP" | "CP" | "MAP" | "AP";
export interface HotelRate {
  id: string; room_id: string; meal_plan: MealPlan; season: string | null; start_date: string; end_date: string;
  single: number | null; double: number | null; triple: number | null; extra_adult: number | null; child_with_bed: number | null; child_no_bed: number | null;
}
export interface HotelRoom { id: string; name: string; max_adults: number; notes: string | null; active: boolean; meals?: MealPlan[]; rates: HotelRate[] }
export interface HotelMargin { type: "percent" | "amount"; value: number; source: "hotel" | "destination" | "default" }
export interface Hotel {
  id: string; name: string; destination: string; city: string | null; stars: number | null; address: string | null; phone: string | null; website: string | null;
  google_rating: number | null; google_reviews: number | null; description: string | null; amenities: string[]; active: boolean; cover: MediaItem | null;
  margin_type?: "percent" | "amount" | null; margin_value?: number | null; margin?: HotelMargin; rooms_count?: number;
  photos?: MediaItem[]; rooms?: HotelRoom[];
}
export interface HotelEstimate {
  nights: { date: string; cost: number | null; season: string | null }[]; rooms: string[]; cost: number; margin: HotelMargin; margin_amount: number;
  selling: number; per_person: number; persons: number; warnings: string[];
}
export interface QuoteBlock {
  id: string; kind: "inclusions" | "exclusions" | "cancellation" | "notes" | "terms" | "closing"; title: string; destination: string | null;
  items: string[]; tiers: { label: string; percent: number }[]; is_default: boolean; active: boolean; position: number;
}
export interface QuoteSettings { margin_default: { type: "percent" | "amount"; value: number }; margin_destinations: Record<string, { type: "percent" | "amount"; value: number }> }

// ---- premium quotation builder
export interface ProposalStay { hotel_id: number | string | null; room_id: number | string | null; meal_plan: MealPlan; start_offset: number; nights: number }
export interface ProposalDay { title: string; stay: string; bullets: string[]; media_id: number | string | null }
export interface ProposalData {
  trip: { destination: string; start_date: string | null; nights: number; adults: number; children_bed: number; children_no_bed: number; group_sizes: number[] };
  days: ProposalDay[];
  options: { name: string; stays: ProposalStay[] }[];
  land: { label: string; rows: { pax: number; cost_pp: number; child_cost_pp: number | null }[] };
  gst_rate: number; selected_option: number; cover_media_id: number | string | null;
  content: {
    inclusions: string[]; exclusions: string[]; notes: string[]; terms: string[]; closing: string[];
    cancellation: { tiers: { label: string; percent: number }[]; items: string[] };
  };
}
export interface ProposalPrice {
  persons: number; adults: number; land_selling: number; total: number; per_person: number; cost?: number; margin_amount?: number;
  stays: { hotel: string; room: string; meal_plan: MealPlan; check_in: string; nights: number; selling: number; cost?: number }[];
}
export interface ProposalComputed {
  options: { name: string; tiers: (ProposalPrice & { pax: number })[]; actual: ProposalPrice }[];
  selected: number; gst_rate: number; warnings: string[];
}
