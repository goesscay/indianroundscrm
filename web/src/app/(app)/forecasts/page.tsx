"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { AlertTriangle, ChevronLeft, ChevronRight, Loader2, Pencil } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { CustomerLink, INR, StagePill, fmtDate, inrShort } from "@/components/crm/shared";
import type { Deal, Forecast, ForecastQuarter } from "@/types";

type Cell = { kind: "won" | "open" | "overdue" | "undated"; quarter: number };
const cellLabel = (c: Cell) => ({ won: "Won", open: "Expected to close", overdue: "Past their expected close date", undated: "No expected close date" }[c.kind]);

function Kpi({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: string }) {
  return (
    <Card className="rounded-lg p-4">
      <div className="label-caps">{label}</div>
      <div className={cn("mt-1 font-display text-2xl font-black tracking-tight", tone)}>{value}</div>
      {sub && <div className="text-xs text-muted-foreground">{sub}</div>}
    </Card>
  );
}

/** Target / won / weighted-pipeline bars per quarter, drawn with plain divs so they follow the theme. */
function QuarterChart({ quarters }: { quarters: ForecastQuarter[] }) {
  const max = Math.max(1, ...quarters.flatMap((q) => [q.target, q.won, q.weighted]));
  const bar = (v: number, cls: string, label: string) => (
    <div className="flex h-full w-5 items-end sm:w-7" title={`${label}: ${INR(v)}`}>
      <div className={cn("w-full rounded-t", cls)} style={{ height: `${Math.max(v > 0 ? 3 : 0, (v / max) * 100)}%` }} />
    </div>
  );
  return (
    <Card className="rounded-lg p-5" data-testid="forecast-chart">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-display font-bold">Quarter by quarter</h3>
        <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
          <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm border border-foreground/60" /> Target</span>
          <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-emerald-500" /> Won</span>
          <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-sky-500/70" /> Weighted pipeline</span>
        </div>
      </div>
      <div className="grid grid-cols-4 gap-3">
        {quarters.map((q) => (
          <div key={q.quarter} className="flex flex-col items-center">
            <div className="flex h-40 items-end gap-1">
              {bar(q.target, "border border-foreground/60 bg-transparent", "Target")}
              {bar(q.won, "bg-emerald-500", "Won")}
              {bar(q.weighted, "bg-sky-500/70", "Weighted pipeline")}
            </div>
            <div className={cn("mt-2 text-sm font-medium", q.current && "underline underline-offset-4")}>{q.label}</div>
            <div className="text-[11px] text-muted-foreground">{q.months}</div>
          </div>
        ))}
      </div>
    </Card>
  );
}

function TargetDialog({ open, onOpenChange, fy, quarter, consultants, defaultOwner, quarters, onSaved }: {
  open: boolean; onOpenChange: (o: boolean) => void; fy: number; quarter: number; consultants: { id: string; name: string }[];
  defaultOwner: string; quarters: ForecastQuarter[]; onSaved: () => void;
}) {
  const [who, setWho] = useState("team");
  const [q, setQ] = useState(quarter);
  const [amount, setAmount] = useState("");
  const [margin, setMargin] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setWho(defaultOwner === "all" ? "team" : defaultOwner); setQ(quarter); setError("");
  }, [open, quarter, defaultOwner]);
  // Prefill with the target currently shown for that quarter when the scope matches the page.
  useEffect(() => {
    if (!open) return;
    const cur = quarters.find((x) => x.quarter === q);
    const same = (defaultOwner === "all" && who === "team") || who === defaultOwner;
    setAmount(same && cur?.target ? String(cur.target) : ""); setMargin(same && cur?.margin_target ? String(cur.margin_target) : "");
  }, [open, q, who, quarters, defaultOwner]);

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setSaving(true); setError("");
    try {
      await api.put("/forecasts/targets", { fy, quarter: q, user_id: who === "team" ? null : who, amount: Number(amount) || 0, margin_amount: margin.trim() === "" ? null : Number(margin) });
      toast.success(Number(amount) > 0 ? "Target saved" : "Target cleared");
      onSaved(); onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-w-md" data-testid="target-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">Set target</DialogTitle>
          <DialogDescription>Revenue you aim to win in the quarter. Leave the amount empty to clear it.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Quarter</Label>
              <Select value={String(q)} onValueChange={(v) => setQ(Number(v))}>
                <SelectTrigger className="mt-1.5 h-10"><SelectValue /></SelectTrigger>
                <SelectContent>{quarters.map((x) => <SelectItem key={x.quarter} value={String(x.quarter)}>{x.label} · {x.months}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <div>
              <Label>For</Label>
              <Select value={who} onValueChange={setWho}>
                <SelectTrigger className="mt-1.5 h-10" data-testid="target-who"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="team">Whole team</SelectItem>
                  {consultants.map((c) => <SelectItem key={c.id} value={c.id}>{c.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div><Label htmlFor="t-amt">Revenue target (₹)</Label><Input id="t-amt" type="number" min={0} className="mt-1.5 h-10" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus data-testid="target-amount" /></div>
          <div><Label htmlFor="t-mg">Margin target (₹, optional)</Label><Input id="t-mg" type="number" min={0} className="mt-1.5 h-10" value={margin} onChange={(e) => setMargin(e.target.value)} /></div>
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="target-save">{saving && <Loader2 size={14} className="animate-spin" />} Save target</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function ForecastsPage() {
  const [fy, setFy] = useState<number | null>(null);
  const [owner, setOwner] = useState("all");
  const [data, setData] = useState<Forecast | null>(null);
  const [error, setError] = useState("");
  const [cell, setCell] = useState<Cell | null>(null);
  const [cellDeals, setCellDeals] = useState<Deal[] | null>(null);
  const [tgt, setTgt] = useState<{ open: boolean; quarter: number }>({ open: false, quarter: 1 });

  const load = useCallback(async () => {
    try {
      const { data: d } = await api.get<Forecast>("/forecasts", { params: { ...(fy ? { fy } : {}), owner } });
      setData(d); setError("");
      if (fy === null) setFy(d.fy);
    } catch (e) { setError(formatApiError(e)); }
  }, [fy, owner]);
  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    if (!cell || !data) { setCellDeals(null); return; }
    setCellDeals(null);
    api.get<Deal[]>("/forecasts/deals", { params: { fy: data.fy, owner, kind: cell.kind, ...(cell.kind !== "undated" ? { quarter: cell.quarter } : {}) } })
      .then((r) => setCellDeals(r.data)).catch((e) => { toast.error(formatApiError(e)); setCellDeals([]); });
  }, [cell, data?.fy, owner]); // eslint-disable-line react-hooks/exhaustive-deps

  const tot = data?.totals;
  const pick = (c: Cell) => setCell((cur) => (cur && cur.kind === c.kind && cur.quarter === c.quarter ? null : c));
  const maxPct = useMemo(() => (tot?.pct ?? 0), [tot]);

  return (
    <div className="space-y-6" data-testid="forecasts-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="label-caps">Sales</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">Forecasts</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">Your sales target for each quarter against what you have won. A win counts in the quarter it was closed; open deals count in the quarter of their expected close date.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {data?.can_view_all && (
            <Select value={owner} onValueChange={(v) => { setOwner(v); setCell(null); }}>
              <SelectTrigger className="h-10 w-[180px]" data-testid="forecast-owner"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Whole team</SelectItem>
                {data.consultants.map((c) => <SelectItem key={c.id} value={c.id}>{c.name}</SelectItem>)}
              </SelectContent>
            </Select>
          )}
          <div className="inline-flex items-center rounded border border-border" data-testid="fy-switcher">
            <Button variant="ghost" size="icon" aria-label="Previous financial year" onClick={() => data && setFy(data.fy - 1)}><ChevronLeft size={16} /></Button>
            <span className="min-w-[110px] px-2 text-center text-sm font-medium" data-testid="fy-label">{data?.label ?? "…"}</span>
            <Button variant="ghost" size="icon" aria-label="Next financial year" onClick={() => data && setFy(data.fy + 1)}><ChevronRight size={16} /></Button>
          </div>
          {data?.can_set_targets && <Button variant="outline" onClick={() => setTgt({ open: true, quarter: data.quarters.find((q) => q.current)?.quarter ?? 1 })} data-testid="set-target-btn"><Pencil size={15} /> Set target</Button>}
        </div>
      </div>

      {error && <div className="text-sm text-destructive">{error}</div>}
      {!data && !error && <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>}

      {data && tot && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="forecast-kpis">
            <Kpi label={`Target · ${data.label}`} value={tot.target ? INR(tot.target) : "Not set"} sub={tot.target ? undefined : data.can_set_targets ? "Use Set target" : undefined} />
            <Kpi label="Won" value={INR(tot.won)} sub={`${tot.won_count} deal${tot.won_count === 1 ? "" : "s"}${tot.pct != null ? ` · ${tot.pct}% of target` : ""}`} tone="text-emerald-600 dark:text-emerald-400" />
            <Kpi label="Still in the pipeline" value={INR(tot.open)} sub={`${INR(tot.weighted)} weighted by chance of winning`} />
            <Kpi label="Margin won" value={INR(tot.won_margin)} sub="selling less supplier cost" />
          </div>

          {tot.target > 0 && (
            <div aria-label="Progress to target" role="progressbar" aria-valuenow={Math.min(100, maxPct)} aria-valuemin={0} aria-valuemax={100} className="h-2 overflow-hidden rounded-full bg-secondary">
              <div className="h-full rounded-full bg-emerald-500" style={{ width: `${Math.min(100, maxPct)}%` }} />
            </div>
          )}

          {tot.overdue_count > 0 && (
            <div className="flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/40" role="status" data-testid="forecast-overdue">
              <AlertTriangle size={18} className="shrink-0 text-amber-700 dark:text-amber-300" />
              <div className="min-w-0 flex-1 text-amber-900 dark:text-amber-200">
                <span className="font-semibold">{tot.overdue_count} open deal{tot.overdue_count === 1 ? " is" : "s are"} past the expected close date ({INR(tot.overdue_value)}).</span>{" "}
                They still count in the quarter of their old date. Give them new dates, or mark them won or lost.
              </div>
              <Link href="/deals?close=overdue" className="font-medium underline underline-offset-2" data-testid="forecast-review">Review them</Link>
            </div>
          )}

          <QuarterChart quarters={data.quarters} />

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid="forecast-quarters">
            {data.quarters.map((q) => {
              const pct = q.target > 0 ? Math.round((q.won / q.target) * 100) : null;
              const active = (k: Cell["kind"]) => cell?.kind === k && cell.quarter === q.quarter;
              return (
                <Card key={q.quarter} className={cn("rounded-lg p-4", q.current && "ring-1 ring-foreground/40")} data-testid={`quarter-${q.quarter}`}>
                  <div className="flex items-start justify-between">
                    <div>
                      <div className="font-display text-lg font-black">{q.label} <span className="text-sm font-normal text-muted-foreground">· {q.months}</span></div>
                      {q.current && <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">This quarter</div>}
                    </div>
                    {data.can_set_targets && <button type="button" onClick={() => setTgt({ open: true, quarter: q.quarter })} className="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground" aria-label={`${q.target ? "Edit" : "Set"} ${q.label} target`} data-testid={`edit-target-${q.quarter}`}><Pencil size={14} /></button>}
                  </div>
                  <div className="mt-3 text-2xl font-black tracking-tight">{INR(q.won)}</div>
                  <div className="text-xs text-muted-foreground">{q.target > 0 ? `of ${INR(q.target)} · ${pct}%` : "No target set"}</div>
                  {q.target > 0 && <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-secondary"><div className="h-full rounded-full bg-emerald-500" style={{ width: `${Math.min(100, pct ?? 0)}%` }} /></div>}
                  <dl className="mt-3 space-y-1 text-sm">
                    <button type="button" disabled={!q.won_count} onClick={() => pick({ kind: "won", quarter: q.quarter })} aria-pressed={active("won")} data-testid={`cell-won-${q.quarter}`}
                            className={cn("flex w-full justify-between rounded px-1.5 py-1 text-left", q.won_count ? "hover:bg-secondary" : "opacity-60", active("won") && "bg-secondary")}>
                      <dt className="text-muted-foreground">Won</dt><dd>{q.won_count ? `${q.won_count} · ${inrShort(q.won)}` : "None yet"}</dd>
                    </button>
                    <button type="button" disabled={!q.open_count} onClick={() => pick({ kind: "open", quarter: q.quarter })} aria-pressed={active("open")} data-testid={`cell-open-${q.quarter}`}
                            className={cn("flex w-full justify-between rounded px-1.5 py-1 text-left", q.open_count ? "hover:bg-secondary" : "opacity-60", active("open") && "bg-secondary")}>
                      <dt className="text-muted-foreground">Expected to close</dt><dd>{q.open_count ? `${q.open_count} · ${inrShort(q.open)}` : "None"}</dd>
                    </button>
                    <div className="flex justify-between px-1.5 py-1"><dt className="text-muted-foreground">Weighted</dt><dd>{inrShort(q.weighted)}</dd></div>
                    {q.overdue_count > 0 && (
                      <button type="button" onClick={() => pick({ kind: "overdue", quarter: q.quarter })} aria-pressed={active("overdue")} data-testid={`cell-overdue-${q.quarter}`}
                              className={cn("flex w-full justify-between rounded px-1.5 py-1 text-left text-red-700 hover:bg-secondary dark:text-red-400", active("overdue") && "bg-secondary")}>
                        <dt>Overdue</dt><dd>{q.overdue_count} · {inrShort(q.overdue_value)}</dd>
                      </button>
                    )}
                  </dl>
                </Card>
              );
            })}
          </div>

          {data.undated.count > 0 && (
            <Card className="flex flex-wrap items-center justify-between gap-2 rounded-lg p-4 text-sm" data-testid="forecast-undated">
              <span><span className="font-semibold">{data.undated.count} open deal{data.undated.count === 1 ? " has" : "s have"} no expected close date</span> ({INR(data.undated.value)}). They are in the totals but not in any quarter.</span>
              <Button size="sm" variant="outline" onClick={() => pick({ kind: "undated", quarter: 0 })}>Show them</Button>
            </Card>
          )}

          {cell && (
            <Card className="overflow-hidden rounded-lg" data-testid="forecast-drill">
              <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <h3 className="font-display font-bold">{cellLabel(cell)}{cell.kind !== "undated" ? ` · Q${cell.quarter}` : ""}</h3>
                <Button size="sm" variant="ghost" onClick={() => setCell(null)}>Close</Button>
              </div>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader><TableRow><TableHead>Deal</TableHead><TableHead>Customer</TableHead><TableHead>Stage</TableHead><TableHead className="text-right">Selling</TableHead><TableHead className="text-right">Weighted</TableHead><TableHead>{cell.kind === "won" ? "Closed" : "Expected close"}</TableHead><TableHead>Owner</TableHead></TableRow></TableHeader>
                  <TableBody>
                    {cellDeals === null && <TableRow><TableCell colSpan={7} className="py-6 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
                    {cellDeals?.length === 0 && <TableRow><TableCell colSpan={7} className="py-6 text-center text-muted-foreground">No deals.</TableCell></TableRow>}
                    {cellDeals?.map((d) => (
                      <TableRow key={d.id} data-testid={`drill-deal-${d.id}`}>
                        <TableCell><Link href={`/deals/${d.id}`} className="font-medium hover:underline">{d.name}</Link><div className="font-mono text-xs text-muted-foreground">{d.deal_no}</div></TableCell>
                        <TableCell className="text-sm"><CustomerLink customer={d.customer} /></TableCell>
                        <TableCell><StagePill stage={d.stage} /></TableCell>
                        <TableCell className="text-right font-semibold">{INR(d.selling_amount)}</TableCell>
                        <TableCell className="text-right">{INR(d.weighted)}</TableCell>
                        <TableCell className={cn("text-xs", d.overdue ? "font-medium text-red-600 dark:text-red-400" : "text-muted-foreground")}>{fmtDate(cell.kind === "won" ? d.closed_at : d.expected_close)}</TableCell>
                        <TableCell className="text-xs text-muted-foreground">{d.owner?.name ?? "Unassigned"}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </Card>
          )}

          {data.owners.length > 0 && (
            <Card className="overflow-hidden rounded-lg" data-testid="forecast-owners">
              <div className="border-b border-border px-4 py-3"><h3 className="font-display font-bold">By consultant · {data.label}</h3></div>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader><TableRow><TableHead>Consultant</TableHead><TableHead className="text-right">Target</TableHead><TableHead className="text-right">Won</TableHead><TableHead className="text-right">% of target</TableHead><TableHead className="text-right">Open pipeline</TableHead><TableHead className="text-right">Weighted</TableHead></TableRow></TableHeader>
                  <TableBody>
                    {data.owners.map((o) => (
                      <TableRow key={o.id ?? "none"}>
                        <TableCell className="font-medium">{o.name}</TableCell>
                        <TableCell className="text-right">{o.target ? INR(o.target) : "—"}</TableCell>
                        <TableCell className="text-right font-semibold">{INR(o.won)} <span className="font-normal text-muted-foreground">({o.won_count})</span></TableCell>
                        <TableCell className="text-right">{o.target ? `${Math.round((o.won / o.target) * 100)}%` : "—"}</TableCell>
                        <TableCell className="text-right">{INR(o.open)} <span className="text-muted-foreground">({o.open_count})</span></TableCell>
                        <TableCell className="text-right">{INR(o.weighted)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </Card>
          )}

          <TargetDialog open={tgt.open} onOpenChange={(o) => setTgt((t) => ({ ...t, open: o }))} fy={data.fy} quarter={tgt.quarter}
                        consultants={data.consultants} defaultOwner={owner} quarters={data.quarters} onSaved={() => void load()} />
        </>
      )}
    </div>
  );
}
