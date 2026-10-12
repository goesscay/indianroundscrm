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
import { CustomerLink, INR2, PayPill, fmtDate, useCrmOptions } from "@/components/crm/shared";
import type { Invoice } from "@/types";

function InvoiceList() {
  const router = useRouter();
  const { can } = useCurrentUser();
  const options = useCrmOptions();
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const sp = useSearchParams();
  const [status, setStatus] = useState(["draft", "unpaid", "overdue", "paid", "cancelled"].includes(sp.get("status") ?? "") ? sp.get("status")! : "all");
  const [owner, setOwner] = useState("all");
  const [outstanding, setOutstanding] = useState<number | null>(null);

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (status !== "all") p.status = status;
    if (owner !== "all") p.owner = owner;
    return p;
  }, [applied, status, owner]);
  const { rows, loading, loaded, error, paginationProps } = usePagedList<Invoice>("/invoices", params);
  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);
  // The outstanding total comes with the paged response; fetch it once for the headline figure.
  useEffect(() => {
    api.get<{ outstanding: number }>("/invoices", { params: { page: 1, per_page: 1 } }).then((r) => setOutstanding(r.data.outstanding)).catch(() => setOutstanding(null));
  }, [rows.length]);
  const active = (q.trim() ? 1 : 0) + [status, owner].filter((v) => v !== "all").length;

  return (
    <div className="space-y-6" data-testid="invoices-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="label-caps">Sales</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">Invoices</h1>
          <p className="mt-1 text-sm text-muted-foreground">What customers owe you. Issue an invoice, record payments as they come in, and credit it if a trip is cancelled.</p>
        </div>
        <div className="flex items-center gap-4">
          {outstanding !== null && (
            <div className="text-right" data-testid="invoices-outstanding"><div className="label-caps">Outstanding</div><div className="font-display text-2xl font-black">{INR2(outstanding)}</div></div>
          )}
          {can("invoices.create") && <Button onClick={() => router.push("/invoices/new")} data-testid="new-invoice-btn">+ New invoice</Button>}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3" data-testid="invoices-filters">
        <div className="relative min-w-[220px] max-w-[340px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === "Enter" && setApplied(q)} placeholder="Search invoice no., customer, title…" className="h-10 pl-9" data-testid="invoice-search" />
        </div>
        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="h-10 w-[170px]" data-testid="filter-invoice-status"><SelectValue placeholder="All invoices" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All invoices</SelectItem><SelectItem value="draft">Draft</SelectItem><SelectItem value="unpaid">Money outstanding</SelectItem>
            <SelectItem value="overdue">Overdue</SelectItem><SelectItem value="paid">Paid</SelectItem><SelectItem value="cancelled">Cancelled</SelectItem>
          </SelectContent>
        </Select>
        {can("invoices.view_all") && (
          <Select value={owner} onValueChange={setOwner}>
            <SelectTrigger className="h-10 w-[170px]"><SelectValue placeholder="Everyone's" /></SelectTrigger>
            <SelectContent><SelectItem value="all">Everyone&apos;s</SelectItem>{(options?.owners ?? []).map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}</SelectContent>
          </Select>
        )}
        {active > 0 && <Button variant="ghost" size="sm" onClick={() => { setQ(""); setApplied(""); setStatus("all"); setOwner("all"); }}><X size={14} /> Clear filters</Button>}
      </div>

      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow><TableHead>Invoice</TableHead><TableHead>Customer</TableHead><TableHead>Status</TableHead><TableHead className="text-right">Total</TableHead><TableHead className="text-right">Balance</TableHead><TableHead>Next due</TableHead><TableHead>Issued</TableHead></TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={7} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && <TableRow><TableCell colSpan={7} className="py-12 text-center text-muted-foreground">{active ? "No invoices match these filters." : "No invoices yet. Create one from an accepted quote or a deal."}</TableCell></TableRow>}
              {rows.map((x) => (
                <TableRow key={x.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => router.push(`/invoices/${x.id}`)} data-testid={`invoice-row-${x.id}`}>
                  <TableCell><div className="font-medium">{x.title}</div><div className="font-mono text-xs text-muted-foreground">{x.invoice_no ?? "Draft"}</div></TableCell>
                  <TableCell className="text-sm"><CustomerLink customer={x.customer} /></TableCell>
                  <TableCell><PayPill status={x.payment_status} /></TableCell>
                  <TableCell className="text-right font-semibold">{INR2(x.total)}</TableCell>
                  <TableCell className="text-right">{x.status === "issued" ? INR2(x.balance) : "—"}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{x.status === "issued" && x.balance > 0 ? fmtDate(x.next_due) : "—"}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{x.status === "draft" ? "—" : fmtDate(x.issue_date)}</TableCell>
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

export default function InvoicesPage() {
  return <Suspense fallback={null}><InvoiceList /></Suspense>;
}
