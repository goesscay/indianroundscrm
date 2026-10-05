"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { Search } from "lucide-react";
import { api } from "@/lib/api";
import { usePagedList } from "@/lib/usePagedList";
import { useCurrentUser } from "@/context/AuthContext";
import { useSupplierReplies } from "@/context/SupplierRepliesContext";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import MailStatusStrip from "@/components/supplier/MailStatusStrip";
import ReplyDrawer from "@/components/supplier/ReplyDrawer";
import { StatusBadge, absTime, relTime, senderLabel } from "@/components/supplier/shared";
import { cn } from "@/lib/utils";
import type { SupplierReply, SupplierReplyCounts } from "@/types";

type TabKey = "all" | "matched" | "unmatched" | "bounce" | "auto_reply" | "ignored";
const TABS: { key: TabKey; label: string; empty: string }[] = [
  { key: "all", label: "All", empty: "No supplier replies yet. They show up here as soon as the mailbox picks them up." },
  { key: "matched", label: "Matched", empty: "No matched replies." },
  { key: "unmatched", label: "Unmatched", empty: "Nothing to triage. Every reply is linked to a lead." },
  { key: "bounce", label: "Bounces", empty: "No bounced emails." },
  { key: "auto_reply", label: "Auto-replies", empty: "No auto-replies." },
  { key: "ignored", label: "Ignored", empty: "Nothing ignored." },
];
const ZERO: SupplierReplyCounts = { all: 0, matched: 0, unmatched: 0, bounce: 0, auto_reply: 0, ignored: 0, unread: 0 };

function InboxInner() {
  const router = useRouter();
  const pathname = usePathname();
  const sp = useSearchParams();
  const { can } = useCurrentUser();
  const { counts: ctxCounts, refreshCounts, version, bump } = useSupplierReplies();

  const tabParam = sp.get("tab") as TabKey | null;
  const tab: TabKey = TABS.some((t) => t.key === tabParam) ? (tabParam as TabKey) : "all";
  const applied = sp.get("q") || "";

  const setParams = useCallback((updates: Record<string, string | null>) => {
    const next = new URLSearchParams(window.location.search);
    for (const [k, v] of Object.entries(updates)) { if (v === null || v === "") next.delete(k); else next.set(k, v); }
    if (!("page" in updates)) next.delete("page");
    const qs = next.toString();
    router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
  }, [router, pathname]);

  const [q, setQ] = useState(applied);
  useEffect(() => {
    const t = setTimeout(() => {
      const cur = new URLSearchParams(window.location.search).get("q") || "";
      if (q.trim() !== cur) setParams({ q: q.trim() || null });
    }, 300);
    return () => clearTimeout(t);
  }, [q, setParams]);

  const [localTick, setLocalTick] = useState(0);
  const refreshTick = localTick + version;
  const params = useMemo(() => {
    const f: Record<string, string> = { status: tab };
    if (applied) f.q = applied;
    return f;
  }, [tab, applied]);

  const { rows, loading, loaded, error, paginationProps } = usePagedList<SupplierReply>("/supplier-replies", params, refreshTick);

  // Tab counts: shared provider counts are the source of truth (it refetches on every version bump).
  const counts = ctxCounts ?? ZERO;

  const [selected, setSelected] = useState<SupplierReply | null>(null);
  const [overrides, setOverrides] = useState<Record<string, SupplierReply>>({});
  const view = (r: SupplierReply) => overrides[r.id] ?? r;

  const open = async (r: SupplierReply) => {
    setSelected(view(r));
    if (!view(r).read) {
      setOverrides((o) => ({ ...o, [r.id]: { ...view(r), read: true } }));
      try {
        await api.post(`/supplier-replies/${r.id}/read`);
        bump();
      } catch {
        setOverrides((o) => ({ ...o, [r.id]: { ...view(r), read: false } }));
      }
    }
  };

  const changed = (r: SupplierReply) => {
    setOverrides((o) => ({ ...o, [r.id]: r }));
    setSelected(r);
    setLocalTick((n) => n + 1);
    void refreshCounts();
    bump();
  };

  const activeTab = TABS.find((t) => t.key === tab)!;

  return (
    <div className="space-y-5" data-testid="supplier-inbox-page">
      <div>
        <div className="label-caps">Operations</div>
        <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Supplier Inbox</h1>
        <p className="text-sm text-muted-foreground mt-2 max-w-xl">Replies from suppliers, matched to leads</p>
      </div>

      {can("mail.manage") && <MailStatusStrip onPolled={() => { setLocalTick((n) => n + 1); void refreshCounts(); bump(); }} />}

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative flex-1 min-w-[200px] max-w-[360px]">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input
            value={q} onChange={(e) => setQ(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") setParams({ q: q.trim() || null }); }}
            placeholder="Search sender, subject, lead" aria-label="Search replies" className="pl-9 h-10" data-testid="inbox-search"
          />
        </div>
      </div>

      <div className="flex gap-1 overflow-x-auto border-b border-border" role="tablist" aria-label="Reply status" data-testid="inbox-tabs">
        {TABS.map((t) => {
          const active = t.key === tab;
          const n = counts[t.key];
          const hot = t.key === "unmatched" && n > 0;
          return (
            <button
              key={t.key} type="button" role="tab" aria-selected={active}
              onClick={() => setParams({ tab: t.key === "all" ? null : t.key })}
              className={cn(
                "relative -mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:bg-accent",
                active ? "border-primary text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"
              )}
              data-testid={`inbox-tab-${t.key}`}
            >
              {t.label}
              <span
                className={cn(
                  "min-w-[20px] rounded-full px-1.5 py-px text-center text-[11px] font-semibold tabular-nums",
                  hot ? "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                    : active ? "bg-primary text-primary-foreground" : "bg-secondary text-muted-foreground"
                )}
                data-testid={`inbox-count-${t.key}`}
              >{n}</span>
            </button>
          );
        })}
      </div>

      <Card className="rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-6"><span className="sr-only">Unread</span></TableHead>
                <TableHead className="min-w-[170px]">From</TableHead>
                <TableHead className="min-w-[260px]">Subject</TableHead>
                <TableHead className="min-w-[150px]">Lead</TableHead>
                <TableHead className="min-w-[120px]">Received</TableHead>
                <TableHead className="min-w-[100px]">Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && !error && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground py-8">Loading…</TableCell></TableRow>}
              {!loaded && error && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground py-8">Could not load replies.</TableCell></TableRow>}
              {loaded && rows.length === 0 && (
                <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground py-12" data-testid="inbox-empty">
                  {applied ? "No replies match your search." : activeTab.empty}
                </TableCell></TableRow>
              )}
              {rows.map((raw) => {
                const r = view(raw);
                return (
                  <TableRow key={r.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => open(r)} data-testid={`inbox-row-${r.id}`}>
                    <TableCell>
                      {!r.read && <span className="block h-2 w-2 rounded-full bg-primary" role="img" aria-label="Unread" data-testid={`inbox-unread-${r.id}`} />}
                    </TableCell>
                    <TableCell>
                      <div className={cn("text-sm max-w-[220px] truncate", !r.read && "font-semibold")}>{senderLabel(r)}</div>
                      <div className="text-xs text-muted-foreground max-w-[220px] truncate">{r.from_email}</div>
                    </TableCell>
                    <TableCell>
                      <button
                        type="button" onClick={(e) => { e.stopPropagation(); void open(r); }}
                        className={cn("block max-w-[420px] truncate text-left text-sm hover:underline focus-visible:outline-none focus-visible:underline", !r.read && "font-semibold")}
                      >{r.subject || "(no subject)"}</button>
                      {r.preview && <div className="text-xs text-muted-foreground max-w-[420px] truncate">{r.preview}</div>}
                    </TableCell>
                    <TableCell onClick={(e) => e.stopPropagation()}>
                      {r.lead_id ? (
                        <Link href={`/leads/${r.lead_id}#supplier-replies`} className="text-sm hover:underline" data-testid={`inbox-lead-${r.id}`}>
                          <span className="font-mono font-semibold">{r.lead_id}</span>
                          {r.customer_name && <span className="block text-xs text-muted-foreground max-w-[160px] truncate">{r.customer_name}</span>}
                        </Link>
                      ) : <span className="text-sm text-amber-700 dark:text-amber-300">Unmatched</span>}
                    </TableCell>
                    <TableCell className="text-sm whitespace-nowrap" title={absTime(r.received_at)}>{relTime(r.received_at)}</TableCell>
                    <TableCell><StatusBadge status={r.status} /></TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
      {error && <div className="text-sm text-destructive" role="alert">{error}</div>}

      <ReplyDrawer reply={selected} onClose={() => setSelected(null)} onChanged={changed} />
    </div>
  );
}

export default function SupplierInboxPage() {
  return <Suspense fallback={null}><InboxInner /></Suspense>;
}
