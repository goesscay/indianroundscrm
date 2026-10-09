import { cn } from "@/lib/utils";

export type PortalState = "open" | "quoted" | "declined" | "closed";

export interface PortalRequestRow {
  lead_id: string; destination: string | null; travel_date: string | null; duration: string | null;
  adults: number | string | null; children: number | string | null; state: PortalState; invited_at: string | null;
  respond_by: string | null; unread_messages: number;
  quote: { amount: number | null; currency: string | null; version: number; at: string | null } | null;
}

export interface PortalQuote {
  id: string; version: number; status: "submitted" | "declined" | "superseded";
  amount: number | null; currency: string | null; hotel_offered: string | null;
  flight_included: boolean | null; transfer_included: boolean | null; activities_included: string | null;
  valid_until: string | null; remarks: string | null; decline_reason: string | null; at: string | null;
  files: { id: string; filename: string; size: number }[];
}

export interface PortalMessage { id: string; from: "you" | "team"; body: string; at: string | null; unread: boolean }

export interface PortalRequest {
  lead_id: string; state: PortalState; respond_by: string | null; messages: PortalMessage[];
  requirement: {
    destination: string | null; travel_date: string | null; trip_end_date: string | null; duration: string | null; package_type: string | null;
    stay_cities: { city: string; nights: number | null }[];
    hotel_category: string | null; meal_plan: string | null; rooms: number | string | null; room_type: string | null;
    adults: number | string | null; children: number | string | null; child_ages: string | null;
    passport: string | null; passport_reason: string | null; special_requests: string | null;
  };
  quotes: PortalQuote[];
}

export const dateText = (d: string | null | undefined) =>
  d ? new Date(d).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "";

export const timeText = (d: string | null | undefined) =>
  d ? new Date(d).toLocaleString("en-IN", { dateStyle: "medium", timeStyle: "short" }) : "";

export function money(amount: number | null | undefined, currency: string | null | undefined): string {
  if (amount == null) return "";
  return `${currency || "INR"} ${Number(amount).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`;
}

const STATE: Record<PortalState, { label: string; cls: string }> = {
  open: { label: "Waiting for your quote", cls: "bg-amber-100 text-amber-800" },
  quoted: { label: "Quote sent", cls: "bg-emerald-100 text-emerald-800" },
  declined: { label: "Declined", cls: "bg-neutral-200 text-neutral-700" },
  closed: { label: "Closed", cls: "bg-neutral-200 text-neutral-700" },
};

export function StateBadge({ state }: { state: PortalState }) {
  const m = STATE[state] ?? STATE.open;
  return <span className={cn("inline-flex rounded px-1.5 py-0.5 text-[11px] font-semibold", m.cls)} data-testid={`portal-state-${state}`}>{m.label}</span>;
}

export function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
  return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

/** Days from today to a YYYY-MM-DD date: negative once it has passed. */
export function daysUntil(d: string | null | undefined): number | null {
  if (!d) return null;
  const t = new Date(d + "T00:00:00");
  const n = new Date(); n.setHours(0, 0, 0, 0);
  return Math.round((t.getTime() - n.getTime()) / 86400000);
}

export function dueText(d: string | null | undefined): { text: string; late: boolean } | null {
  const n = daysUntil(d);
  if (n === null) return null;
  if (n < 0) return { text: `Was due ${dateText(d)}`, late: true };
  if (n === 0) return { text: "Please reply today", late: true };
  if (n === 1) return { text: "Please reply by tomorrow", late: false };
  return { text: `Please reply by ${dateText(d)}`, late: false };
}
