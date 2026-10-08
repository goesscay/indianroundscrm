"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { AlertTriangle, Search } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { INR, fmtDate } from "@/components/crm/shared";
import { PaymentChip, ProgressBar, daysLabel, type TripSummary } from "@/components/crm/tripShared";
import { cn } from "@/lib/utils";

const SCOPES = [{ v: "upcoming", l: "Upcoming" }, { v: "completed", l: "Completed" }, { v: "all", l: "All" }];

/** Operations board: every confirmed trip, soonest departure first, with payment and checklist progress. */
export default function TripsBoard() {
  const [scope, setScope] = useState("upcoming");
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [rows, setRows] = useState<TripSummary[]>([]);
  const [attention, setAttention] = useState(0);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);
  useEffect(() => {
    let live = true;
    api.get<{ items: TripSummary[]; attention: number }>("/trips", { params: { scope, ...(applied.trim() ? { q: applied.trim() } : {}) } })
      .then(({ data }) => { if (live) { setRows(data.items); setAttention(data.attention); setError(""); } })
      .catch((e) => { if (live) setError(formatApiError(e)); })
      .finally(() => { if (live) setLoaded(true); });
    return () => { live = false; };
  }, [scope, applied]);

  return (
    <div className="space-y-4" data-testid="trips-board">
      <div>
        <div className="label-caps">Operations</div>
        <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Trips</h1>
        <p className="mt-1 text-xs text-muted-foreground">Confirmed trips, soonest departure first. {attention > 0 ? <span className="font-semibold text-red-600 dark:text-red-400">{attention} need attention (overdue items or payments).</span> : "Nothing overdue."}</p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="inline-flex rounded border border-border p-0.5 text-sm" role="tablist">
          {SCOPES.map((s) => (
            <button key={s.v} type="button" role="tab" aria-selected={scope === s.v} onClick={() => setScope(s.v)} data-testid={`trips-scope-${s.v}`}
                    className={cn("rounded px-3 py-1", scope === s.v ? "bg-foreground text-background" : "text-muted-foreground hover:text-foreground")}>{s.l}</button>
          ))}
        </div>
        <div className="relative w-full sm:w-72">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Customer, destination or deal…" className="h-9 pl-9" aria-label="Search trips" />
        </div>
      </div>

      {error && <div className="text-sm text-destructive">{error}</div>}
      {loaded && rows.length === 0 && !error && <Card className="rounded-lg p-8 text-center text-sm text-muted-foreground">No trips here yet. A trip appears once a deal is confirmed (advance received, or confirmed by hand on the deal).</Card>}

      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        {rows.map((r) => {
          const late = r.progress.overdue > 0 || r.payment.overdue;
          return (
            <Link key={r.deal.id} href={`/deals/${r.deal.id}`} data-testid={`trip-card-${r.deal.id}`}>
              <Card className={cn("h-full rounded-lg p-4 transition-colors hover:bg-secondary/40", late && "border-red-300 dark:border-red-900")}>
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="truncate font-semibold">{r.customer?.name ?? r.deal.name}</div>
                    <div className="truncate text-xs text-muted-foreground">{r.deal.destination ?? "—"} · {r.deal.deal_no}</div>
                  </div>
                  <div className="shrink-0 text-right">
                    <div className="text-sm font-semibold">{r.deal.travel_date ? fmtDate(r.deal.travel_date) : "No date"}</div>
                    <div className={cn("text-xs", r.days_left !== null && r.days_left <= 7 && r.days_left >= 0 ? "font-semibold text-red-600 dark:text-red-400" : "text-muted-foreground")}>{daysLabel(r.days_left)}</div>
                  </div>
                </div>
                <div className="mt-3">
                  <div className="mb-1 flex items-center justify-between text-xs"><span>Checklist {r.progress.done}/{r.progress.total}</span>
                    {r.progress.overdue > 0 && <span className="inline-flex items-center gap-1 font-semibold text-red-600 dark:text-red-400"><AlertTriangle size={11} />{r.progress.overdue} overdue</span>}</div>
                  <ProgressBar percent={r.progress.percent} tone={r.progress.overdue > 0 ? "warn" : "default"} />
                  {r.progress.next && <div className="mt-1 truncate text-xs text-muted-foreground">Next: {r.progress.next.title}{r.progress.next.due_date ? ` · ${fmtDate(r.progress.next.due_date)}` : ""}</div>}
                </div>
                <div className="mt-3 flex items-center justify-between border-t border-border pt-2 text-xs">
                  <PaymentChip p={r.payment} />
                  <span className={cn(r.payment.balance > 0 && "font-semibold", r.payment.overdue && "text-red-600 dark:text-red-400")}>{r.payment.balance > 0 ? `${INR(r.payment.balance)} balance${r.payment.overdue ? " · overdue" : ""}` : "Settled"}</span>
                </div>
              </Card>
            </Link>
          );
        })}
      </div>
    </div>
  );
}
