import { cn } from "@/lib/utils";

export interface TripTask {
  id: string; deal_id: string; category: string; title: string; notes: string | null; due_date: string | null;
  status: "pending" | "in_progress" | "done" | "na"; critical: boolean; overdue: boolean;
  assigned_to: { id: string; name: string } | null; completed_at: string | null; position: number;
}
export interface TripPayment {
  invoiced: number; paid: number; balance: number; percent: number; status: "not_paid" | "part_paid" | "paid";
  advance_received: boolean; overdue: boolean; next_due: string | null; invoices: number;
}
export interface TripProgress { total: number; done: number; percent: number; overdue: number; critical_open: number; next: { title: string; due_date: string | null } | null }
export interface TripSummary {
  deal: { id: string; deal_no: string; name: string; destination: string | null; travel_date: string | null; duration_days: number | null; adults: number; children: number; stage: string; trip_status: string | null };
  customer: { id: string; name: string } | null; owner: { id: string; name: string } | null;
  confirmed: boolean; trip_type: string | null; confirmed_at: string | null; days_left: number | null;
  payment: TripPayment; progress: TripProgress;
}
export interface TripDetail extends TripSummary { tasks: TripTask[]; assignees: { id: string; name: string }[]; advance_percent: number }
export interface TripTemplateItem { id?: string; category: string; title: string; due_days_before: number | null; critical: boolean }
export interface TripTemplate { id: string; name: string; trip_type: string; active: boolean; items: TripTemplateItem[] }

export const TRIP_TYPES: { value: string; label: string; hint: string }[] = [
  { value: "domestic", label: "Domestic trip", hint: "Within India: tickets, hotels, transfers" },
  { value: "international", label: "International trip", hint: "Adds passport checks, visas, insurance, forex" },
  { value: "package", label: "Package tour", hint: "Group departure or fixed package" },
];

export const CATEGORIES: { value: string; label: string }[] = [
  { value: "payments", label: "Payments" },
  { value: "documents", label: "Documents" },
  { value: "transport", label: "Transport" },
  { value: "stay", label: "Stay" },
  { value: "visa_insurance", label: "Visa & insurance" },
  { value: "activities", label: "Activities" },
  { value: "forex_connectivity", label: "Forex & SIM" },
  { value: "pre_departure", label: "Pre-departure" },
  { value: "post_trip", label: "After the trip" },
  { value: "other", label: "Other" },
];
export const categoryLabel = (v: string) => CATEGORIES.find((c) => c.value === v)?.label ?? v;

export const TASK_STATUS: { value: TripTask["status"]; label: string }[] = [
  { value: "pending", label: "To do" }, { value: "in_progress", label: "In progress" }, { value: "done", label: "Done" }, { value: "na", label: "Not needed" },
];

export const PAYMENT_LABEL: Record<TripPayment["status"], string> = { not_paid: "Not paid", part_paid: "Part paid", paid: "Fully paid" };

export function PaymentChip({ p }: { p: TripPayment }) {
  const tone = p.status === "paid" ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
    : p.status === "part_paid" ? "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300" : "bg-secondary text-muted-foreground";
  return <span className={cn("inline-flex items-center rounded px-2 py-0.5 text-xs font-semibold", tone)}>{PAYMENT_LABEL[p.status]}</span>;
}

export function ProgressBar({ percent, tone = "default" }: { percent: number; tone?: "default" | "warn" }) {
  return (
    <div className="h-2 w-full overflow-hidden rounded-full bg-secondary" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100}>
      <div className={cn("h-full rounded-full transition-all", tone === "warn" ? "bg-red-500" : "bg-foreground")} style={{ width: `${Math.max(0, Math.min(100, percent))}%` }} />
    </div>
  );
}

export const daysLabel = (d: number | null) => (d === null ? "No travel date" : d > 0 ? `${d} day${d === 1 ? "" : "s"} to go` : d === 0 ? "Travels today" : `Travelled ${-d} day${d === -1 ? "" : "s"} ago`);
