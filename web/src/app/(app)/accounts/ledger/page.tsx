"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { cn } from "@/lib/utils";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { INR2, SOURCE_LABEL, fmtDate } from "@/components/crm/shared";
import type { LedgerAccount, LedgerReport } from "@/types";

function LedgerView() {
  const router = useRouter();
  const sp = useSearchParams();
  const accountId = sp.get("account") ?? "";
  const [accounts, setAccounts] = useState<LedgerAccount[]>([]);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [per, setPer] = useState(50);
  const [rep, setRep] = useState<LedgerReport | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api.get<{ items: LedgerAccount[] }>("/finance/accounts").then((r) => setAccounts(r.data.items)).catch(() => setAccounts([]));
  }, []);

  useEffect(() => {
    if (!accountId) { setRep(null); return; }
    let off = false;
    setLoading(true);
    api.get<LedgerReport>(`/finance/ledger/${accountId}`, { params: { ...(from ? { from } : {}), ...(to ? { to } : {}), page, per_page: per } })
      .then((r) => { if (!off) { setRep(r.data); setError(""); } })
      .catch((e) => { if (!off) setError(formatApiError(e)); })
      .finally(() => { if (!off) setLoading(false); });
    return () => { off = true; };
  }, [accountId, from, to, page, per]);

  const pick = (id: string) => { setPage(1); router.replace(`/accounts/ledger?account=${id}`); };

  return (
    <div className="space-y-6" data-testid="ledger-page">
      <div>
        <h2 className="font-display text-2xl font-black tracking-tight">Ledger</h2>
        <p className="mt-1 text-sm text-muted-foreground">Every posting to one account, with the running balance.</p>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-[260px]">
          <Label>Account</Label>
          <Select value={accountId} onValueChange={pick}>
            <SelectTrigger className="mt-1.5 h-10" data-testid="ledger-account"><SelectValue placeholder="Choose an account" /></SelectTrigger>
            <SelectContent className="max-h-80">{accounts.map((a) => <SelectItem key={a.id} value={a.id}>{a.code} · {a.name}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div><Label htmlFor="l-from">From</Label><Input id="l-from" type="date" className="mt-1.5 h-10 w-[150px]" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} /></div>
        <div><Label htmlFor="l-to">To</Label><Input id="l-to" type="date" className="mt-1.5 h-10 w-[150px]" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} /></div>
      </div>
      {error && <div className="text-sm text-destructive">{error}</div>}
      {!accountId && <Card className="rounded-lg p-10 text-center text-sm text-muted-foreground">Choose an account to see its postings.</Card>}

      {rep && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="ledger-summary">
            <Card className="rounded-lg p-4"><div className="label-caps">Opening</div><div className="mt-1 font-display text-xl font-black">{INR2(rep.opening)}</div></Card>
            <Card className="rounded-lg p-4"><div className="label-caps">Debits</div><div className="mt-1 font-display text-xl font-black">{INR2(rep.total_debit)}</div></Card>
            <Card className="rounded-lg p-4"><div className="label-caps">Credits</div><div className="mt-1 font-display text-xl font-black">{INR2(rep.total_credit)}</div></Card>
            <Card className="rounded-lg p-4"><div className="label-caps">Closing</div><div className={cn("mt-1 font-display text-xl font-black", rep.closing < 0 && "text-red-600 dark:text-red-400")} data-testid="ledger-closing">{INR2(rep.closing)}</div><div className="text-xs text-muted-foreground">{rep.account.type_label}, shown on its normal side</div></Card>
          </div>
          <Card className="overflow-hidden rounded-lg">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader><TableRow><TableHead>Date</TableHead><TableHead>Entry</TableHead><TableHead>Details</TableHead><TableHead className="text-right">Debit</TableHead><TableHead className="text-right">Credit</TableHead><TableHead className="text-right">Balance</TableHead></TableRow></TableHeader>
                <TableBody className={loading ? "opacity-60 transition-opacity" : "transition-opacity"}>
                  {rep.items.length === 0 && <TableRow><TableCell colSpan={6} className="py-10 text-center text-muted-foreground">No postings in this period.</TableCell></TableRow>}
                  {rep.items.map((r, i) => (
                    <TableRow key={`${r.entry_id}-${i}`} data-testid={`ledger-row-${i}`}>
                      <TableCell className="text-sm">{fmtDate(r.date)}</TableCell>
                      <TableCell><Link href={`/accounts/journal/${r.entry_id}`} className="font-mono text-sm hover:underline">{r.entry_no}</Link></TableCell>
                      <TableCell className="max-w-[380px] text-sm"><div className="truncate">{r.narration}</div><div className="text-xs text-muted-foreground">{SOURCE_LABEL[r.source_type] ?? r.source_type}{r.memo ? ` · ${r.memo}` : ""}</div></TableCell>
                      <TableCell className="text-right">{r.debit ? INR2(r.debit) : ""}</TableCell>
                      <TableCell className="text-right">{r.credit ? INR2(r.credit) : ""}</TableCell>
                      <TableCell className={cn("text-right font-medium", r.balance < 0 && "text-red-600 dark:text-red-400")}>{INR2(r.balance)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            <Pagination page={rep.page} pageSize={rep.per_page} total={rep.total} onPageChange={setPage} onPageSizeChange={(n) => { setPer(n); setPage(1); }} />
          </Card>
        </>
      )}
    </div>
  );
}

export default function LedgerPage() {
  return <Suspense fallback={null}><LedgerView /></Suspense>;
}
