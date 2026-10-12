"use client";

import { useRouter } from "next/navigation";
import { Suspense, useEffect, useMemo, useState } from "react";
import { Search, X } from "lucide-react";
import { cn } from "@/lib/utils";
import { api } from "@/lib/api";
import { usePagedList } from "@/lib/usePagedList";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { CustomerLink, INR2, PAY_MODES, fmtDate, modeLabel } from "@/components/crm/shared";
import type { Receipt } from "@/types";

function ReceiptList() {
  const router = useRouter();
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [mode, setMode] = useState("all");
  const [view, setView] = useState("all");
  const [totals, setTotals] = useState<{ received: number; refunded: number; advances: number } | null>(null);

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (mode !== "all") p.mode = mode;
    if (view === "advances") p.advances = "1";
    else if (view === "refunds") p.kind = "refund";
    else if (view === "cancelled") p.status = "cancelled";
    return p;
  }, [applied, mode, view]);
  const { rows, loading, loaded, error, paginationProps } = usePagedList<Receipt>("/receipts", params);
  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);
  useEffect(() => {
    api.get<{ totals: { received: number; refunded: number; advances: number } }>("/receipts", { params: { page: 1, per_page: 1 } }).then((r) => setTotals(r.data.totals)).catch(() => setTotals(null));
  }, [rows.length]);
  const active = (q.trim() ? 1 : 0) + [mode, view].filter((v) => v !== "all").length;

  return (
    <div className="space-y-6" data-testid="receipts-page">
      <div>
        <div className="label-caps">Sales</div>
        <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">Receipts</h1>
        <p className="mt-1 text-sm text-muted-foreground">Every payment received and every refund paid. Record a payment from an invoice, or an advance from the customer&apos;s page.</p>
      </div>

      {totals && (
        <div className="grid grid-cols-3 gap-3" data-testid="receipt-totals">
          <Card className="rounded-lg p-4"><div className="label-caps">Received</div><div className="mt-1 font-display text-2xl font-black text-emerald-600 dark:text-emerald-400">{INR2(totals.received)}</div></Card>
          <Card className="rounded-lg p-4"><div className="label-caps">Refunded</div><div className="mt-1 font-display text-2xl font-black">{INR2(totals.refunded)}</div></Card>
          <Card className="rounded-lg p-4"><div className="label-caps">Advances not yet applied</div><div className="mt-1 font-display text-2xl font-black">{INR2(totals.advances)}</div></Card>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3" data-testid="receipts-filters">
        <div className="relative min-w-[220px] max-w-[340px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === "Enter" && setApplied(q)} placeholder="Search receipt, invoice, customer, reference…" className="h-10 pl-9" data-testid="receipt-search" />
        </div>
        <Select value={view} onValueChange={setView}>
          <SelectTrigger className="h-10 w-[170px]" data-testid="filter-receipt-view"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">All receipts</SelectItem><SelectItem value="advances">Advances only</SelectItem><SelectItem value="refunds">Refunds</SelectItem><SelectItem value="cancelled">Cancelled</SelectItem></SelectContent>
        </Select>
        <Select value={mode} onValueChange={setMode}>
          <SelectTrigger className="h-10 w-[160px]"><SelectValue placeholder="Any mode" /></SelectTrigger>
          <SelectContent><SelectItem value="all">Any mode</SelectItem>{PAY_MODES.map((m) => <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>)}</SelectContent>
        </Select>
        {active > 0 && <Button variant="ghost" size="sm" onClick={() => { setQ(""); setApplied(""); setMode("all"); setView("all"); }}><X size={14} /> Clear filters</Button>}
      </div>

      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader><TableRow><TableHead>Receipt</TableHead><TableHead>Date</TableHead><TableHead>Customer</TableHead><TableHead>Against</TableHead><TableHead>Mode</TableHead><TableHead className="text-right">Amount</TableHead></TableRow></TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={6} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && <TableRow><TableCell colSpan={6} className="py-12 text-center text-muted-foreground">{active ? "No receipts match these filters." : "No payments recorded yet."}</TableCell></TableRow>}
              {rows.map((r) => (
                <TableRow key={r.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => router.push(`/receipts/${r.id}`)} data-testid={`receipt-row-${r.id}`}>
                  <TableCell><div className={cn("font-mono font-medium", r.status === "cancelled" && "line-through text-muted-foreground")}>{r.receipt_no}</div>{r.kind === "refund" && <div className="text-xs text-amber-700 dark:text-amber-300">Refund</div>}{r.status === "cancelled" && <div className="text-xs text-muted-foreground">Cancelled</div>}</TableCell>
                  <TableCell className="text-sm">{fmtDate(r.received_date)}</TableCell>
                  <TableCell className="text-sm"><CustomerLink customer={r.customer} /></TableCell>
                  <TableCell className="text-sm">{r.invoice ? <span className="font-mono">{r.invoice.invoice_no}</span> : <span className="text-muted-foreground">Advance</span>}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{modeLabel(r.mode)}{r.reference ? ` · ${r.reference}` : ""}</TableCell>
                  <TableCell className={cn("text-right font-semibold", r.kind === "refund" && "text-amber-700 dark:text-amber-300")}>{r.kind === "refund" ? "− " : ""}{INR2(r.amount)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
      {error && <div className="text-sm text-destructive">{error}</div>}
    </div>
  );
}

export default function ReceiptsPage() {
  return <Suspense fallback={null}><ReceiptList /></Suspense>;
}
