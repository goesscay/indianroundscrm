"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { cn } from "@/lib/utils";
import type { CrmOptions, CustomerType, DealStage, TripStatus } from "@/types";

export const INR = (n: number | null | undefined) => "₹" + (Number(n) || 0).toLocaleString("en-IN", { maximumFractionDigits: 0 });

/** Compact rupees for board columns: ₹1.8 L, ₹38 L, ₹1.2 Cr. */
export function inrShort(n: number | null | undefined): string {
  const v = Number(n) || 0;
  if (v >= 1e7) return `₹${+(v / 1e7).toFixed(2)} Cr`;
  if (v >= 1e5) return `₹${+(v / 1e5).toFixed(2)} L`;
  if (v >= 1e3) return `₹${+(v / 1e3).toFixed(1)} K`;
  return `₹${v}`;
}

export const CUSTOMER_TYPES: { value: CustomerType; label: string }[] = [
  { value: "individual", label: "Individual" },
  { value: "family", label: "Family" },
  { value: "corporate", label: "Corporate" },
  { value: "agent", label: "Agent" },
];
export const customerTypeLabel = (t: string) => CUSTOMER_TYPES.find((x) => x.value === t)?.label ?? t;

export const STAGES: { value: DealStage; label: string; dot: string; chip: string }[] = [
  { value: "enquiry", label: "Enquiry", dot: "bg-slate-400", chip: "bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/60 dark:text-slate-200 dark:border-slate-700" },
  { value: "quoted", label: "Quoted", dot: "bg-sky-500", chip: "bg-sky-50 text-sky-800 border-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:border-sky-900" },
  { value: "negotiation", label: "Negotiation", dot: "bg-amber-500", chip: "bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:border-amber-900" },
  { value: "won", label: "Won", dot: "bg-emerald-500", chip: "bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-900" },
  { value: "lost", label: "Lost", dot: "bg-red-500", chip: "bg-red-50 text-red-800 border-red-200 dark:bg-red-950/50 dark:text-red-300 dark:border-red-900" },
];
export const stageMeta = (s: string) => STAGES.find((x) => x.value === s) ?? STAGES[0];

export const TRIP_STATUSES: { value: TripStatus; label: string }[] = [
  { value: "confirmed", label: "Confirmed" },
  { value: "docs_collected", label: "Documents collected" },
  { value: "travelling", label: "Travelling" },
  { value: "completed", label: "Completed" },
];

export function StagePill({ stage, className }: { stage: string; className?: string }) {
  const m = stageMeta(stage);
  return (
    <span className={cn("inline-flex items-center gap-1.5 rounded border px-2 py-0.5 text-xs font-medium", m.chip, className)}>
      <span className={cn("h-1.5 w-1.5 rounded-full", m.dot)} aria-hidden /> {m.label}
    </span>
  );
}

let optionsCache: CrmOptions | null = null;

/** Owners / types / sources for the filter rows and forms (cached for the session). */
export function useCrmOptions(): CrmOptions | null {
  const [opts, setOpts] = useState<CrmOptions | null>(optionsCache);
  useEffect(() => {
    if (optionsCache) return;
    api.get<CrmOptions>("/customers/options").then((r) => { optionsCache = r.data; setOpts(r.data); }).catch(() => { /* lists stay empty */ });
  }, []);
  return opts;
}

export const fmtDate = (d: string | null | undefined) => {
  if (!d) return "—";
  const dt = new Date(d.length === 10 ? `${d}T00:00:00` : d);
  if (Number.isNaN(dt.getTime())) return "—";
  return dt.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
};
