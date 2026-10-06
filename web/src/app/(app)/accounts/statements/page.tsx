"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useRef, useState } from "react";
import { Search } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import { cn } from "@/lib/utils";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { INR2, SOURCE_LABEL, fmtDate } from "@/components/crm/shared";
import type { Customer, PartyStatement, Supplier } from "@/types";

function Statement() {
  const router = useRouter();
  const sp = useSearchParams();
  const type = (sp.get("type") === "supplier" ? "supplier" : "customer") as "customer" | "supplier";
  const id = sp.get("id") ?? "";
  const [q, setQ] = useState("");
  const [results, setResults] = useState<{ id: string; name: string; sub: string }[]>([]);
  const reqId = useRef(0);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [per, setPer] = useState(50);
  const [st, setSt] = useState<PartyStatement | null>(null);
  const [error, setError] = useState("");

  const go = (t: string, i: string) => { setPage(1); router.replace(`/accounts/statements?type=${t}${i ? `&id=${i}` : ""}`); };

  useEffect(() => {
    if (!q.trim()) { setResults([]); return; }
    const rid = ++reqId.current;
    const t = setTimeout(() => {
      if (type === "customer") {
        api.get<Customer[]>("/customers", { params: { q: q.trim(), limit: 8 } }).then((r) => { if (rid === reqId.current) setResults(r.data.map((c) => ({ id: c.id, name: c.name, sub: c.customer_no }))); }).catch(() => setResults([]));
      } else {
        api.get<Supplier[]>("/suppliers").then((r) => { if (rid === reqId.current) setResults(r.data.filter((s) => s.name.toLowerCase().includes(q.trim().toLowerCase())).slice(0, 8).map((s) => ({ id: s.id, name: s.name, sub: s.email }))); }).catch(() => setResults([]));
      }
    }, 250);
    return () => clearTimeout(t);
  }, [q, type]);

  useEffect(() => {
    if (!id) { setSt(null); return; }
    let off = false;
    api.get<PartyStatement>("/finance/party-ledger", { params: { type, id, ...(from ? { from } : {}), ...(to ? { to } : {}), page, per_page: per } })
      .then((r) => { if (!off) { setSt(r.data); setError(""); } }).catch((e) => { if (!off) setError(formatApiError(e)); });
    return () => { off = true; };
  }, [type, id, from, to, page, per]);

  return (
    <div className="space-y-6" data-testid="statements-page">
      <div>
        <h2 className="font-display text-2xl font-black tracking-tight">Statements</h2>
        <p className="mt-1 text-sm text-muted-foreground">Everything between you and one customer or supplier: what was billed, what was paid and what is still open.</p>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <div>
          <Label>For a</Label>
          <Select value={type} onValueChange={(v) => { setQ(""); go(v, ""); }}>
            <SelectTrigger className="mt-1.5 h-10 w-[140px]" data-testid="statement-type"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem value="customer">Customer</SelectItem><SelectItem value="supplier">Supplier</SelectItem></SelectContent>
          </Select>
        </div>
        <div className="relative min-w-[240px] max-w-[340px] flex-1">
          <Label>{type === "customer" ? "Customer" : "Supplier"}</Label>
          <Search size={15} className="absolute left-3 top-[2.6rem] -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={st ? st.party.name : "Search by name…"} className="mt-1.5 h-10 pl-9" data-testid="statement-search" />
          {results.length > 0 && (
            <ul className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded border border-border bg-popover shadow-md">
              {results.map((r) => <li key={r.id}><button type="button" onClick={() => { setQ(""); setResults([]); go(type, r.id); }} className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-accent" data-testid={`statement-opt-${r.id}`}><span className="truncate">{r.name}</span><span className="text-xs text-muted-foreground">{r.sub}</span></button></li>)}
            </ul>
          )}
        </div>
        <div><Label htmlFor="st-from">From</Label><Input id="st-from" type="date" className="mt-1.5 h-10 w-[150px]" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} /></div>
        <div><Label htmlFor="st-to">To</Label><Input id="st-to" type="date" className="mt-1.5 h-10 w-[150px]" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} /></div>
      </div>
      {error && <div className="text-sm text-destructive">{error}</div>}
      {!id && <Card className="rounded-lg p-10 text-center text-sm text-muted-foreground">Choose a {type} to see the statement.</Card>}
      {st && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="statement-summary">
            <Card className="rounded-lg p-4"><div className="label-caps">Opening</div><div className="mt-1 font-display text-xl font-black">{INR2(st.opening)}</div></Card>
            <Card className="rounded-lg p-4"><div className="label-caps">Debits</div><div className="mt-1 font-display text-xl font-black">{INR2(st.total_debit)}</div></Card>
            <Card className="rounded-lg p-4"><div className="label-caps">Credits</div><div className="mt-1 font-display text-xl font-black">{INR2(st.total_credit)}</div></Card>
            <Card className="rounded-lg p-4"><div className="label-caps">{st.party.name}</div><div className={cn("mt-1 font-display text-xl font-black", st.closing < 0 && "text-red-600 dark:text-red-400")} data-testid="statement-closing">{INR2(Math.abs(st.closing))}</div><div className="text-xs text-muted-foreground">{st.position}</div></Card>
          </div>
          <Card className="overflow-hidden rounded-lg">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader><TableRow><TableHead>Date</TableHead><TableHead>Entry</TableHead><TableHead>Details</TableHead><TableHead className="text-right">Debit</TableHead><TableHead className="text-right">Credit</TableHead><TableHead className="text-right">Balance</TableHead></TableRow></TableHeader>
                <TableBody>
                  {st.items.length === 0 && <TableRow><TableCell colSpan={6} className="py-10 text-center text-muted-foreground">No postings in this period.</TableCell></TableRow>}
                  {st.items.map((r, i) => (
                    <TableRow key={`${r.entry_id}-${i}`} data-testid={`statement-row-${i}`}>
                      <TableCell className="text-sm">{fmtDate(r.date)}</TableCell>
                      <TableCell><Link href={`/accounts/journal/${r.entry_id}`} className="font-mono text-sm hover:underline">{r.entry_no}</Link></TableCell>
                      <TableCell className="max-w-[360px] text-sm"><div className="truncate">{r.narration}</div><div className="text-xs text-muted-foreground">{SOURCE_LABEL[r.source_type] ?? r.source_type}</div></TableCell>
                      <TableCell className="text-right">{r.debit ? INR2(r.debit) : ""}</TableCell><TableCell className="text-right">{r.credit ? INR2(r.credit) : ""}</TableCell>
                      <TableCell className="text-right font-medium">{INR2(r.balance)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            <Pagination page={st.page} pageSize={st.per_page} total={st.total} onPageChange={setPage} onPageSizeChange={(n) => { setPer(n); setPage(1); }} />
          </Card>
        </>
      )}
    </div>
  );
}

export default function StatementsPage() {
  return <Suspense fallback={null}><Statement /></Suspense>;
}
