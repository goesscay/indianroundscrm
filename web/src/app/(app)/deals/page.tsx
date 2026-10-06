"use client";

import { useRouter } from "next/navigation";
import { Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { Columns3, Download, List as ListIcon, Search, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { fetchAll, usePagedList } from "@/lib/usePagedList";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import DealDialog from "@/components/crm/DealDialog";
import { INR, STAGES, StagePill, fmtDate, inrShort, stageMeta, useCrmOptions } from "@/components/crm/shared";
import type { Deal, DealStage } from "@/types";

type View = "board" | "list";
const VIEW_KEY = "ir-deals-view";

function Board({ params, tick, onChanged, onNew }: { params: Record<string, string>; tick: number; onChanged: () => void; onNew: (s: DealStage) => void }) {
  const router = useRouter();
  const { can } = useCurrentUser();
  const canMove = can("deals.edit");
  const [deals, setDeals] = useState<Deal[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState("");
  const [dragId, setDragId] = useState<string | null>(null);
  const [over, setOver] = useState<DealStage | null>(null);

  const load = useCallback(async () => {
    try { setDeals(await fetchAll<Deal>("/deals", params)); setError(""); }
    catch (e) { setError(formatApiError(e)); }
    finally { setLoaded(true); }
  }, [params]);
  useEffect(() => { void load(); }, [load, tick]);

  const move = async (deal: Deal, stage: DealStage) => {
    if (deal.stage === stage) return;
    let reason: string | undefined;
    if (stage === "lost") {
      const r = window.prompt("Why was this deal lost?");
      if (!r || !r.trim()) return;
      reason = r.trim();
    }
    const prev = deals;
    setDeals((l) => l.map((d) => (d.id === deal.id ? { ...d, stage } : d))); // optimistic
    try {
      await api.patch(`/deals/${deal.id}`, { stage, ...(reason ? { lost_reason: reason } : {}) });
      onChanged();
      await load();
    } catch (e) { setDeals(prev); toast.error(formatApiError(e)); }
  };

  return (
    <div data-testid="deals-board">
      {error && <div className="mb-3 text-sm text-destructive">{error}</div>}
      <div className="flex gap-3 overflow-x-auto pb-3">
        {STAGES.map((s) => {
          const col = deals.filter((d) => d.stage === s.value);
          const total = col.reduce((a, d) => a + d.selling_amount, 0);
          const weighted = col.reduce((a, d) => a + d.weighted, 0);
          return (
            <section key={s.value}
                     onDragOver={(e) => { if (canMove && dragId) { e.preventDefault(); setOver(s.value); } }}
                     onDragLeave={() => setOver((o) => (o === s.value ? null : o))}
                     onDrop={(e) => { e.preventDefault(); setOver(null); const d = deals.find((x) => x.id === dragId); setDragId(null); if (d) void move(d, s.value); }}
                     className={cn("flex w-[272px] shrink-0 flex-col rounded-lg border border-border bg-secondary/40 transition-colors", over === s.value && "border-foreground/50 bg-secondary")}
                     data-testid={`board-col-${s.value}`} aria-label={`${s.label} deals`}>
              <header className="border-b border-border px-3 py-2.5">
                <div className="flex items-center justify-between">
                  <span className="flex items-center gap-2 text-sm font-semibold"><span className={cn("h-2 w-2 rounded-full", s.dot)} aria-hidden />{s.label}</span>
                  <span className="text-xs text-muted-foreground">{col.length}</span>
                </div>
                <div className="mt-0.5 text-xs text-muted-foreground">{inrShort(total)}{s.value !== "won" && s.value !== "lost" ? ` · ${inrShort(weighted)} weighted` : ""}</div>
              </header>
              <div className="flex min-h-[120px] flex-1 flex-col gap-2 p-2">
                {!loaded && <div className="p-2 text-xs text-muted-foreground">Loading…</div>}
                {loaded && col.length === 0 && <div className="rounded border border-dashed border-border p-4 text-center text-xs text-muted-foreground">{canMove ? "Drop a deal here" : "No deals"}</div>}
                {col.map((d) => (
                  <Card key={d.id} draggable={canMove}
                        onDragStart={() => setDragId(d.id)} onDragEnd={() => { setDragId(null); setOver(null); }}
                        onClick={() => router.push(`/deals/${d.id}`)}
                        className={cn("cursor-pointer rounded-md p-3 shadow-none transition-shadow hover:shadow-md", dragId === d.id && "opacity-50")}
                        data-testid={`deal-card-${d.id}`}>
                    <div className="truncate text-sm font-semibold">{d.name}</div>
                    <div className="mt-0.5 truncate text-xs text-muted-foreground">{d.customer?.name}</div>
                    <div className="mt-2 flex items-center justify-between text-xs">
                      <span className="font-semibold">{inrShort(d.selling_amount)}</span>
                      {d.expected_close && <span className={cn(d.overdue ? "font-medium text-red-600 dark:text-red-400" : "text-muted-foreground")}>{d.overdue ? "Overdue · " : ""}{fmtDate(d.expected_close).replace(/ \d{4}$/, "")}</span>}
                    </div>
                    {s.value === "won" && d.trip_status && <div className="mt-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">{d.trip_status.replace("_", " ")}</div>}
                  </Card>
                ))}
                {can("deals.create") && s.value !== "won" && s.value !== "lost" && (
                  <button type="button" onClick={() => onNew(s.value)} className="rounded border border-dashed border-transparent px-2 py-1.5 text-left text-xs text-muted-foreground hover:border-border hover:text-foreground">+ Add deal</button>
                )}
              </div>
            </section>
          );
        })}
      </div>
    </div>
  );
}

function DealTable({ params, tick }: { params: Record<string, string>; tick: number }) {
  const router = useRouter();
  const { rows, loading, loaded, error, paginationProps } = usePagedList<Deal>("/deals", params, tick);
  return (
    <Card className="overflow-hidden rounded-lg" data-testid="deals-list">
      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Deal</TableHead><TableHead>Customer</TableHead><TableHead>Stage</TableHead><TableHead>Travel</TableHead>
              <TableHead className="text-right">Selling</TableHead><TableHead className="text-right">Margin</TableHead>
              <TableHead>Expected close</TableHead><TableHead>Owner</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
            {loading && !loaded && <TableRow><TableCell colSpan={8} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
            {loaded && rows.length === 0 && <TableRow><TableCell colSpan={8} className="py-12 text-center text-muted-foreground">No deals found.</TableCell></TableRow>}
            {rows.map((d) => (
              <TableRow key={d.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => router.push(`/deals/${d.id}`)} data-testid={`deal-row-${d.id}`}>
                <TableCell><div className="font-medium">{d.name}</div><div className="font-mono text-xs text-muted-foreground">{d.deal_no}</div></TableCell>
                <TableCell className="text-sm">{d.customer?.name}</TableCell>
                <TableCell><StagePill stage={d.stage} /></TableCell>
                <TableCell className="text-xs text-muted-foreground">{fmtDate(d.travel_date)}</TableCell>
                <TableCell className="text-right font-semibold">{INR(d.selling_amount)}</TableCell>
                <TableCell className={cn("text-right", d.margin >= 0 ? "text-[#0F766E] dark:text-emerald-400" : "text-[#B91C1C] dark:text-red-400")}>{d.supplier_cost ? INR(d.margin) : "—"}</TableCell>
                <TableCell className={cn("text-xs", d.overdue ? "font-medium text-red-600 dark:text-red-400" : "text-muted-foreground")}>{fmtDate(d.expected_close)}</TableCell>
                <TableCell className="text-xs text-muted-foreground">{d.owner?.name ?? "Unassigned"}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <Pagination {...paginationProps} />
      {error && <div className="p-3 text-sm text-destructive">{error}</div>}
    </Card>
  );
}

function DealsPage() {
  const { can } = useCurrentUser();
  const options = useCrmOptions();
  const [view, setView] = useState<View>("board");
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [owner, setOwner] = useState("all");
  const [close, setClose] = useState("all");
  const [tick, setTick] = useState(0);
  const [dlg, setDlg] = useState<{ open: boolean; stage: DealStage }>({ open: false, stage: "enquiry" });
  const [exportErr, setExportErr] = useState("");

  useEffect(() => { try { const v = localStorage.getItem(VIEW_KEY); if (v === "list" || v === "board") setView(v); } catch { /* storage unavailable */ } }, []);
  const pick = (v: View) => { setView(v); try { localStorage.setItem(VIEW_KEY, v); } catch { /* storage unavailable */ } };
  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (owner !== "all") p.owner = owner;
    if (close !== "all") p.close = close;
    return p;
  }, [applied, owner, close]);
  const active = (q.trim() ? 1 : 0) + [owner, close].filter((v) => v !== "all").length;
  const bump = () => setTick((t) => t + 1);

  const exportCsv = async () => {
    setExportErr("");
    try {
      const all = await fetchAll<Deal>("/deals", params);
      const headers = ["Deal No", "Name", "Customer", "Stage", "Destination", "Travel date", "Selling", "Supplier cost", "Margin", "Expected close", "Owner"];
      const body = all.map((d) => [d.deal_no, d.name, d.customer?.name, stageMeta(d.stage).label, d.destination, d.travel_date, d.selling_amount, d.supplier_cost, d.margin, d.expected_close, d.owner?.name]);
      const csv = [headers, ...body].map((r) => r.map((v) => `"${(v ?? "").toString().replace(/"/g, '""')}"`).join(",")).join("\n");
      const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
      a.download = `deals-${Date.now()}.csv`; a.click();
    } catch (e) { setExportErr(formatApiError(e)); }
  };

  return (
    <div className="space-y-6" data-testid="deals-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="label-caps">Sales</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">Deals</h1>
          <p className="mt-1 text-sm text-muted-foreground">The trips you are selling, stage by stage.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={exportCsv} data-testid="export-deals"><Download size={16} /> Export CSV</Button>
          {can("deals.create") && <Button onClick={() => setDlg({ open: true, stage: "enquiry" })} data-testid="new-deal-btn">+ New deal</Button>}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3" data-testid="deals-filters">
        <div className="inline-flex rounded border border-border p-0.5" role="group" aria-label="View">
          <button type="button" onClick={() => pick("board")} aria-pressed={view === "board"} data-testid="view-board"
                  className={cn("inline-flex items-center gap-1.5 rounded px-2.5 py-1.5 text-sm", view === "board" ? "bg-foreground text-background" : "text-muted-foreground hover:text-foreground")}><Columns3 size={14} /> Board</button>
          <button type="button" onClick={() => pick("list")} aria-pressed={view === "list"} data-testid="view-list"
                  className={cn("inline-flex items-center gap-1.5 rounded px-2.5 py-1.5 text-sm", view === "list" ? "bg-foreground text-background" : "text-muted-foreground hover:text-foreground")}><ListIcon size={14} /> List</button>
        </div>
        <div className="relative min-w-[220px] max-w-[340px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === "Enter" && setApplied(q)} placeholder="Search deals, customers, destinations…" className="h-10 pl-9" data-testid="deal-search" />
        </div>
        <Select value={close} onValueChange={setClose}>
          <SelectTrigger className="h-10 w-[170px]" data-testid="filter-deal-close"><SelectValue placeholder="Any close date" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Any close date</SelectItem>
            <SelectItem value="overdue">Overdue</SelectItem>
            <SelectItem value="this_month">This month</SelectItem>
            <SelectItem value="next_month">Next month</SelectItem>
          </SelectContent>
        </Select>
        {can("deals.view_all") && (
          <Select value={owner} onValueChange={setOwner}>
            <SelectTrigger className="h-10 w-[170px]" data-testid="filter-deal-owner"><SelectValue placeholder="Everyone's" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Everyone&apos;s</SelectItem>
              <SelectItem value="unassigned">Unassigned</SelectItem>
              {(options?.owners ?? []).map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}
            </SelectContent>
          </Select>
        )}
        {active > 0 && <Button variant="ghost" size="sm" onClick={() => { setQ(""); setApplied(""); setOwner("all"); setClose("all"); }}><X size={14} /> Clear filters</Button>}
      </div>

      {view === "board"
        ? <Board params={params} tick={tick} onChanged={() => { /* board reloads itself */ }} onNew={(s) => setDlg({ open: true, stage: s })} />
        : <DealTable params={params} tick={tick} />}
      {exportErr && <div className="text-sm text-destructive">{exportErr}</div>}

      <DealDialog open={dlg.open} onOpenChange={(o) => setDlg((d) => ({ ...d, open: o }))} stage={dlg.stage} onSaved={bump} />
    </div>
  );
}

export default function Page() {
  return <Suspense fallback={null}><DealsPage /></Suspense>;
}
