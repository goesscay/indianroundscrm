import { cn } from "@/lib/utils";

const STATUS_STYLES = {
  "New Lead":               { bg: "#DBEAFE", text: "#1E40AF", border: "#BFDBFE" },
  "Supplier Mail Sent":     { bg: "#F3E8FF", text: "#6B21A8", border: "#E9D5FF" },
  "Quotation Awaiting":     { bg: "#FEF3C7", text: "#92400E", border: "#FDE68A" },
  "Quote Received":         { bg: "#CCFBF1", text: "#0F766E", border: "#99F6E4" },
  "Quote Sent to Customer": { bg: "#E0E7FF", text: "#3730A3", border: "#C7D2FE" },
  "Follow-up":              { bg: "#FFEDD5", text: "#C2410C", border: "#FED7AA" },
  "Booked":                 { bg: "#0FFCBE", text: "#024a37", border: "#0AE0A8" },
  "Payment Pending":        { bg: "#FEF08A", text: "#854D0E", border: "#FDE047" },
  "Payment Received":       { bg: "#DCFCE7", text: "#166534", border: "#BBF7D0" },
  "Travel Completed":       { bg: "#DCFCE7", text: "#166534", border: "#BBF7D0" },
  "Closed":                 { bg: "#E5E7EB", text: "#374151", border: "#D1D5DB" },
  "Cancelled":              { bg: "#FEE2E2", text: "#B91C1C", border: "#FECACA" },
};

export default function StatusPill({ status, className }) {
  const s = STATUS_STYLES[status] || { bg: "#E5E7EB", text: "#374151", border: "#D1D5DB" };
  return (
    <span
      className={cn("inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border", className)}
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
