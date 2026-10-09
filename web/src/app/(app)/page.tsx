"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState, type ReactNode } from "react";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { AlertTriangle, ArrowRight, CalendarCheck, CheckCircle2, Clock, Phone, PlaneTakeoff, RefreshCw } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { useTheme } from "@/context/ThemeContext";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { INR, fmtDate, inrShort } from "@/components/crm/shared";

interface Kpi { key: string; label: string; value: number; format: "inr" | "number"; sub?: string; tone?: "warn" | null; href?: string }
interface FocusItem { kind: "task" | "followup"; id: string; type: string; title: string; sub: string | null; due_at: string; overdue: boolean; href: string; phone?: string }
interface Overview {
  kpis: Kpi[];
  focus: { items: FocusItem[]; total: number; overdue: number } | null;
  pipeline: { stages: { stage: string; label: string; count: number; value: number }[]; lost: number } | null;
  target: { label: string; target: number; won: number; pct: number | null; days_left: number; scope: "team" | "you" } | null;
  months: { label: string; won: number; leads: number }[] | null;
  departures: { id: string; name: string; customer: string | null; destination: string | null; travel_date: string; days: number; pax: number; trip_status: string | null; balance_due: number }[] | null;
  collections: { items: { id: string; invoice_no: string | null; customer: string | null; balance: number; due_date: string | null; overdue: boolean; days: number | null }[]; total: number; amount: number } | null;
  payments: { id: string; receipt_no: string; customer: string | null; invoice_no: string | null; amount: number; date: string }[] | null;
  leaderboard: { name: string; deals: number; value: number }[] | null;
  destinations: { destination: string; deals: number; value: number }[] | null;
  supplier: { awaiting: number; overdue: number; unmatched: number; replies_today: number } | null;
}

function greeting(): string {
  const h = new Date().getHours();
  return h < 12 ? "Good morning" : h < 17 ? "Good afternoon" : "Good evening";
}

function Widget({ title, action, children, className, testId }: { title: string; action?: { href: string; label: string }; children: ReactNode; className?: string; testId?: string }) {
  return (
    <Card className={cn("flex flex-col rounded-lg p-5", className)} data-testid={testId}>
      <div className="mb-3 flex items-center justify-between gap-3">
        <h3 className="font-display text-base font-bold">{title}</h3>
        {action && <Link href={action.href} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">{action.label} <ArrowRight size={12} /></Link>}
      </div>
      {children}
    </Card>
  );
}

const Empty = ({ children }: { children: ReactNode }) => <p className="py-6 text-center text-sm text-muted-foreground">{children}</p>;

function dueLabel(iso: string): string {
  const d = new Date(iso);
  const t = new Date(); t.setHours(0, 0, 0, 0);
  const days = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime() - t.getTime()) / 86400000);
  const time = d.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
  if (days === 0) return `Today, ${time}`;
  if (days === -1) return `Yesterday, ${time}`;
  if (days < 0) return `${-days} days ago`;
  return d.toLocaleDateString(undefined, { day: "numeric", month: "short" });
}

export default function Dashboard() {
  const { user, can } = useCurrentUser();
  const router = useRouter();
  const { resolved } = useTheme();
  const dark = resolved === "dark";
  const [d, setD] = useState<Overview | null>(null);
  const [error, setError] = useState("");
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async (manual = false) => {
    if (manual) setRefreshing(true);
    try { const { data } = await api.get<Overview>("/dashboard/overview"); setD(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
    finally { setRefreshing(false); }
  }, []);
  useEffect(() => {
    void load();
    const t = setInterval(() => void load(), 120_000);
    return () => clearInterval(t);
  }, [load]);

  const first = (user.name || "").split(" ")[0] || "there";
  const today = new Date().toLocaleDateString(undefined, { weekday: "long", day: "numeric", month: "long" });
  const grid = dark ? "#26262a" : "#E6E6E1";
  const axis = dark ? "#9c9ca2" : "#6b6b6b";

  return (
    <div className="space-y-6" data-testid="dashboard-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="label-caps">{today}</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">{greeting()}, {first}</h1>
          <p className="mt-1 text-sm text-muted-foreground">Here is what needs your attention today.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" aria-label="Refresh" onClick={() => void load(true)} data-testid="dashboard-refresh"><RefreshCw size={16} className={refreshing ? "animate-spin" : ""} /></Button>
          {can("quotes.create") && <Button variant="outline" onClick={() => router.push("/quotes/new")} data-testid="dashboard-new-quote">+ Quote</Button>}
          {can("leads.create") && <Button asChild><Link href="/leads/new" data-testid="dashboard-new-lead">+ New lead</Link></Button>}
        </div>
      </div>

      {error && <div className="text-sm text-destructive">{error}</div>}
      {!d && !error && <div className="py-16 text-center text-sm text-muted-foreground">Loading…</div>}

      {d && (
        <>
          {d.kpis.length > 0 && (
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6" data-testid="dashboard-kpis">
              {d.kpis.map((k) => {
                const body = (
                  <Card className={cn("h-full rounded-lg p-4 transition-shadow hover:shadow-sm", k.tone === "warn" && "border-amber-300 dark:border-amber-900")} data-testid={`kpi-${k.key}`}>
                    <div className="label-caps">{k.label}</div>
                    <div className="mt-1.5 font-display text-2xl font-black tracking-tight">{k.format === "inr" ? inrShort(k.value) : k.value.toLocaleString("en-IN")}</div>
                    {k.sub && <div className={cn("mt-0.5 text-xs", k.tone === "warn" ? "text-amber-700 dark:text-amber-400" : "text-muted-foreground")}>{k.sub}</div>}
                  </Card>
                );
                return k.href ? <Link key={k.key} href={k.href} className="block">{body}</Link> : <div key={k.key}>{body}</div>;
              })}
            </div>
          )}

          <div className="grid gap-6 lg:grid-cols-12">
            {d.focus && (
              <Widget title="Today's focus" action={{ href: "/activities", label: "All tasks" }} className="lg:col-span-7" testId="widget-focus">
                {d.focus.items.length === 0 ? (
                  <div className="flex flex-col items-center gap-2 py-8 text-sm text-muted-foreground"><CheckCircle2 size={28} className="text-emerald-600" /> You are all caught up. Nothing is due today.</div>
                ) : (
                  <>
                    {d.focus.overdue > 0 && <p className="mb-2 flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400"><AlertTriangle size={13} /> {d.focus.overdue} overdue</p>}
                    <ul className="divide-y divide-border">
                      {d.focus.items.map((f) => (
                        <li key={`${f.kind}-${f.id}`}>
                          <Link href={f.href} className="-mx-2 flex items-center gap-3 rounded px-2 py-2.5 hover:bg-secondary/50" data-testid={`focus-${f.kind}-${f.id}`}>
                            <span className={cn("flex h-8 w-8 shrink-0 items-center justify-center rounded-full", f.overdue ? "bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300" : "bg-secondary text-muted-foreground")}>
                              {f.kind === "followup" ? <Phone size={14} /> : <CalendarCheck size={14} />}
                            </span>
                            <span className="min-w-0 flex-1">
                              <span className="block truncate text-sm font-medium">{f.title}</span>
                              {f.sub && <span className="block truncate text-xs text-muted-foreground">{f.sub}</span>}
                            </span>
                            <span className={cn("shrink-0 text-xs", f.overdue ? "font-medium text-red-600 dark:text-red-400" : "text-muted-foreground")}>{dueLabel(f.due_at)}</span>
                          </Link>
                        </li>
                      ))}
                    </ul>
                    {d.focus.total > d.focus.items.length && <p className="mt-2 text-xs text-muted-foreground">+ {d.focus.total - d.focus.items.length} more</p>}
                  </>
                )}
              </Widget>
            )}

            {d.pipeline && (
              <Widget title="Sales pipeline" action={{ href: "/deals", label: "Open board" }} className={d.focus ? "lg:col-span-5" : "lg:col-span-12"} testId="widget-pipeline">
                {(() => {
                  const max = Math.max(1, ...d.pipeline.stages.map((s) => s.value));
                  return (
                    <ul className="space-y-3">
                      {d.pipeline.stages.map((s) => (
                        <li key={s.stage} data-testid={`pipeline-${s.stage}`}>
                          <div className="mb-1 flex items-baseline justify-between text-sm"><span className="font-medium">{s.label} <span className="text-xs font-normal text-muted-foreground">· {s.count}</span></span><span className="font-semibold">{inrShort(s.value)}</span></div>
                          <div className="h-2 overflow-hidden rounded-full bg-secondary"><div className={cn("h-full rounded-full", s.stage === "won" ? "bg-emerald-500" : s.stage === "negotiation" ? "bg-amber-500" : s.stage === "quoted" ? "bg-sky-500" : "bg-slate-400")} style={{ width: `${Math.max(s.value > 0 ? 3 : 0, (s.value / max) * 100)}%` }} /></div>
                        </li>
                      ))}
                    </ul>
                  );
                })()}
                {d.pipeline.lost > 0 && <p className="mt-4 text-xs text-muted-foreground">{d.pipeline.lost} deal{d.pipeline.lost === 1 ? "" : "s"} lost this financial year.</p>}
              </Widget>
            )}
          </div>

          <div className="grid gap-6 lg:grid-cols-12">
            {(d.target || d.months) && (
              <Widget title="Sales performance" action={can("forecasts.view") ? { href: "/forecasts", label: "Forecasts" } : undefined} className="lg:col-span-7" testId="widget-performance">
                {d.target && (
                  <div className="mb-4" data-testid="target-progress">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                      <div className="text-sm"><span className="font-semibold">{d.target.label}</span> <span className="text-muted-foreground">· {d.target.days_left} days left · {d.target.scope === "team" ? "team" : "your"} target</span></div>
                      <div className="text-sm"><span className="font-display text-xl font-black">{inrShort(d.target.won)}</span>{d.target.target > 0 && <span className="text-muted-foreground"> of {inrShort(d.target.target)}</span>}</div>
                    </div>
                    {d.target.target > 0 ? (
                      <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-secondary" role="progressbar" aria-valuenow={Math.min(100, d.target.pct ?? 0)} aria-valuemin={0} aria-valuemax={100}><div className="h-full rounded-full bg-emerald-500" style={{ width: `${Math.min(100, d.target.pct ?? 0)}%` }} /></div>
                    ) : <p className="mt-1 text-xs text-muted-foreground">No target set for this quarter.</p>}
                    {d.target.pct != null && <p className="mt-1 text-xs text-muted-foreground">{d.target.pct}% of the quarter&apos;s target</p>}
                  </div>
                )}
                {d.months && (
                  <div className="h-48" data-testid="chart-months">
                    <ResponsiveContainer width="100%" height="100%">
                      <BarChart data={d.months} margin={{ top: 4, right: 4, left: -8, bottom: 0 }}>
                        <CartesianGrid stroke={grid} vertical={false} />
                        <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fill: axis, fontSize: 12 }} />
                        <YAxis tickLine={false} axisLine={false} width={52} tick={{ fill: axis, fontSize: 11 }} tickFormatter={(v: number) => inrShort(v)} />
                        <Tooltip cursor={{ fill: dark ? "#ffffff10" : "#00000008" }} formatter={(v) => [INR(Number(v)), "Won"]} contentStyle={{ borderRadius: 6, fontSize: 12 }} />
                        <Bar dataKey="won" fill={dark ? "#ececee" : "#0A0A0A"} radius={[3, 3, 0, 0]} maxBarSize={36} />
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                )}
              </Widget>
            )}

            {d.departures && (
              <Widget title="Departing soon" action={{ href: "/deals", label: "All deals" }} className="lg:col-span-5" testId="widget-departures">
                {d.departures.length === 0 ? <Empty>No trips leave in the next three weeks.</Empty> : (
                  <ul className="divide-y divide-border">
                    {d.departures.map((t) => (
                      <li key={t.id}>
                        <Link href={`/deals/${t.id}`} className="-mx-2 flex items-center gap-3 rounded px-2 py-2.5 hover:bg-secondary/50" data-testid={`departure-${t.id}`}>
                          <span className="flex h-9 w-9 shrink-0 flex-col items-center justify-center rounded bg-secondary text-center leading-none"><span className="text-sm font-bold">{t.days}</span><span className="text-[9px] uppercase text-muted-foreground">{t.days === 1 ? "day" : "days"}</span></span>
                          <span className="min-w-0 flex-1">
                            <span className="flex items-center gap-1.5 truncate text-sm font-medium"><PlaneTakeoff size={13} className="shrink-0 text-muted-foreground" />{t.destination ?? t.name}</span>
                            <span className="block truncate text-xs text-muted-foreground">{t.customer} · {t.pax} pax · {fmtDate(t.travel_date)}</span>
                          </span>
                          {t.balance_due > 0 && <span className="shrink-0 rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-300" title="Balance still to collect">{inrShort(t.balance_due)} due</span>}
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </Widget>
            )}
          </div>

          <div className="grid gap-6 lg:grid-cols-12">
            {d.collections && (
              <Widget title="Payments to chase" action={can("invoices.view") ? { href: "/invoices?status=overdue", label: "Invoices" } : undefined} className="lg:col-span-5" testId="widget-collections">
                {d.collections.items.length === 0 ? <Empty>No payments are overdue or due this week.</Empty> : (
                  <>
                    <ul className="divide-y divide-border">
                      {d.collections.items.map((c) => (
                        <li key={c.id}>
                          <Link href={`/invoices/${c.id}`} className="-mx-2 flex items-center justify-between gap-3 rounded px-2 py-2.5 hover:bg-secondary/50" data-testid={`collect-${c.id}`}>
                            <span className="min-w-0"><span className="block truncate text-sm font-medium">{c.customer}</span><span className="block text-xs text-muted-foreground"><span className="font-mono">{c.invoice_no}</span>{c.due_date ? ` · ${c.overdue ? "was due" : "due"} ${fmtDate(c.due_date)}` : ""}</span></span>
                            <span className="shrink-0 text-right"><span className="block text-sm font-semibold">{inrShort(c.balance)}</span>{c.overdue && <span className="text-[11px] font-medium text-red-600 dark:text-red-400">Overdue</span>}</span>
                          </Link>
                        </li>
                      ))}
                    </ul>
                    {d.collections.total > d.collections.items.length && <p className="mt-2 text-xs text-muted-foreground">+ {d.collections.total - d.collections.items.length} more · {inrShort(d.collections.amount)} in total</p>}
                  </>
                )}
              </Widget>
            )}

            {d.payments && (
              <Widget title="Recent payments" action={{ href: "/receipts", label: "Receipts" }} className="lg:col-span-4" testId="widget-payments">
                {d.payments.length === 0 ? <Empty>No payments recorded yet.</Empty> : (
                  <ul className="divide-y divide-border">
                    {d.payments.map((p) => (
                      <li key={p.id}>
                        <Link href={`/receipts/${p.id}`} className="-mx-2 flex items-center justify-between gap-3 rounded px-2 py-2.5 hover:bg-secondary/50">
                          <span className="min-w-0"><span className="block truncate text-sm font-medium">{p.customer}</span><span className="block text-xs text-muted-foreground">{fmtDate(p.date)}{p.invoice_no ? ` · ${p.invoice_no}` : " · advance"}</span></span>
                          <span className="shrink-0 text-sm font-semibold text-emerald-600 dark:text-emerald-400">+ {inrShort(p.amount)}</span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </Widget>
            )}

            {(d.leaderboard || d.destinations) && (
              <Widget title={d.leaderboard && d.leaderboard.length > 0 ? "Top performers this month" : "Top destinations"} className={cn(d.collections || d.payments ? "lg:col-span-3" : "lg:col-span-12")} testId="widget-ranking">
                {d.leaderboard && d.leaderboard.length > 0 ? (
                  <ol className="space-y-2.5">
                    {d.leaderboard.map((r, i) => (
                      <li key={r.name} className="flex items-center gap-3 text-sm" data-testid={`leader-${i}`}>
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-secondary text-xs font-bold">{i + 1}</span>
                        <span className="min-w-0 flex-1"><span className="block truncate font-medium">{r.name}</span><span className="text-xs text-muted-foreground">{r.deals} deal{r.deals === 1 ? "" : "s"}</span></span>
                        <span className="font-semibold">{inrShort(r.value)}</span>
                      </li>
                    ))}
                  </ol>
                ) : d.destinations && d.destinations.length > 0 ? (
                  <ol className="space-y-2.5">
                    {d.destinations.map((r, i) => (
                      <li key={r.destination} className="flex items-center gap-3 text-sm">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-secondary text-xs font-bold">{i + 1}</span>
                        <span className="min-w-0 flex-1 truncate font-medium">{r.destination}</span><span className="font-semibold">{inrShort(r.value)}</span>
                      </li>
                    ))}
                  </ol>
                ) : <Empty>Nothing to rank yet.</Empty>}
              </Widget>
            )}
          </div>

          {d.supplier && (
            <Card className="flex flex-wrap items-center justify-between gap-4 rounded-lg p-4" data-testid="widget-supplier">
              <div className="flex items-center gap-2 text-sm font-semibold"><Clock size={16} className="text-muted-foreground" /> Supplier desk</div>
              <div className="flex flex-wrap gap-x-8 gap-y-2 text-sm">
                <span><span className="font-display text-lg font-black">{d.supplier.awaiting}</span> <span className="text-muted-foreground">awaiting a quote</span></span>
                <span className={d.supplier.overdue ? "text-amber-700 dark:text-amber-400" : ""}><span className="font-display text-lg font-black">{d.supplier.overdue}</span> <span className="text-muted-foreground">overdue over 24 h</span></span>
                <span><span className="font-display text-lg font-black">{d.supplier.replies_today}</span> <span className="text-muted-foreground">replies today</span></span>
                <span><span className="font-display text-lg font-black">{d.supplier.unmatched}</span> <span className="text-muted-foreground">unmatched</span></span>
              </div>
              <div className="flex gap-3 text-xs">
                {can("supplier_dashboard.view") && <Link href="/supplier-dashboard" className="underline underline-offset-2">Supplier dashboard</Link>}
                {can("rsq.manage") && <Link href="/supplier-inbox" className="underline underline-offset-2">Inbox</Link>}
              </div>
            </Card>
          )}

          {d.kpis.length === 0 && !d.focus && !d.pipeline && !d.supplier && (
            <Card className="rounded-lg p-10 text-center text-sm text-muted-foreground">Nothing to show here yet. Use the menu on the left to get started.</Card>
          )}
        </>
      )}
    </div>
  );
}
