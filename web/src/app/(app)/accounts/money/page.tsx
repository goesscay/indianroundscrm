"use client";

import Link from "next/link";
import { Suspense, useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { usePagedList } from "@/lib/usePagedList";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import MoneyDialog from "@/components/crm/MoneyDialog";
import { INR2, fmtDate, modeLabel } from "@/components/crm/shared";
import type { MoneyEntryRow } from "@/types";

function MoneyList() {
  const { can } = useCurrentUser();
  const [kind, setKind] = useState<"all" | "expense" | "income">("all");
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [dlg, setDlg] = useState<"expense" | "income" | null>(null);
  const [tick, setTick] = useState(0);
  const [totals, setTotals] = useState<{ expense: number; income: number } | null>(null);

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (kind !== "all") p.kind = kind;
    if (applied.trim()) p.q = applied.trim();
    return p;
  }, [kind, applied]);
  const { rows, loading, loaded, error, paginationProps } = usePagedList<MoneyEntryRow>("/finance/money", params, tick);
  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);
  useEffect(() => {
    api.get<{ totals: { expense: number; income: number } }>("/finance/money", { params: { page: 1, per_page: 1 } }).then((r) => setTotals(r.data.totals)).catch(() => setTotals(null));
  }, [tick, rows.length]);

  const cancel = async (m: MoneyEntryRow) => {
    const reason = window.prompt(`Why is ${m.entry_no} being cancelled?`);
    if (!reason || !reason.trim()) return;
    try { await api.post(`/finance/money/${m.id}/cancel`, { reason: reason.trim() }); toast.success("Entry cancelled"); setTick((t) => t + 1); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  const canWrite = can("finance.payables");

  return (
    <div className="space-y-6" data-testid="money-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="font-display text-2xl font-black tracking-tight">Money in and out</h2>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">Running costs and other income. Customer payments live in <Link href="/receipts" className="underline underline-offset-2">Receipts</Link> and supplier payments on each <Link href="/accounts/bills" className="underline underline-offset-2">bill</Link>.</p>
        </div>
        {canWrite && <div className="flex gap-2"><Button variant="outline" onClick={() => setDlg("income")} data-testid="record-income-btn">Record money in</Button><Button onClick={() => setDlg("expense")} data-testid="record-expense-btn">Record money out</Button></div>}
      </div>

      {totals && (
        <div className="grid grid-cols-2 gap-3" data-testid="money-totals">
          <Card className="rounded-lg p-4"><div className="label-caps">Money out</div><div className="mt-1 font-display text-2xl font-black">{INR2(totals.expense)}</div></Card>
          <Card className="rounded-lg p-4"><div className="label-caps">Money in</div><div className="mt-1 font-display text-2xl font-black text-emerald-600 dark:text-emerald-400">{INR2(totals.income)}</div></Card>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <div className="inline-flex rounded border border-border p-0.5" role="group" aria-label="Show">
          {([["all", "All"], ["expense", "Money out"], ["income", "Money in"]] as const).map(([k, l]) => (
            <button key={k} type="button" aria-pressed={kind === k} onClick={() => setKind(k)} data-testid={`money-filter-${k}`}
                    className={cn("rounded px-3 py-1.5 text-sm", kind === k ? "bg-foreground text-background" : "text-muted-foreground hover:text-foreground")}>{l}</button>
          ))}
        </div>
        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search number, payee, reference…" className="h-10 max-w-[320px]" data-testid="money-search" />
      </div>

      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader><TableRow><TableHead>Entry</TableHead><TableHead>Date</TableHead><TableHead>Account</TableHead><TableHead>Details</TableHead><TableHead className="text-right">Amount</TableHead><TableHead /></TableRow></TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={6} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && <TableRow><TableCell colSpan={6} className="py-12 text-center text-muted-foreground">Nothing recorded yet.</TableCell></TableRow>}
              {rows.map((m) => (
                <TableRow key={m.id} data-testid={`money-row-${m.id}`}>
                  <TableCell><div className={cn("font-mono font-medium", m.status === "cancelled" && "line-through text-muted-foreground")}>{m.entry_no}</div>{m.status === "cancelled" && <div className="text-xs text-muted-foreground">Cancelled</div>}</TableCell>
                  <TableCell className="text-sm">{fmtDate(m.entry_date)}</TableCell>
                  <TableCell className="text-sm">{m.account?.name}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{[m.payee, m.reference, modeLabel(m.mode)].filter(Boolean).join(" · ")}</TableCell>
                  <TableCell className={cn("text-right font-semibold", m.kind === "income" && "text-emerald-600 dark:text-emerald-400")}>{m.kind === "income" ? "+ " : "− "}{INR2(m.amount)}</TableCell>
                  <TableCell className="text-right">{canWrite && m.status === "valid" && <Button size="sm" variant="ghost" onClick={() => void cancel(m)} data-testid={`cancel-money-${m.id}`}>Cancel</Button>}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
      {error && <div className="text-sm text-destructive">{error}</div>}
      <MoneyDialog open={dlg !== null} onOpenChange={(o) => !o && setDlg(null)} kind={dlg ?? "expense"} onSaved={() => setTick((t) => t + 1)} />
    </div>
  );
}

export default function MoneyPage() {
  return <Suspense fallback={null}><MoneyList /></Suspense>;
}
