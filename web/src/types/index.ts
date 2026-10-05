// TypeScript models mirroring the Pydantic models / JSON shapes of the CRM API contract (backend/server.py).

/** Role slug. The three built-in roles are "admin" | "sales" | "operations"; admins can create more. */
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
}

export interface SupplierCreate {
  name: string;
  email: string;
  phone: string;
  destination: string;
  country: string;
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
}

export interface Conversation {
  id: string;
  type: "direct" | "group";
  name: string;
  members: ChatUser[];
  last_message: ChatLastMessage | null;
  unread_count: number;
  updated_at: string;
}

export interface ChatMessage {
  id: string;
  conversation_id: string;
  sender_id: string;
  sender_name: string;
  body: string;
  created_at: string;
  deleted: boolean;
  /** Client-only state for optimistic sends. */
  pending?: boolean;
  failed?: boolean;
}

export interface ChatMessagesResponse {
  messages: ChatMessage[];
  has_more: boolean;
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
