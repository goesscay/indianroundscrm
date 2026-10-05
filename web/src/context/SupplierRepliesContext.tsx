"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { useAuth } from "@/context/AuthContext";
import { useChatRealtime } from "@/context/ChatRealtimeContext";
import type { SupplierReplyCounts, SupplierReplyPage } from "@/types";

interface SupplierRepliesValue {
  /** Inbox counts (only loaded for users with rsq.manage). */
  counts: SupplierReplyCounts | null;
  refreshCounts: () => Promise<void>;
  /** Increments whenever replies may have changed (realtime event, reads, link/ignore). Re-fetch on change. */
  version: number;
  /** Tell the bell / lists that something changed (e.g. after marking replies read). */
  bump: () => void;
  /** The most recent realtime reply (id + lead), for pages that want to react precisely. */
  lastEvent: { replyId: string; leadId: string | null } | null;
}

const Ctx = createContext<SupplierRepliesValue>({
  counts: null,
  refreshCounts: async () => {},
  version: 0,
  bump: () => {},
  lastEvent: null,
});

const POLL_MS = 45_000;
const POLL_CONNECTED_MS = 120_000;

/**
 * Supplier reply notifications. Subscribes (via the shared realtime connection) to
 * `.supplier.reply`: toasts the lead owner, dedupes by reply id, bumps `version`.
 * Counts for the inbox badge are polled lightly (slower while the socket is connected).
 */
export function SupplierRepliesProvider({ children }: { children: ReactNode }) {
  const { user, can } = useAuth();
  const router = useRouter();
  const { notifConnected, onSupplierReply, onNotifReconnect } = useChatRealtime();
  const userId = user?.id ?? null;
  const manage = !!userId && can("rsq.manage");
  const [counts, setCounts] = useState<SupplierReplyCounts | null>(null);
  const [version, setVersion] = useState(0);
  const [lastEvent, setLastEvent] = useState<SupplierRepliesValue["lastEvent"]>(null);
  const seen = useRef<string[]>([]);

  const bump = useCallback(() => setVersion((v) => v + 1), []);

  const refreshCounts = useCallback(async () => {
    if (!manage) return;
    try {
      const { data } = await api.get<SupplierReplyPage>("/supplier-replies", { params: { page: 1, per_page: 1, status: "all" } });
      if (data && data.counts) setCounts(data.counts);
    } catch {
      /* endpoint missing or transient error: badge simply stays hidden */
    }
  }, [manage]);

  useEffect(() => {
    if (!manage) {
      setCounts(null);
      return;
    }
    void refreshCounts();
    const tick = () => {
      if (document.visibilityState === "visible") void refreshCounts();
    };
    const t = setInterval(tick, notifConnected ? POLL_CONNECTED_MS : POLL_MS);
    document.addEventListener("visibilitychange", tick);
    return () => {
      clearInterval(t);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [manage, refreshCounts, notifConnected, version]);

  // Catch up after the socket (re)subscribes: anything missed while offline.
  useEffect(() => onNotifReconnect(() => bump()), [onNotifReconnect, bump]);

  useEffect(
    () =>
      onSupplierReply(({ reply }) => {
        if (!reply || !reply.id) return;
        if (seen.current.includes(reply.id)) return;
        seen.current = [...seen.current.slice(-49), reply.id];
        const who = reply.supplier_name || "supplier";
        const lead = reply.lead_id;
        const href = lead ? `/leads/${lead}#supplier-replies` : can("rsq.manage") ? "/supplier-inbox" : null;
        toast.info(`Reply from ${who}${lead ? ` — ${lead}` : ""}`, {
          description: reply.subject || reply.preview || undefined,
          duration: 10_000,
          action: href ? { label: "View", onClick: () => router.push(href) } : undefined,
        });
        setLastEvent({ replyId: reply.id, leadId: lead });
        bump();
      }),
    [onSupplierReply, router, bump, can]
  );

  const value = useMemo(() => ({ counts, refreshCounts, version, bump, lastEvent }), [counts, refreshCounts, version, bump, lastEvent]);
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useSupplierReplies(): SupplierRepliesValue {
  return useContext(Ctx);
}
