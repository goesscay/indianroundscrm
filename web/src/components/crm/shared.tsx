"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
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

export const PACKAGE_CATEGORIES: { value: string; label: string }[] = [
  { value: "domestic", label: "Domestic" },
  { value: "international", label: "International" },
  { value: "honeymoon", label: "Honeymoon" },
  { value: "group", label: "Group tour" },
  { value: "pilgrimage", label: "Pilgrimage" },
  { value: "adventure", label: "Adventure" },
  { value: "other", label: "Other" },
];
export const categoryLabel = (c: string) => PACKAGE_CATEGORIES.find((x) => x.value === c)?.label ?? c;

export const LINE_KINDS: { value: string; label: string }[] = [
  { value: "package", label: "Package" },
  { value: "hotel", label: "Hotel" },
  { value: "flight", label: "Flight" },
  { value: "transfer", label: "Transfer" },
  { value: "visa", label: "Visa" },
  { value: "insurance", label: "Insurance" },
  { value: "activity", label: "Activity" },
  { value: "other", label: "Other" },
];

const QUOTE_META: Record<string, { label: string; chip: string }> = {
  draft: { label: "Draft", chip: "bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/60 dark:text-slate-200 dark:border-slate-700" },
  sent: { label: "Sent", chip: "bg-sky-50 text-sky-800 border-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:border-sky-900" },
  accepted: { label: "Accepted", chip: "bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-900" },
  rejected: { label: "Rejected", chip: "bg-red-50 text-red-800 border-red-200 dark:bg-red-950/50 dark:text-red-300 dark:border-red-900" },
  expired: { label: "Expired", chip: "bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:border-amber-900" },
  superseded: { label: "Superseded", chip: "bg-neutral-100 text-neutral-500 border-neutral-200 dark:bg-neutral-900 dark:text-neutral-400 dark:border-neutral-800" },
};

export function QuotePill({ status, className }: { status: string; className?: string }) {
  const m = QUOTE_META[status] ?? QUOTE_META.draft;
  return <span className={cn("inline-flex items-center rounded border px-2 py-0.5 text-xs font-medium", m.chip, className)}>{m.label}</span>;
}

/** Same maths as the server: amount = qty*unit - discount, tax = amount*rate, TCS = taxable subtotal * rate (GST and TCS both come from the subtotal). */
export function calcQuote(lines: { qty: number; unit_price: number; discount: number; tax_rate: number }[], tcsRate: number) {
  let subtotal = 0, discount = 0, tax = 0;
  const rows = lines.map((l) => {
    const gross = Math.round((Number(l.qty) || 0) * (Number(l.unit_price) || 0) * 100) / 100;
    const disc = Number(l.discount) || 0;
    const amount = Math.round((gross - disc) * 100) / 100;
    const t = Math.round(amount * (Number(l.tax_rate) || 0)) / 100;
    subtotal += gross; discount += disc; tax += t;
    return { gross, amount, tax: t, total: amount + t };
  });
  const taxable = subtotal - discount;
  const tcs = Math.round(taxable * (Number(tcsRate) || 0)) / 100;
  const r = (n: number) => Math.round(n * 100) / 100;
  return { rows, subtotal: r(subtotal), discount: r(discount), taxable: r(taxable), tax: r(tax), tcs: r(tcs), total: r(taxable + tax + tcs) };
}

export const INR2 = (n: number | null | undefined) => "₹" + (Number(n) || 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const PAY_MODES: { value: string; label: string }[] = [
  { value: "upi", label: "UPI" },
  { value: "bank_transfer", label: "Bank transfer" },
  { value: "card", label: "Card" },
  { value: "cash", label: "Cash" },
  { value: "cheque", label: "Cheque" },
  { value: "other", label: "Other" },
];
export const modeLabel = (m: string) => PAY_MODES.find((x) => x.value === m)?.label ?? m;

const PAY_META: Record<string, { label: string; chip: string }> = {
  draft: { label: "Draft", chip: "bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/60 dark:text-slate-200 dark:border-slate-700" },
  unpaid: { label: "Unpaid", chip: "bg-sky-50 text-sky-800 border-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:border-sky-900" },
  partial: { label: "Part paid", chip: "bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:border-amber-900" },
  paid: { label: "Paid", chip: "bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-900" },
  overdue: { label: "Overdue", chip: "bg-red-50 text-red-800 border-red-200 dark:bg-red-950/50 dark:text-red-300 dark:border-red-900" },
  credited: { label: "Credited", chip: "bg-violet-50 text-violet-800 border-violet-200 dark:bg-violet-950/50 dark:text-violet-300 dark:border-violet-900" },
  cancelled: { label: "Cancelled", chip: "bg-neutral-100 text-neutral-500 border-neutral-200 dark:bg-neutral-900 dark:text-neutral-400 dark:border-neutral-800" },
};

export function PayPill({ status, className }: { status: string; className?: string }) {
  const m = PAY_META[status] ?? PAY_META.draft;
  return <span className={cn("inline-flex items-center rounded border px-2 py-0.5 text-xs font-medium", m.chip, className)}>{m.label}</span>;
}

export const SOURCE_LABEL: Record<string, string> = {
  manual: "Manual", opening: "Opening balance", invoice: "Invoice", receipt: "Receipt", advance_apply: "Advance applied", credit_note: "Credit note", reversal: "Reversal",
  bill: "Supplier bill", supplier_payment: "Supplier payment", supplier_adv_apply: "Supplier advance applied", money_entry: "Money in / out",
};

/**
 * A customer's name that opens the customer. Safe inside a clickable row or card (it does not trigger the row);
 * people without access to customers see the plain name.
 */
export function CustomerLink({ customer, className }: { customer: { id: string; name: string } | null | undefined; className?: string }) {
  const { can } = useCurrentUser();
  if (!customer) return null;
  if (!can("customers.view")) return <span className={className}>{customer.name}</span>;
  return (
    <Link href={`/customers/${customer.id}`} onClick={(e) => e.stopPropagation()} onAuxClick={(e) => e.stopPropagation()}
          className={cn("hover:underline underline-offset-2", className)} title="Open customer" data-testid={`customer-link-${customer.id}`}>
      {customer.name}
    </Link>
  );
}
