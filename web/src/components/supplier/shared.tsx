"use client";

import { FileText, FileImage, FileSpreadsheet, File as FileIcon, Paperclip } from "lucide-react";
import { cn } from "@/lib/utils";
import type { SupplierReply, SupplierReplyAttachment, SupplierReplyStatus } from "@/types";

export const downloadUrl = (attachmentId: string) => `/api/supplier-replies/attachments/${encodeURIComponent(attachmentId)}/download`;

export function formatBytes(n?: number | null): string {
  if (!n || n < 0) return "";
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(n < 10 * 1024 ? 1 : 0)} KB`;
  return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

export function absTime(iso?: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "" : d.toLocaleString("en-IN", { dateStyle: "medium", timeStyle: "short" });
}

export function relTime(iso?: string | null): string {
  if (!iso) return "";
  const t = new Date(iso).getTime();
  if (Number.isNaN(t)) return "";
  const s = Math.round((Date.now() - t) / 1000);
  if (s < 45) return "just now";
  const m = Math.round(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.round(h / 24);
  if (d < 30) return `${d}d ago`;
  return new Date(t).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" });
}

export function senderLabel(r: Pick<SupplierReply, "supplier" | "from_name" | "from_email">): string {
  return r.supplier?.name || r.from_name || r.from_email;
}

const BADGE = "inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide";

export function NewBadge() {
  return <span className={cn(BADGE, "bg-primary text-primary-foreground")} data-testid="reply-new-badge">New</span>;
}

const STATUS_META: Record<SupplierReplyStatus, { label: string; cls: string }> = {
  matched: { label: "Matched", cls: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300" },
  unmatched: { label: "Unmatched", cls: "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300" },
  bounce: { label: "Bounced", cls: "bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300" },
  auto_reply: { label: "Auto-reply", cls: "bg-slate-200 text-slate-700 dark:bg-neutral-800 dark:text-neutral-300" },
  ignored: { label: "Ignored", cls: "bg-slate-200 text-slate-600 dark:bg-neutral-800 dark:text-neutral-400" },
};

export function StatusBadge({ status, className }: { status: SupplierReplyStatus; className?: string }) {
  const m = STATUS_META[status] ?? STATUS_META.matched;
  return <span className={cn(BADGE, m.cls, className)} data-testid={`reply-status-${status}`}>{m.label}</span>;
}

function AttachmentIcon({ a, className }: { a: SupplierReplyAttachment; className?: string }) {
  const t = (a.content_type || "").toLowerCase();
  const n = a.filename.toLowerCase();
  const props = { size: 13, "aria-hidden": true, className } as const;
  if (a.skipped) return <Paperclip {...props} />;
  if (t.startsWith("image/")) return <FileImage {...props} />;
  if (t.includes("sheet") || t.includes("excel") || t.includes("csv") || /\.(xlsx?|csv)$/.test(n)) return <FileSpreadsheet {...props} />;
  if (t.includes("pdf") || t.includes("word") || /\.(pdf|docx?)$/.test(n)) return <FileText {...props} />;
  return <FileIcon {...props} />;
}

export function AttachmentChip({ a }: { a: SupplierReplyAttachment }) {
  const size = formatBytes(a.size);
  const base = "inline-flex max-w-full items-center gap-1.5 rounded-md border px-2 py-1 text-xs";
  if (a.skipped) {
    const reason = a.skip_reason || "Not stored";
    return (
      <span className={cn(base, "border-dashed border-border text-muted-foreground")} title={`Not saved: ${reason}`} data-testid={`reply-attachment-${a.id}`}>
        <AttachmentIcon a={a} className="shrink-0" />
        <span className="truncate">{a.filename}</span>
        <span className="shrink-0">· {reason}</span>
      </span>
    );
  }
  return (
    <a
      href={downloadUrl(a.id)} download
      className={cn(base, "border-border bg-card text-foreground hover:bg-secondary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring")}
      title={`Download ${a.filename}`} data-testid={`reply-attachment-${a.id}`}
    >
      <AttachmentIcon a={a} className="shrink-0 text-muted-foreground" />
      <span className="truncate">{a.filename}</span>
      {size && <span className="shrink-0 text-muted-foreground">{size}</span>}
    </a>
  );
}

export function AttachmentList({ items, className }: { items: SupplierReplyAttachment[]; className?: string }) {
  if (!items || items.length === 0) return null;
  return (
    <div className={cn("flex flex-wrap gap-1.5", className)} aria-label="Attachments">
      {items.map((a) => <AttachmentChip key={a.id} a={a} />)}
    </div>
  );
}
