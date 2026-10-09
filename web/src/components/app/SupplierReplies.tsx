"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Mail } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import RichText from "@/components/chat/RichText";
import { AttachmentList, NewBadge, StatusBadge, absTime, relTime, senderLabel } from "@/components/supplier/shared";
import { useSupplierReplies } from "@/context/SupplierRepliesContext";
import { cn } from "@/lib/utils";
import type { SupplierReply } from "@/types";

/** Fetches a lead's replies; refreshes on realtime events and when replies change elsewhere. */
export function useLeadReplies(leadId: string, enabled = true) {
  const { version, bump } = useSupplierReplies();
  const [replies, setReplies] = useState<SupplierReply[]>([]);
  const [loaded, setLoaded] = useState(false);

  const load = useCallback(async () => {
    if (!enabled) { setLoaded(true); return; }
    try {
      const { data } = await api.get<SupplierReply[]>(`/leads/${leadId}/supplier-replies`);
      setReplies(Array.isArray(data) ? data : []);
    } catch {
      /* feature unavailable or no access: behave as "no replies" */
    } finally {
      setLoaded(true);
    }
  }, [leadId, enabled]);

  useEffect(() => { void load(); }, [load, version]);

  const patchLocal = useCallback((fn: (r: SupplierReply) => SupplierReply) => setReplies((rs) => rs.map(fn)), []);
  return { replies, loaded, reload: load, patchLocal, bump };
}

const BODY_LINES = 6;
const needsClamp = (t: string) => t.split("\n").length > BODY_LINES || t.length > 420;

function ReplyItem({ r, onRead, onMark }: { r: SupplierReply; onRead: (r: SupplierReply) => void; onMark: (r: SupplierReply, as: "final" | "chatter" | "normal") => void }) {
  const [open, setOpen] = useState(false);
  const clampable = needsClamp(r.body_text || "");
  const bodyId = `reply-body-${r.id}`;
  const toggle = () => {
    setOpen((o) => !o);
    if (!r.read) onRead(r);
  };

  return (
    <li
      className={cn(
        "rounded-md border p-3 transition-colors",
        r.read ? "border-border" : "border-primary/40 bg-secondary/40",
        r.status === "bounce" && "border-red-300/70 dark:border-red-900/70",
        r.is_final && "border-emerald-500/60 bg-emerald-50/50 dark:bg-emerald-950/20"
      )}
      data-testid={`supplier-reply-${r.id}`}
      onClick={() => { if (!r.read) onRead(r); }}
    >
      <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="font-semibold text-sm">{senderLabel(r)}</span>
            {!r.read && <NewBadge />}
            {r.is_final && <span className="rounded bg-emerald-600 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white" data-testid={`reply-final-${r.id}`}>Final quote</span>}
            {!r.is_final && r.relevance === "quote" && <span className="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 dark:bg-sky-950/60 dark:text-sky-300">Looks like a quote</span>}
            {r.relevance === "chatter" && <span className="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-700 dark:bg-neutral-800 dark:text-neutral-300">Forward / follow-up</span>}
            {(r.status === "bounce" || r.status === "auto_reply") && <StatusBadge status={r.status} />}
          </div>
          <div className="text-xs text-muted-foreground break-all">{r.supplier?.email || r.from_email}</div>
        </div>
        <time dateTime={r.received_at} title={absTime(r.received_at)} className="text-xs text-muted-foreground whitespace-nowrap">
          {relTime(r.received_at)}
        </time>
      </div>
      {r.subject && <div className="mt-2 text-sm font-medium break-words">{r.subject}</div>}
      <div
        id={bodyId}
        className={cn("mt-1.5 text-sm text-foreground/90 whitespace-pre-wrap break-words", !open && clampable && "line-clamp-6")}
        data-testid={`supplier-reply-body-${r.id}`}
      >
        {r.body_text ? <RichText text={r.body_text} /> : <span className="text-muted-foreground">(no text)</span>}
      </div>
      {clampable && (
        <button
          type="button" onClick={(e) => { e.stopPropagation(); toggle(); }}
          aria-expanded={open} aria-controls={bodyId}
          className="mt-1 text-xs font-medium underline underline-offset-2 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring rounded"
          data-testid={`supplier-reply-toggle-${r.id}`}
        >
          {open ? "Show less" : "Show more"}
        </button>
      )}
      <AttachmentList items={r.attachments} className="mt-2" />
      {r.status === "matched" && (
        <div className="mt-2 flex flex-wrap gap-3 text-xs">
          {!r.is_final && (
            <button type="button" onClick={() => onMark(r, "final")} className="font-medium underline underline-offset-2 hover:text-foreground text-muted-foreground" data-testid={`reply-mark-final-${r.id}`}>This is the quote</button>
          )}
          {r.is_final && (
            <button type="button" onClick={() => onMark(r, "normal")} className="font-medium underline underline-offset-2 text-muted-foreground hover:text-foreground">Not the final quote</button>
          )}
          {!r.is_final && r.relevance !== "chatter" && (
            <button type="button" onClick={() => onMark(r, "chatter")} className="font-medium underline underline-offset-2 text-muted-foreground hover:text-foreground" data-testid={`reply-mark-chatter-${r.id}`}>Mark as forward</button>
          )}
          {r.relevance === "chatter" && (
            <button type="button" onClick={() => onMark(r, "normal")} className="font-medium underline underline-offset-2 text-muted-foreground hover:text-foreground">Not a forward</button>
          )}
        </div>
      )}
    </li>
  );
}

export default function SupplierReplies({
  leadId, replies, patchLocal, bump, reload, show,
}: {
  leadId: string;
  replies: SupplierReply[];
  patchLocal: (fn: (r: SupplierReply) => SupplierReply) => void;
  bump: () => void;
  reload: () => Promise<void>;
  show: boolean;
}) {
  const [showChatter, setShowChatter] = useState(false);
  const main = useMemo(
    () => replies.filter((r) => r.relevance !== "chatter").sort((a, b) => Number(!!b.is_final) - Number(!!a.is_final)),
    [replies]
  );
  const chatter = useMemo(() => replies.filter((r) => r.relevance === "chatter"), [replies]);
  const lm = useLoadMore(main, 10);
  const unread = useMemo(() => replies.filter((r) => !r.read).length, [replies]);
  const [busy, setBusy] = useState(false);
  const deepLinked = useRef(false);

  const markRead = useCallback(async (r: SupplierReply) => {
    patchLocal((x) => (x.id === r.id ? { ...x, read: true } : x));
    try {
      await api.post(`/supplier-replies/${r.id}/read`);
      bump();
    } catch (e) {
      patchLocal((x) => (x.id === r.id ? { ...x, read: false } : x));
      toast.error(formatApiError(e));
    }
  }, [patchLocal, bump]);

  const mark = useCallback(async (r: SupplierReply, as: "final" | "chatter" | "normal") => {
    try {
      await api.post(`/supplier-replies/${r.id}/mark`, { as });
      toast.success(as === "final" ? "Marked as the supplier's quote" : as === "chatter" ? "Marked as a forward" : "Updated");
      await reload();
      bump();
    } catch (e) {
      toast.error(formatApiError(e));
    }
  }, [reload, bump]);

  const markAll = useCallback(async () => {
    setBusy(true);
    try {
      await api.post("/supplier-replies/read-all", null, { params: { lead_id: leadId } });
      patchLocal((x) => ({ ...x, read: true }));
      bump();
    } catch (e) {
      toast.error(formatApiError(e));
      void reload();
    } finally {
      setBusy(false);
    }
  }, [leadId, patchLocal, bump, reload]);

  // Arriving from the bell (#supplier-replies): scroll to the card and mark unread as read.
  const hasUnread = unread > 0;
  useEffect(() => {
    if (!show || deepLinked.current || typeof window === "undefined") return;
    if (window.location.hash !== "#supplier-replies") return;
    deepLinked.current = true;
    requestAnimationFrame(() => document.getElementById("supplier-replies")?.scrollIntoView({ behavior: "smooth", block: "start" }));
    if (hasUnread) void markAll();
  }, [show, hasUnread, markAll]);

  if (!show) return null;

  return (
    <Card id="supplier-replies" className="p-6 rounded-lg lg:col-span-3 scroll-mt-20" data-testid="supplier-replies-card">
      <div className="flex flex-wrap items-center justify-between gap-2 mb-1">
        <h3 className="font-display text-xl font-bold flex items-center gap-2">
          <Mail size={18} aria-hidden /> Supplier replies
          {unread > 0 && (
            <span className="rounded-full bg-primary text-primary-foreground px-2 py-0.5 text-xs font-bold tabular-nums" data-testid="supplier-replies-unread" aria-label={`${unread} unread`}>
              {unread}
            </span>
          )}
        </h3>
        {unread > 0 && (
          <Button size="sm" variant="outline" onClick={markAll} disabled={busy} data-testid="supplier-replies-mark-all">
            Mark all read
          </Button>
        )}
      </div>
      <p className="text-xs text-muted-foreground mb-4">Emails received from suppliers for this lead.</p>
      {replies.length === 0 ? (
        <div className="text-sm text-muted-foreground py-4 text-center" data-testid="supplier-replies-empty">
          No supplier replies yet. They appear here automatically when a supplier answers your enquiry.
        </div>
      ) : (
        <>
          <ul className="space-y-3">
            {lm.visible.map((r) => <ReplyItem key={r.id} r={r} onRead={markRead} onMark={mark} />)}
          </ul>
          <LoadMore lm={lm} className="mt-3" />
          {chatter.length > 0 && (
            <div className="mt-4">
              <button
                type="button" onClick={() => setShowChatter((s) => !s)} aria-expanded={showChatter}
                className="text-xs font-medium underline underline-offset-2 text-muted-foreground hover:text-foreground"
                data-testid="supplier-replies-chatter-toggle"
              >
                {showChatter ? "Hide" : "Show"} {chatter.length} forward{chatter.length === 1 ? "" : "s"} and follow-up{chatter.length === 1 ? "" : "s"}
              </button>
              {showChatter && (
                <ul className="mt-3 space-y-3 opacity-80" data-testid="supplier-replies-chatter">
                  {chatter.map((r) => <ReplyItem key={r.id} r={r} onRead={markRead} onMark={mark} />)}
                </ul>
              )}
            </div>
          )}
        </>
      )}
    </Card>
  );
}
