"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { AlertTriangle, Search } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Pagination, usePagination } from "@/components/ui/pagination";
import { Input } from "@/components/ui/input";
import { INR, fmtDate } from "@/components/crm/shared";
import { PaymentChip, ProgressBar, daysLabel, type TripSummary } from "@/components/crm/tripShared";
import { cn } from "@/lib/utils";

const SCOPES = [{ v: "upcoming", l: "Upcoming" }, { v: "completed", l: "Completed" }, { v: "all", l: "All" }];

/** Operations board: every confirmed trip, soonest departure first, with payment and checklist progress. */
export default function TripsBoard() {
  const router = useRouter();
  const [scope, setScope] = useState("upcoming");
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [rows, setRows] = useState<TripSummary[]>([]);
  const [attention, setAttention] = useState(0);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState("");

  const pg = usePagination(rows, { resetKey: `${scope}|${applied}` });
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

      {rows.length > 0 && (
        <Card className="overflow-hidden rounded-lg">
          <div className="overflow-x-auto">
            <Table className="text-[13px]">
              <TableHeader>
                <TableRow>
                  <TableHead className="h-9 px-3 text-xs">Customer</TableHead>
                  <TableHead className="h-9 px-3 text-xs">Departure</TableHead>
                  <TableHead className="h-9 px-3 text-xs">Checklist</TableHead>
                  <TableHead className="h-9 px-3 text-xs">Ready to travel</TableHead>
                  <TableHead className="h-9 px-3 text-xs">Documents</TableHead>
                  <TableHead className="h-9 px-3 text-right text-xs">Payment</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {pg.pageItems.map((r) => {
                  const late = r.progress.overdue > 0 || r.payment.overdue;
                  const soon = r.days_left !== null && r.days_left <= 7 && r.days_left >= 0;
                  const docs = r.documents.travellers === 0 ? "Travellers not chosen"
                    : `${r.documents.received}/${r.documents.required} received`;
                  const docsNote = r.documents.travellers === 0 ? "" : r.documents.verified === r.documents.required && r.documents.required > 0 ? "all verified" : r.documents.missing > 0 ? `${r.documents.missing} missing` : "";
                  return (
                    <TableRow key={r.deal.id} className={cn("cursor-pointer hover:bg-secondary/50", late && "bg-red-50/60 dark:bg-red-950/20")}
                              onClick={(e) => { if ((e.target as HTMLElement).closest("a,button")) return; if (e.ctrlKey || e.metaKey || e.shiftKey) return; router.push(`/deals/${r.deal.id}`); }}
                              data-testid={`trip-card-${r.deal.id}`}>
                      <TableCell className="px-3 py-2">
                        <Link href={`/deals/${r.deal.id}`} className="block max-w-[220px] truncate font-medium leading-tight hover:underline">{r.customer?.name ?? r.deal.name}</Link>
                        <div className="max-w-[220px] truncate text-[11px] text-muted-foreground">{r.deal.destination ?? "—"} · <span className="font-mono">{r.deal.deal_no}</span></div>
                      </TableCell>
                      <TableCell className="whitespace-nowrap px-3 py-2">
                        <div className="text-xs font-semibold">{r.deal.travel_date ? fmtDate(r.deal.travel_date) : "No date"}</div>
                        <div className={cn("text-[11px]", soon ? "font-semibold text-red-600 dark:text-red-400" : "text-muted-foreground")}>{daysLabel(r.days_left)}</div>
                      </TableCell>
                      <TableCell className="min-w-[170px] px-3 py-2">
                        <div className="flex items-center gap-2">
                          <div className="w-20 shrink-0"><ProgressBar percent={r.progress.percent} tone={r.progress.overdue > 0 ? "warn" : "default"} /></div>
                          <span className="whitespace-nowrap text-xs">{r.progress.done}/{r.progress.total}</span>
                          {r.progress.overdue > 0 && <span className="inline-flex items-center gap-0.5 whitespace-nowrap text-[11px] font-semibold text-red-600 dark:text-red-400"><AlertTriangle size={11} />{r.progress.overdue}</span>}
                        </div>
                        {r.progress.next && <div className="mt-0.5 max-w-[200px] truncate text-[11px] text-muted-foreground" title={r.progress.next.title}>Next: {r.progress.next.title}{r.progress.next.due_date ? ` · ${fmtDate(r.progress.next.due_date)}` : ""}</div>}
                      </TableCell>
                      <TableCell className="px-3 py-2" data-testid={`trip-ready-${r.deal.id}`}>
                        <div className={cn("text-xs font-semibold", r.readiness.ready ? "text-emerald-700 dark:text-emerald-400" : "text-muted-foreground")}>{r.readiness.ready ? "Ready" : "Not ready"}</div>
                        {!r.readiness.ready && <div className="max-w-[190px] truncate text-[11px] text-muted-foreground" title={r.readiness.blockers.join("; ")}>{r.readiness.blockers[0]}{r.readiness.blockers.length > 1 ? ` (+${r.readiness.blockers.length - 1})` : ""}</div>}
                      </TableCell>
                      <TableCell className="px-3 py-2 text-xs" data-testid={`trip-docs-${r.deal.id}`}>
                        <div>{docs}</div>
                        {docsNote && <div className="text-[11px] text-muted-foreground">{docsNote}</div>}
                      </TableCell>
                      <TableCell className="whitespace-nowrap px-3 py-2 text-right">
                        <PaymentChip p={r.payment} />
                        <div className={cn("mt-0.5 text-[11px]", r.payment.balance > 0 ? "font-semibold" : "text-muted-foreground", r.payment.overdue && "text-red-600 dark:text-red-400")}>{r.payment.balance > 0 ? `${INR(r.payment.balance)} balance${r.payment.overdue ? " · overdue" : ""}` : "Settled"}</div>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
          <Pagination {...pg.paginationProps} />
        </Card>
      )}
    </div>
  );
}
