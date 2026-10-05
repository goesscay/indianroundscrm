"use client";

import { cn } from "@/lib/utils";
import { useTheme } from "@/context/ThemeContext";

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

// Dark-mode palette (same semantic tiers as above, tuned for dark surfaces).
const D_NEUTRAL: PillStyle = { bg: "#1c1c1f", text: "#d4d4d8", border: "#2c2c30" };
const D_DARK: PillStyle = { bg: "#ececee", text: "#0A0A0A", border: "#ececee" };
const D_AMBER: PillStyle = { bg: "#2a2008", text: "#f2c96b", border: "#4a3a12" };
const D_RED: PillStyle = { bg: "#2a1010", text: "#f87171", border: "#5a1f1f" };

const DARK_STATUS_STYLES: Record<string, PillStyle> = {
  "New Lead":               { bg: "#121214", text: "#f2f2f2", border: "#8a8a90" },
  "Supplier Mail Sent":     D_NEUTRAL,
  "Quotation Awaiting":     D_AMBER,
  "Quote Received":         D_NEUTRAL,
  "Quote Sent to Customer": { bg: "#26262a", text: "#ececee", border: "#38383d" },
  "Follow-up":              D_AMBER,
  "Booked":                 D_DARK,
  "Payment Pending":        D_AMBER,
  "Payment Received":       D_DARK,
  "Travel Completed":       { bg: "#c4c4c8", text: "#0A0A0A", border: "#c4c4c8" },
  "Closed":                 { bg: "#18181a", text: "#8c8c92", border: "#27272a" },
  "Cancelled":              D_RED,
};

export default function StatusPill({ status, className }: { status?: string; className?: string }) {
  const { resolved } = useTheme();
  const s = (resolved === "dark" ? DARK_STATUS_STYLES[status ?? ""] || D_NEUTRAL : STATUS_STYLES[status ?? ""] || NEUTRAL);
  return (
    <span
      className={cn("inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold border", className)}
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
