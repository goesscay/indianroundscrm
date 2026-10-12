"use client";

import { useRouter } from "next/navigation";
import { Suspense, useEffect, useMemo, useState } from "react";
import { Search, X } from "lucide-react";
import { usePagedList } from "@/lib/usePagedList";
import { useCurrentUser } from "@/context/AuthContext";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { CustomerLink, INR2, QuotePill, fmtDate, useCrmOptions } from "@/components/crm/shared";
import type { Quote } from "@/types";

function QuoteList() {
  const router = useRouter();
  const { can } = useCurrentUser();
  const options = useCrmOptions();
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [status, setStatus] = useState("all");
  const [owner, setOwner] = useState("all");

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (status !== "all") p.status = status;
    if (owner !== "all") p.owner = owner;
    return p;
  }, [applied, status, owner]);
  const { rows, loading, loaded, error, paginationProps } = usePagedList<Quote>("/quotes", params);
  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);
  const active = (q.trim() ? 1 : 0) + [status, owner].filter((v) => v !== "all").length;

  return (
    <div className="space-y-6" data-testid="quotes-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="label-caps">Sales</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">Quotes</h1>
          <p className="mt-1 text-sm text-muted-foreground">Prices you have offered customers. Send a quote as a PDF by e-mail, then mark it accepted when they say yes.</p>
        </div>
        {can("quotes.create") && <Button onClick={() => router.push("/quotes/new")} data-testid="new-quote-btn">+ New quote</Button>}
      </div>

      <div className="flex flex-wrap items-center gap-3" data-testid="quotes-filters">
        <div className="relative min-w-[220px] max-w-[340px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === "Enter" && setApplied(q)} placeholder="Search quote no., customer, destination…" className="h-10 pl-9" data-testid="quote-search" />
        </div>
        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="h-10 w-[160px]" data-testid="filter-quote-status"><SelectValue placeholder="All stages" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All stages</SelectItem>
            <SelectItem value="draft">Draft</SelectItem><SelectItem value="sent">Sent</SelectItem><SelectItem value="expired">Expired</SelectItem>
            <SelectItem value="accepted">Accepted</SelectItem><SelectItem value="rejected">Rejected</SelectItem><SelectItem value="superseded">Superseded</SelectItem>
          </SelectContent>
        </Select>
        {can("quotes.view_all") && (
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
              <TableRow><TableHead>Quote</TableHead><TableHead>Customer</TableHead><TableHead>Stage</TableHead><TableHead className="text-right">Total</TableHead><TableHead>Valid until</TableHead><TableHead>Owner</TableHead></TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={6} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && <TableRow><TableCell colSpan={6} className="py-12 text-center text-muted-foreground">{active ? "No quotes match these filters." : "No quotes yet. Create one from a deal or click New quote."}</TableCell></TableRow>}
              {rows.map((x) => (
                <TableRow key={x.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => router.push(`/quotes/${x.id}`)} data-testid={`quote-row-${x.id}`}>
                  <TableCell><div className="font-medium">{x.title}</div><div className="font-mono text-xs text-muted-foreground">{x.quote_no}</div></TableCell>
                  <TableCell className="text-sm"><CustomerLink customer={x.customer} /></TableCell>
                  <TableCell><QuotePill status={x.display_status} /></TableCell>
                  <TableCell className="text-right font-semibold">{INR2(x.total)}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{fmtDate(x.valid_until)}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{x.owner?.name ?? "Unassigned"}</TableCell>
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

export default function QuotesPage() {
  return <Suspense fallback={null}><QuoteList /></Suspense>;
}
