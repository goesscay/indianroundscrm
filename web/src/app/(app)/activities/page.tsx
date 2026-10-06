"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { ArrowDown, ArrowUp, CalendarDays, List, Plus, Search, X } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import { usePagedList } from "@/lib/usePagedList";
import { useCurrentUser } from "@/context/AuthContext";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import ActivityDialog from "@/components/activities/ActivityDialog";
import ActivityCalendar from "@/components/activities/ActivityCalendar";
import { ACTIVITY_TYPES, DoneToggle, canEditActivity, dayKey, dueClass, formatDue, typeMeta } from "@/components/activities/shared";
import { cn } from "@/lib/utils";
import type { Activity, ActivityCounts, ActivityOptions } from "@/types";

type TabKey = "open" | "overdue" | "today" | "upcoming" | "no_date" | "done";
const TABS: { key: TabKey; label: string; empty: string }[] = [
  { key: "open", label: "All open", empty: "Nothing open. Add an activity to get started." },
  { key: "overdue", label: "Overdue", empty: "Nothing overdue — nice." },
  { key: "today", label: "Today", empty: "Nothing due today." },
  { key: "upcoming", label: "Upcoming", empty: "Nothing coming up." },
  { key: "no_date", label: "No date", empty: "Every open activity has a due date." },
  { key: "done", label: "Done", empty: "No completed activities yet." },
];
const ZERO: ActivityCounts = { open: 0, overdue: 0, today: 0, upcoming: 0, no_date: 0, done: 0 };

function ActivitiesInner() {
  const router = useRouter();
  const pathname = usePathname();
  const sp = useSearchParams();
  const { user, can } = useCurrentUser();
  const myId = user.id ? String(user.id) : "";
  const canCreate = can("activities.create", "activities.manage");

  // URL-backed state
  const tabParam = sp.get("tab") as TabKey | null;
  const tab: TabKey = TABS.some((t) => t.key === tabParam) ? (tabParam as TabKey) : "open";
  const view = sp.get("view") === "calendar" ? "calendar" : "list";
  const type = sp.get("type") || "all";
  const owner = sp.get("owner") || "all";
  const dir = sp.get("dir") === "desc" ? "desc" : "asc";
  const applied = sp.get("q") || "";

  const setParams = useCallback((updates: Record<string, string | null>) => {
    const next = new URLSearchParams(window.location.search);
    for (const [k, v] of Object.entries(updates)) { if (v === null || v === "") next.delete(k); else next.set(k, v); }
    if (!("page" in updates)) next.delete("page");
    const qs = next.toString();
    router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
  }, [router, pathname]);

  // Live search: URL updates shortly after typing stops.
  const [q, setQ] = useState(applied);
  useEffect(() => {
    const t = setTimeout(() => {
      const cur = new URLSearchParams(window.location.search).get("q") || "";
      if (q.trim() !== cur) setParams({ q: q.trim() || null });
    }, 300);
    return () => clearTimeout(t);
  }, [q, setParams]);

  const [options, setOptions] = useState<ActivityOptions | null>(null);
  useEffect(() => {
    api.get<ActivityOptions>("/activities/options").then((r) => setOptions(r.data)).catch(() => { /* owner list stays minimal */ });
  }, []);

  const [refreshTick, setRefreshTick] = useState(0);
  const refresh = useCallback(() => setRefreshTick((n) => n + 1), []);

  const filters = useMemo(() => {
    const f: Record<string, string> = {};
    if (applied) f.q = applied;
    if (type !== "all") f.type = type;
    if (owner !== "all") f.owner = owner;
    return f;
  }, [applied, type, owner]);

  const todayKey = dayKey(new Date());
  const listParams = useMemo(() => {
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0, 0);
    const end = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59, 999);
    return { ...filters, tab, sort: "due_at", dir, day_start: start.toISOString(), day_end: end.toISOString() };
    // todayKey changes when the local day rolls over.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, tab, dir, todayKey]);

  const { rows, loading, loaded, error, paginationProps } = usePagedList<Activity>("/activities", listParams, refreshTick);

  // Tab counts (independent of the active tab, honour search/type/owner).
  const [counts, setCounts] = useState<ActivityCounts>(ZERO);
  useEffect(() => {
    let cancelled = false;
    const now = new Date();
    const day_start = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0, 0).toISOString();
    const day_end = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59, 999).toISOString();
    api.get<{ counts?: ActivityCounts }>("/activities", { params: { ...filters, page: 1, per_page: 1, day_start, day_end } })
      .then((r) => { if (!cancelled && r.data.counts) setCounts({ ...ZERO, ...r.data.counts }); })
      .catch(() => { /* counts are decorative */ });
    return () => { cancelled = true; };
  }, [filters, refreshTick, todayKey]);

  // Optimistic done toggles keyed by activity id.
  const [optimistic, setOptimistic] = useState<Record<string, boolean>>({});
  const toggleDone = async (a: Activity) => {
    const next = !(optimistic[a.id] ?? a.done);
    setOptimistic((o) => ({ ...o, [a.id]: next }));
    try {
      await api.patch<Activity>(`/activities/${a.id}`, { done: next });
      toast.success(next ? "Marked as done" : "Reopened");
      refresh();
    } catch (e) {
      toast.error(formatApiError(e));
    } finally {
      setOptimistic((o) => { const c = { ...o }; delete c[a.id]; return c; });
    }
  };

  // Dialog state
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Activity | null>(null);
  const [prefill, setPrefill] = useState<{ due_local?: string } | undefined>(undefined);
  const openNew = (dueLocal?: string) => { setEditing(null); setPrefill(dueLocal ? { due_local: dueLocal } : undefined); setDialogOpen(true); };
  const openEdit = (a: Activity) => { setEditing(a); setPrefill(undefined); setDialogOpen(true); };

  // Owner filter options
  const seeAll = can("activities.view_all", "activities.manage");
  const ownerUsers = (options?.users ?? []).filter((u) => u.id !== myId);

  const activeFilters = (applied ? 1 : 0) + (type !== "all" ? 1 : 0) + (owner !== "all" ? 1 : 0);
  const clearFilters = () => { setQ(""); setParams({ q: null, type: null, owner: null }); };

  const activeTab = TABS.find((t) => t.key === tab)!;

  return (
    <div className="space-y-5" data-testid="activities-page">
      <div className="flex items-end justify-between flex-wrap gap-4">
        <div>
          <div className="label-caps">Sales</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Activities</h1>
          <p className="text-sm text-muted-foreground mt-2 max-w-xl">
            Tasks, calls and meetings across your ERP: what&apos;s overdue, what&apos;s today and what&apos;s coming up.
          </p>
        </div>
        {canCreate && (
          <Button onClick={() => openNew()} data-testid="activity-new-btn"><Plus size={16} /> New activity</Button>
        )}
      </div>

      <div className="flex flex-wrap gap-3 items-center" data-testid="activities-filters">
        <div className="inline-flex rounded-md border border-input bg-card p-0.5" role="group" aria-label="View">
          {([["list", "List", List], ["calendar", "Calendar", CalendarDays]] as const).map(([k, label, Icon]) => (
            <button
              key={k} type="button" onClick={() => setParams({ view: k === "list" ? null : k })}
              aria-pressed={view === k}
              className={cn(
                "inline-flex h-8 items-center gap-1.5 rounded px-3 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring",
                view === k ? "bg-primary text-primary-foreground shadow-sm" : "text-muted-foreground hover:text-foreground hover:bg-accent"
              )}
              data-testid={`activity-view-${k}`}
            >
              <Icon size={14} /> {label}
            </button>
          ))}
        </div>
        <div className="relative flex-1 min-w-[200px] max-w-[320px]">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={q} onChange={(e) => setQ(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") setParams({ q: q.trim() || null }); }}
            placeholder="Search activities" aria-label="Search activities" className="pl-9 h-10" data-testid="activity-search"
          />
        </div>
        <Select value={type} onValueChange={(v) => setParams({ type: v === "all" ? null : v })}>
          <SelectTrigger className="w-[150px] h-10" aria-label="Filter by type" data-testid="activity-type-filter"><SelectValue placeholder="All types" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All types</SelectItem>
            {ACTIVITY_TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
          </SelectContent>
        </Select>
        <Select value={owner} onValueChange={(v) => setParams({ owner: v === "all" ? null : v })}>
          <SelectTrigger className="w-[170px] h-10" aria-label="Filter by owner" data-testid="activity-owner-filter"><SelectValue placeholder="Everyone's" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Everyone&apos;s</SelectItem>
            <SelectItem value="me">Me</SelectItem>
            {seeAll && <SelectItem value="unassigned">Unassigned</SelectItem>}
            {seeAll && ownerUsers.map((u) => <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>)}
          </SelectContent>
        </Select>
        {activeFilters > 0 && (
          <Button variant="ghost" size="sm" onClick={clearFilters} data-testid="activity-clear-filters"><X size={14} /> Clear filters</Button>
        )}
      </div>

      {view === "calendar" ? (
        <ActivityCalendar
          filters={filters} refreshTick={refreshTick} canCreate={canCreate} canTrips={can("calendar.view")}
          onOpen={openEdit} onCreate={(d) => openNew(d)}
        />
      ) : (
        <>
          <div className="flex gap-1 overflow-x-auto border-b border-border" role="tablist" aria-label="Activity status" data-testid="activity-tabs">
            {TABS.map((t) => {
              const active = t.key === tab;
              const n = counts[t.key];
              return (
                <button
                  key={t.key} type="button" role="tab" aria-selected={active}
                  onClick={() => setParams({ tab: t.key === "open" ? null : t.key })}
                  className={cn(
                    "relative -mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:bg-accent",
                    active ? "border-primary text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"
                  )}
                  data-testid={`activity-tab-${t.key}`}
                >
                  {t.label}
                  <span
                    className={cn(
                      "min-w-[20px] rounded-full px-1.5 py-px text-center text-[11px] font-semibold tabular-nums",
                      t.key === "overdue" && n > 0 ? "bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300"
                        : active ? "bg-primary text-primary-foreground" : "bg-secondary text-muted-foreground"
                    )}
                    data-testid={`activity-count-${t.key}`}
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
                    <TableHead className="w-14">Done</TableHead>
                    <TableHead className="min-w-[240px]">Activity</TableHead>
                    <TableHead className="min-w-[160px]">About</TableHead>
                    <TableHead className="min-w-[130px]">Assigned to</TableHead>
                    <TableHead className="min-w-[150px]" aria-sort={dir === "asc" ? "ascending" : "descending"}>
                      <button
                        type="button" onClick={() => setParams({ dir: dir === "asc" ? "desc" : null })}
                        className="inline-flex items-center gap-1 uppercase hover:text-foreground focus-visible:outline-none focus-visible:underline"
                        data-testid="activity-sort-due"
                      >
                        Due {dir === "asc" ? <ArrowUp size={12} /> : <ArrowDown size={12} />}
                      </button>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
                  {loading && !loaded && <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground py-8">Loading…</TableCell></TableRow>}
                  {loaded && rows.length === 0 && (
                    <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground py-12" data-testid="activity-empty">
                      {activeFilters > 0 ? "No activities match your filters." : activeTab.empty}
                    </TableCell></TableRow>
                  )}
                  {rows.map((a) => {
                    const m = typeMeta(a.type);
                    const Icon = m.icon;
                    const done = optimistic[a.id] ?? a.done;
                    return (
                      <TableRow key={a.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => openEdit(a)} data-testid={`activity-row-${a.id}`}>
                        <TableCell>
                          <DoneToggle done={done} title={a.title} onToggle={() => toggleDone(a)}
                                      disabled={!canEditActivity(can, myId, a)} testId={`activity-done-${a.id}`} />
                        </TableCell>
                        <TableCell>
                          <button
                            type="button" onClick={(e) => { e.stopPropagation(); openEdit(a); }}
                            className={cn("block max-w-[420px] truncate text-left font-medium hover:underline focus-visible:outline-none focus-visible:underline", done && "line-through text-muted-foreground")}
                          >{a.title}</button>
                          <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Icon size={12} aria-hidden /> {m.label}
                            {a.priority === "high" && <span className="rounded bg-red-100 px-1.5 text-[10px] font-semibold uppercase text-red-700 dark:bg-red-950/60 dark:text-red-300">High</span>}
                          </div>
                        </TableCell>
                        <TableCell onClick={(e) => e.stopPropagation()}>
                          {a.lead ? (
                            <Link href={`/leads/${a.lead.lead_id}`} className="text-sm hover:underline" data-testid={`activity-lead-${a.id}`}>
                              <span className="text-muted-foreground">Lead:</span> {a.lead.customer_name}
                            </Link>
                          ) : <span className="text-muted-foreground">—</span>}
                        </TableCell>
                        <TableCell className="text-sm">{a.assigned_to?.name ?? <span className="text-muted-foreground">Nobody</span>}</TableCell>
                        <TableCell className={cn("text-sm whitespace-nowrap", dueClass({ overdue: a.overdue, done }))}>
                          {formatDue(a.due_at)}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
            <Pagination {...paginationProps} />
          </Card>
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
        </>
      )}

      <ActivityDialog
        open={dialogOpen} onOpenChange={setDialogOpen} activity={editing} defaults={prefill}
        onChanged={refresh}
      />
    </div>
  );
}

export default function ActivitiesPage() {
  return <Suspense fallback={null}><ActivitiesInner /></Suspense>;
}
