"use client";

import { cn } from "@/lib/utils";

type PillStyle = { bg: string; text: string; border: string };
const NEUTRAL: PillStyle = { bg: "#F1F1F1", text: "#262626", border: "#E2E2E2" };
const DARK: PillStyle = { bg: "#0A0A0A", text: "#FFFFFF", border: "#0A0A0A" };
const AMBER: PillStyle = { bg: "#FFF6DD", text: "#8A5A00", border: "#F3E2A9" };
const RED: PillStyle = { bg: "#FFFFFF", text: "#B91C1C", border: "#F3B8B8" };

const STATUS_STYLES: Record<string, PillStyle> = {
  "New Lead":               { bg: "#FFFFFF", text: "#0A0A0A", border: "#0A0A0A" },
  "Supplier Mail Sent":     NEUTRAL,
  "Quotation Awaiting":     AMBER,
  "Quote Received":         NEUTRAL,
  "Quote Sent to Customer": { bg: "#E4E4E4", text: "#0A0A0A", border: "#D0D0D0" },
  "Follow-up":              AMBER,
  "Booked":                 DARK,
  "Payment Pending":        AMBER,
  "Payment Received":       DARK,
  "Travel Completed":       { bg: "#2B2B2B", text: "#FFFFFF", border: "#2B2B2B" },
  "Closed":                 { bg: "#E9E9E9", text: "#525252", border: "#DADADA" },
  "Cancelled":              RED,
};

export default function StatusPill({ status, className }: { status?: string; className?: string }) {
  const s = STATUS_STYLES[status ?? ""] || NEUTRAL;
  return (
    <span
      className={cn("inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold border", className)}
      style={{ background: s.bg, color: s.text, borderColor: s.border }}
      data-testid={`status-pill-${(status || "unknown").toLowerCase().replace(/\s+/g, "-")}`}
    >
      {status || "—"}
    </span>
  );
}

export const LEAD_STATUSES = [
  "New Lead", "Supplier Mail Sent", "Quotation Awaiting", "Quote Received",
  "Quote Sent to Customer", "Follow-up", "Booked", "Payment Pending",
  "Payment Received", "Travel Completed", "Closed", "Cancelled",
];
