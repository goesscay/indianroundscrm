"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useMemo, useState } from "react";
import { Search, X } from "lucide-react";
import { api } from "@/lib/api";
import { usePagedList } from "@/lib/usePagedList";
import { useCurrentUser } from "@/context/AuthContext";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import BillDialog from "@/components/crm/BillDialog";
import { INR2, PayPill, fmtDate } from "@/components/crm/shared";
import type { SupplierBill } from "@/types";

function BillList() {
  const router = useRouter();
  const sp = useSearchParams();
  const { can } = useCurrentUser();
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [status, setStatus] = useState(["unpaid", "overdue", "paid", "cancelled"].includes(sp.get("status") ?? "") ? sp.get("status")! : "all");
  const [open, setOpen] = useState(sp.get("new") === "1" && can("finance.payables"));
  const [tick, setTick] = useState(0);
  const [outstanding, setOutstanding] = useState<number | null>(null);
  const dealParam = sp.get("deal");
  const [deal, setDeal] = useState<{ id: string; deal_no: string; name: string } | null>(null);

  useEffect(() => {
    if (!dealParam) return;
    api.get<{ id: string; deal_no: string; name: string }>(`/deals/${dealParam}`).then((r) => setDeal({ id: r.data.id, deal_no: r.data.deal_no, name: r.data.name })).catch(() => setDeal(null));
  }, [dealParam]);

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (status !== "all") p.status = status;
    return p;
  }, [applied, status]);
  const { rows, loading, loaded, error, paginationProps } = usePagedList<SupplierBill>("/finance/bills", params, tick);
  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);
  useEffect(() => {
    api.get<{ outstanding: number }>("/finance/bills", { params: { page: 1, per_page: 1 } }).then((r) => setOutstanding(r.data.outstanding)).catch(() => setOutstanding(null));
  }, [tick, rows.length]);
  const active = (q.trim() ? 1 : 0) + (status !== "all" ? 1 : 0);

  return (
    <div className="space-y-6" data-testid="bills-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="font-display text-2xl font-black tracking-tight">Supplier bills</h2>
          <p className="mt-1 text-sm text-muted-foreground">What suppliers are charging you. Each bill is booked as a cost on the trip it belongs to, and you pay it when it is due.</p>
        </div>
        <div className="flex items-center gap-4">
          {outstanding !== null && <div className="text-right" data-testid="bills-outstanding"><div className="label-caps">You owe</div><div className="font-display text-2xl font-black">{INR2(outstanding)}</div></div>}
          {can("finance.payables") && <Button onClick={() => setOpen(true)} data-testid="new-bill-btn">+ New bill</Button>}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3" data-testid="bills-filters">
        <div className="relative min-w-[220px] max-w-[320px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search bill, supplier, reference…" className="h-10 pl-9" data-testid="bill-search" />
        </div>
        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="h-10 w-[170px]" data-testid="filter-bill-status"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">All bills</SelectItem><SelectItem value="unpaid">Still to pay</SelectItem><SelectItem value="overdue">Overdue</SelectItem><SelectItem value="paid">Paid</SelectItem><SelectItem value="cancelled">Cancelled</SelectItem></SelectContent>
        </Select>
        {active > 0 && <Button variant="ghost" size="sm" onClick={() => { setQ(""); setApplied(""); setStatus("all"); }}><X size={14} /> Clear filters</Button>}
      </div>

      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader><TableRow><TableHead>Bill</TableHead><TableHead>Supplier</TableHead><TableHead>Trip</TableHead><TableHead>Status</TableHead><TableHead className="text-right">Total</TableHead><TableHead className="text-right">Balance</TableHead><TableHead>Due</TableHead></TableRow></TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={7} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && <TableRow><TableCell colSpan={7} className="py-12 text-center text-muted-foreground">{active ? "No bills match these filters." : "No bills yet."}</TableCell></TableRow>}
              {rows.map((b) => (
                <TableRow key={b.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => router.push(`/accounts/bills/${b.id}`)} data-testid={`bill-row-${b.id}`}>
                  <TableCell><div className="font-mono font-medium">{b.bill_no}</div>{b.supplier_ref && <div className="text-xs text-muted-foreground">{b.supplier_ref}</div>}</TableCell>
                  <TableCell className="text-sm">{b.supplier?.name}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{b.deal?.deal_no ?? "—"}</TableCell>
                  <TableCell><PayPill status={b.payment_status === "open" ? "unpaid" : b.payment_status} /></TableCell>
                  <TableCell className="text-right font-semibold">{INR2(b.total)}</TableCell>
                  <TableCell className="text-right">{b.status === "cancelled" ? "—" : INR2(b.balance)}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{b.balance > 0 && b.status === "open" ? fmtDate(b.due_date) : "—"}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
      {error && <div className="text-sm text-destructive">{error}</div>}
      <BillDialog open={open} onOpenChange={setOpen} deal={deal} onSaved={(b) => { setTick((t) => t + 1); router.push(`/accounts/bills/${b.id}`); }} />
    </div>
  );
}

export default function BillsPage() {
  return <Suspense fallback={null}><BillList /></Suspense>;
}
