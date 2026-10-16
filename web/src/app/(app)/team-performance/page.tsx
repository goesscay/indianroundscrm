"use client";

import Link from "next/link";
import { Suspense, useEffect, useMemo, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { ArrowDown, ArrowUp, Copy, Download, Loader2, Minus } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { INR, fmtDate } from "@/components/crm/shared";
import { cn } from "@/lib/utils";
import type { TeamDrill, TeamMetrics, TeamPerson, TeamReport } from "@/types";

type Group = "sales" | "operations" | "other";
type Col = {
  key: string; label: string; hint?: string; get: (m: TeamMetrics) => number | null; fmt?: (v: number) => string;
  /** flow = a count for the period (compared with the period before); state = how things stand now; lowerBetter flips the arrow colour */
  kind: "flow" | "state"; lowerBetter?: boolean; warnAbove?: number;
};

const num = (v: number) => v.toLocaleString("en-IN");
const hours = (v: number) => (v < 1 ? `${Math.round(v * 60)} min` : v < 48 ? `${v.toFixed(1)} h` : `${(v / 24).toFixed(1)} d`);

const SALES: Col[] = [
  { key: "open_leads", label: "Open leads", hint: "Leads they own that are New, In Progress or Quote Sent", get: (m) => m.open_leads, kind: "state" },
  { key: "new_leads", label: "New leads", hint: "Created in the period", get: (m) => m.new_leads, kind: "flow" },
  { key: "untouched_24h", label: "Not contacted 24h+", hint: "New leads with no call, WhatsApp, e-mail or note for over a day", get: (m) => m.untouched_24h, kind: "state", lowerBetter: true, warnAbove: 0 },
  { key: "first_contact_hours", label: "First contact", hint: "Average time from a new lead to its first logged contact", get: (m) => m.first_contact_hours, fmt: hours, kind: "flow", lowerBetter: true },
  { key: "calls", label: "Calls", get: (m) => m.calls, kind: "flow" },
  { key: "whatsapps", label: "WhatsApp", get: (m) => m.whatsapps, kind: "flow" },
  { key: "emails", label: "E-mails", get: (m) => m.emails, kind: "flow" },
  { key: "notes", label: "Notes", get: (m) => m.notes, kind: "flow" },
  { key: "fu_done", label: "Follow-ups done", hint: "Follow-up activities due in the period that are done", get: (m) => m.fu_done, kind: "flow" },
  { key: "fu_overdue", label: "Follow-ups overdue", hint: "Open follow-ups past their time, right now", get: (m) => m.fu_overdue, kind: "state", lowerBetter: true, warnAbove: 4 },
  { key: "quotes_sent", label: "Quotes sent", get: (m) => m.quotes_sent, kind: "flow" },
  { key: "booked", label: "Booked", hint: "Deals won in the period", get: (m) => m.booked, kind: "flow" },
  { key: "booked_value", label: "Booked value", get: (m) => m.booked_value, fmt: INR, kind: "flow" },
  { key: "payments_value", label: "Collected", hint: "Payments they recorded", get: (m) => m.payments_value, fmt: INR, kind: "flow" },
  { key: "lost", label: "Lost", hint: "Leads marked Lost in the period", get: (m) => m.lost, kind: "flow", lowerBetter: true },
  { key: "active_days", label: "Active days", hint: "Days with any recorded action", get: (m) => m.active_days, kind: "flow" },
];
const OPS: Col[] = [
  { key: "supplier_mails", label: "Supplier mails", get: (m) => m.supplier_mails, kind: "flow" },
  { key: "enquiry_leads", label: "Leads enquired", hint: "Different leads they sent supplier mails for", get: (m) => m.enquiry_leads, kind: "flow" },
  { key: "tasks_done", label: "Trip items done", get: (m) => m.tasks_done, kind: "flow" },
  { key: "tasks_open", label: "Trip items open", get: (m) => m.tasks_open, kind: "state" },
  { key: "tasks_overdue", label: "Trip items overdue", get: (m) => m.tasks_overdue, kind: "state", lowerBetter: true, warnAbove: 2 },
  { key: "docs_verified", label: "Documents verified", get: (m) => m.docs_verified, kind: "flow" },
  { key: "activities_done", label: "Activities done", get: (m) => m.activities_done, kind: "flow" },
  { key: "activities_overdue", label: "Activities overdue", get: (m) => m.activities_overdue, kind: "state", lowerBetter: true, warnAbove: 4 },
  { key: "payments_value", label: "Collected", hint: "Payments they recorded", get: (m) => m.payments_value, fmt: INR, kind: "flow" },
  { key: "active_days", label: "Active days", hint: "Days with any recorded action", get: (m) => m.active_days, kind: "flow" },
];
const OTHER: Col[] = [
  { key: "activities_done", label: "Activities done", get: (m) => m.activities_done, kind: "flow" },
  { key: "notes", label: "Notes", get: (m) => m.notes, kind: "flow" },
  { key: "booked", label: "Booked", get: (m) => m.booked, kind: "flow" },
  { key: "payments_value", label: "Collected", get: (m) => m.payments_value, fmt: INR, kind: "flow" },
  { key: "active_days", label: "Active days", get: (m) => m.active_days, kind: "flow" },
];
const COLS: Record<Group, Col[]> = { sales: SALES, operations: OPS, other: OTHER };
const TABS: { key: Group; label: string }[] = [{ key: "sales", label: "Sales" }, { key: "operations", label: "Operations" }, { key: "other", label: "Admin & others" }];

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
function presets(): { key: string; label: string; from: string; to: string }[] {
  const t = new Date(); t.setHours(12, 0, 0, 0);
  const day = (n: number) => { const d = new Date(t); d.setDate(d.getDate() + n); return d; };
  const mon = day(-((t.getDay() + 6) % 7));
  const som = new Date(t.getFullYear(), t.getMonth(), 1, 12);
  const lastStart = new Date(t.getFullYear(), t.getMonth() - 1, 1, 12);
  const lastEnd = new Date(t.getFullYear(), t.getMonth(), 0, 12);
  return [
    { key: "today", label: "Today", from: iso(t), to: iso(t) },
    { key: "yesterday", label: "Yesterday", from: iso(day(-1)), to: iso(day(-1)) },
    { key: "week", label: "This week", from: iso(mon), to: iso(t) },
    { key: "7d", label: "Last 7 days", from: iso(day(-6)), to: iso(t) },
    { key: "month", label: "This month", from: iso(som), to: iso(t) },
    { key: "lastmonth", label: "Last month", from: iso(lastStart), to: iso(lastEnd) },
  ];
}

function Delta({ now, prev, lowerBetter }: { now: number; prev: number; lowerBetter?: boolean }) {
  const d = now - prev;
  if (Math.abs(d) < 0.05) return <span className="inline-flex items-center text-[10px] text-muted-foreground"><Minus size={9} /></span>;
  const good = lowerBetter ? d < 0 : d > 0;
  return (
    <span className={cn("inline-flex items-center text-[10px] font-semibold", good ? "text-emerald-600 dark:text-emerald-400" : "text-red-600 dark:text-red-400")} title={`Before: ${prev}`}>
      {d > 0 ? <ArrowUp size={9} /> : <ArrowDown size={9} />}{Math.abs(Math.round(d * 10) / 10)}
    </span>
  );
}

function Cell({ c, p }: { c: Col; p: TeamPerson }) {
  const v = c.get(p.m);
  if (v === null) return <span className="text-muted-foreground">—</span>;
  const pv = c.get(p.prev);
  const warn = c.warnAbove !== undefined && v > c.warnAbove;
  return (
    <div className="whitespace-nowrap text-right">
      <span className={cn("tabular-nums", warn && "rounded bg-red-100 px-1.5 py-0.5 font-bold text-red-800 dark:bg-red-950/60 dark:text-red-300")}>{c.fmt ? c.fmt(v) : num(v)}</span>
      {c.kind === "flow" && pv !== null && v !== pv && <div><Delta now={v} prev={pv} lowerBetter={c.lowerBetter} /></div>}
    </div>
  );
}

function ago(iso: string | null): string {
  if (!iso) return "Never";
  const d = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
  return d <= 0 ? "Today" : d === 1 ? "Yesterday" : `${d} days ago`;
}

function Section({ title, count, children }: { title: string; count?: number; children: React.ReactNode }) {
  return (
    <div className="mt-4">
      <div className="mb-1.5 flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-muted-foreground">{title}{count !== undefined && <span className="rounded-full bg-secondary px-1.5 py-px text-[10px]">{count}</span>}</div>
      {children}
    </div>
  );
}

function PersonSheet({ person, from, to, onClose }: { person: TeamPerson | null; from: string; to: string; onClose: () => void }) {
  const [d, setD] = useState<TeamDrill | null>(null);
  const [err, setErr] = useState("");
  useEffect(() => {
    if (!person) return;
    let live = true;
    setD(null); setErr("");
    api.get<TeamDrill>(`/reports/team/${person.id}`, { params: { from, to } }).then((r) => { if (live) setD(r.data); }).catch((e) => { if (live) setErr(formatApiError(e)); });
    return () => { live = false; };
  }, [person, from, to]);
  const link = () => {
    if (!person) return;
    const url = `${window.location.origin}/team-performance?user=${person.id}&from=${from}&to=${to}`;
    navigator.clipboard.writeText(url).then(() => toast.success("Link copied. Only a Super Admin can open it."));
  };
  const max = Math.max(1, ...(d?.daily.map((x) => x.actions) ?? [1]));
  return (
    <Sheet open={!!person} onOpenChange={(o) => { if (!o) onClose(); }}>
      <SheetContent title={person ? `${person.name} · ${person.role_name}` : "Person"} description={`${fmtDate(from)} to ${fmtDate(to)}`} data-testid="person-sheet">
        <div className="flex-1 overflow-y-auto px-4 py-3 text-sm">
          <Button size="sm" variant="outline" onClick={link} data-testid="person-link"><Copy size={13} /> Copy link for this person</Button>
          {err && <div className="mt-3 text-destructive">{err}</div>}
          {!d && !err && <div className="mt-6 flex items-center gap-2 text-muted-foreground"><Loader2 size={14} className="animate-spin" /> Loading…</div>}
          {d && person && (
            <>
              {person.flags.length > 0 && <ul className="mt-3 space-y-1">{person.flags.map((f) => <li key={f.key} className="rounded bg-red-50 px-2 py-1 text-xs font-medium text-red-800 dark:bg-red-950/40 dark:text-red-300">⚑ {f.label}</li>)}</ul>}
              <Section title="Activity by day">
                {d.daily.length === 0 ? <p className="text-xs text-muted-foreground">No recorded actions in this period.</p> : (
                  <div className="flex h-12 items-end gap-0.5" aria-label="Recorded actions per day">
                    {d.daily.map((x) => <div key={x.date} title={`${fmtDate(x.date)}: ${x.actions}`} className="min-w-[3px] flex-1 rounded-sm bg-foreground/70" style={{ height: `${Math.max(8, (x.actions / max) * 100)}%` }} />)}
                  </div>
                )}
              </Section>
              {d.untouched.length > 0 && (
                <Section title="New leads nobody has contacted" count={d.untouched.length}>
                  <ul className="divide-y divide-border rounded-md border border-border">{d.untouched.map((l) => (
                    <li key={l.lead_id} className="flex items-center justify-between gap-2 px-2.5 py-1.5"><Link href={`/leads/${l.lead_id}`} className="min-w-0 truncate font-medium hover:underline">{l.customer_name} <span className="font-mono text-[11px] text-muted-foreground">{l.lead_id}</span></Link><span className="shrink-0 text-xs text-muted-foreground">{l.destination} · {ago(l.created_at)}</span></li>
                  ))}</ul>
                </Section>
              )}
              {d.overdue_activities.length > 0 && (
                <Section title="Overdue activities" count={d.overdue_activities.length}>
                  <ul className="divide-y divide-border rounded-md border border-border">{d.overdue_activities.map((a) => (
                    <li key={a.id} className="flex items-center justify-between gap-2 px-2.5 py-1.5"><span className="min-w-0 truncate">{a.title}{a.lead_id && <> · <Link href={`/leads/${a.lead_id}`} className="font-mono text-[11px] underline">{a.lead_id}</Link></>}</span><span className="shrink-0 text-xs text-red-600 dark:text-red-400">{ago(a.due_at)}</span></li>
                  ))}</ul>
                </Section>
              )}
              {d.overdue_tasks.length > 0 && (
                <Section title="Overdue trip checklist items" count={d.overdue_tasks.length}>
                  <ul className="divide-y divide-border rounded-md border border-border">{d.overdue_tasks.map((t) => (
                    <li key={t.id} className="flex items-center justify-between gap-2 px-2.5 py-1.5"><span className="min-w-0 truncate">{t.title} · <Link href={`/deals/${t.deal_id}`} className="font-mono text-[11px] underline">{t.deal_no}</Link></span><span className="shrink-0 text-xs text-red-600 dark:text-red-400">due {fmtDate(t.due_date)}</span></li>
                  ))}</ul>
                </Section>
              )}
              {d.booked.length > 0 && (
                <Section title="Booked in this period" count={d.booked.length}>
                  <ul className="divide-y divide-border rounded-md border border-border">{d.booked.map((b) => (
                    <li key={b.id} className="flex items-center justify-between gap-2 px-2.5 py-1.5"><Link href={`/deals/${b.id}`} className="min-w-0 truncate hover:underline">{b.customer} · {b.destination} <span className="font-mono text-[11px] text-muted-foreground">{b.deal_no}</span></Link><span className="shrink-0 font-semibold">{INR(b.amount)}</span></li>
                  ))}</ul>
                </Section>
              )}
              {d.lost.length > 0 && (
                <Section title="Lost in this period" count={d.lost.length}>
                  <div className="mb-1.5 flex flex-wrap gap-1.5">{d.lost_reasons.map((r) => <span key={r.reason} className="rounded-full bg-secondary px-2 py-0.5 text-xs">{r.reason} · {r.count}</span>)}</div>
                  <ul className="divide-y divide-border rounded-md border border-border">{d.lost.map((l) => (
                    <li key={l.lead_id} className="flex items-center justify-between gap-2 px-2.5 py-1.5"><Link href={`/leads/${l.lead_id}`} className="min-w-0 truncate hover:underline">{l.customer_name} <span className="font-mono text-[11px] text-muted-foreground">{l.lead_id}</span></Link><span className="shrink-0 text-xs text-muted-foreground">{l.lost_reason ?? "No reason recorded"}</span></li>
                  ))}</ul>
                </Section>
              )}
              {!d.untouched.length && !d.overdue_activities.length && !d.overdue_tasks.length && !d.booked.length && !d.lost.length && <p className="mt-6 text-center text-sm text-muted-foreground">Nothing to raise: no overdue items, no untouched leads, nothing booked or lost in this period.</p>}
            </>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function Board() {
  const router = useRouter();
  const sp = useSearchParams();
  const P = useMemo(() => presets(), []);
  const month = P.find((p) => p.key === "month")!;
  const from = sp.get("from") || month.from;
  const to = sp.get("to") || month.to;
  const tab = (["sales", "operations", "other"].includes(sp.get("tab") ?? "") ? sp.get("tab") : "sales") as Group;
  const userParam = sp.get("user");

  const [data, setData] = useState<TeamReport | null>(null);
  const [err, setErr] = useState("");
  const [loading, setLoading] = useState(true);

  const set = (changes: Record<string, string | null>) => {
    const n = new URLSearchParams(window.location.search);
    Object.entries(changes).forEach(([k, v]) => { if (v === null) n.delete(k); else n.set(k, v); });
    router.replace(`/team-performance?${n.toString()}`, { scroll: false });
  };

  useEffect(() => {
    let live = true;
    setLoading(true);
    api.get<TeamReport>("/reports/team", { params: { from, to } })
      .then((r) => { if (live) { setData(r.data); setErr(""); } })
      .catch((e) => { if (live) setErr(formatApiError(e)); })
      .finally(() => { if (live) setLoading(false); });
    return () => { live = false; };
  }, [from, to]);

  const cols = COLS[tab];
  const people = useMemo(() => (data?.people ?? []).filter((p) => p.group === tab), [data, tab]);
  const counts = useMemo(() => Object.fromEntries(TABS.map((t) => [t.key, (data?.people ?? []).filter((p) => p.group === t.key).length])), [data]);
  const sum = (c: Col, set: "m" | "prev") => people.reduce((a, p) => a + (c.get(p[set]) ?? 0), 0);
  const open = data?.people.find((p) => p.id === userParam) ?? null;
  const activePreset = P.find((p) => p.from === from && p.to === to)?.key ?? "custom";

  const exportCsv = () => {
    const head = ["Name", "Role", ...cols.map((c) => c.label), "Last sign-in", "Flags"];
    const rows = people.map((p) => [p.name, p.role_name, ...cols.map((c) => { const v = c.get(p.m); return v === null ? "" : v; }), p.last_login_at ? p.last_login_at.slice(0, 10) : "", p.flags.map((f) => f.label).join("; ")]);
    const csv = [head, ...rows].map((r) => r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(",")).join("\n");
    const a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
    a.download = `team-${tab}-${from}-to-${to}.csv`; a.click();
  };

  return (
    <div className="space-y-4" data-testid="team-performance">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="label-caps">Super Admin only</div>
          <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Team Performance</h1>
          <p className="mt-1 text-xs text-muted-foreground">What each person recorded in the system, {fmtDate(from)} to {fmtDate(to)}. Arrows compare with the {data ? `${fmtDate(data.previous.from)} to ${fmtDate(data.previous.to)}` : "period before"}. Work that was not logged does not show.</p>
        </div>
        <Button variant="outline" onClick={exportCsv} disabled={!people.length} data-testid="team-export"><Download size={15} /> Export CSV</Button>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="inline-flex flex-wrap rounded border border-border p-0.5 text-sm" role="tablist" aria-label="Period">
          {P.map((p) => (
            <button key={p.key} type="button" role="tab" aria-selected={activePreset === p.key} onClick={() => set({ from: p.from, to: p.to })} data-testid={`period-${p.key}`}
                    className={cn("rounded px-2.5 py-1 text-xs", activePreset === p.key ? "bg-foreground text-background" : "text-muted-foreground hover:text-foreground")}>{p.label}</button>
          ))}
        </div>
        <div className="flex items-center gap-1.5 text-xs">
          <Input type="date" value={from} max={to} onChange={(e) => e.target.value && set({ from: e.target.value })} className="h-8 w-[140px]" aria-label="From" data-testid="period-from" />
          <span className="text-muted-foreground">to</span>
          <Input type="date" value={to} min={from} onChange={(e) => e.target.value && set({ to: e.target.value })} className="h-8 w-[140px]" aria-label="To" data-testid="period-to" />
        </div>
      </div>

      <div className="flex gap-1 border-b border-border" role="tablist" aria-label="Team">
        {TABS.map((t) => (
          <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} onClick={() => set({ tab: t.key })} data-testid={`team-tab-${t.key}`}
                  className={cn("-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2 text-[13px] font-medium", tab === t.key ? "border-primary text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}>
            {t.label}<span className="rounded-full bg-secondary px-1.5 py-px text-[11px] tabular-nums">{counts[t.key] ?? 0}</span>
          </button>
        ))}
      </div>

      {err && <div className="text-sm text-destructive">{err}</div>}
      <Card className={cn("overflow-hidden rounded-lg", loading && "opacity-60 transition-opacity")}>
        <div className="overflow-x-auto">
          <Table className="text-[13px]">
            <TableHeader>
              <TableRow>
                <TableHead className="sticky left-0 z-10 h-9 bg-card px-3 text-xs">Person</TableHead>
                {cols.map((c) => <TableHead key={c.key} className="h-9 whitespace-nowrap px-3 text-right text-xs" title={c.hint}>{c.label}</TableHead>)}
                <TableHead className="h-9 whitespace-nowrap px-3 text-right text-xs">Last sign-in</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {people.length === 0 && !loading && <TableRow><TableCell colSpan={cols.length + 2} className="py-10 text-center text-muted-foreground">No one in this group.</TableCell></TableRow>}
              {people.map((p) => (
                <TableRow key={p.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => set({ user: p.id })} data-testid={`team-row-${p.id}`}>
                  <TableCell className="sticky left-0 z-10 bg-card px-3 py-2">
                    <div className="font-medium leading-tight">{p.name}</div>
                    <div className="text-[11px] text-muted-foreground">{p.role_name}</div>
                    {p.flags.map((f) => <div key={f.key} className="mt-0.5 max-w-[200px] text-[10px] font-semibold leading-tight text-red-700 dark:text-red-400" data-testid={`flag-${p.id}-${f.key}`}>⚑ {f.label}</div>)}
                  </TableCell>
                  {cols.map((c) => <TableCell key={c.key} className="px-3 py-2"><Cell c={c} p={p} /></TableCell>)}
                  <TableCell className="whitespace-nowrap px-3 py-2 text-right text-xs text-muted-foreground">{ago(p.last_login_at)}</TableCell>
                </TableRow>
              ))}
              {people.length > 1 && (
                <TableRow className="border-t-2 bg-secondary/40 font-semibold">
                  <TableCell className="sticky left-0 z-10 bg-secondary/40 px-3 py-2">Team total</TableCell>
                  {cols.map((c) => {
                    if (c.key === "first_contact_hours") return <TableCell key={c.key} className="px-3 py-2 text-right text-muted-foreground">—</TableCell>;
                    const v = sum(c, "m"); const pv = sum(c, "prev");
                    return <TableCell key={c.key} className="px-3 py-2 text-right whitespace-nowrap"><span className="tabular-nums">{c.fmt ? c.fmt(v) : num(v)}</span>{c.kind === "flow" && v !== pv && <div><Delta now={v} prev={pv} lowerBetter={c.lowerBetter} /></div>}</TableCell>;
                  })}
                  <TableCell />
                </TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </Card>
      <PersonSheet person={open} from={from} to={to} onClose={() => set({ user: null })} />
    </div>
  );
}

export default function TeamPerformancePage() {
  return <Suspense fallback={null}><Board /></Suspense>;
}
