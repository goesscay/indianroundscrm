"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { CheckCircle2, Download, TriangleAlert } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { INR2 } from "@/components/crm/shared";
import type { TrialBalance } from "@/types";

const TYPE_ORDER = ["asset", "liability", "equity", "income", "expense"];
const TYPE_LABEL: Record<string, string> = { asset: "Assets", liability: "Liabilities", equity: "Equity", income: "Income", expense: "Expenses" };

export default function TrialBalancePage() {
  const router = useRouter();
  const [asOf, setAsOf] = useState("");
  const [tb, setTb] = useState<TrialBalance | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let off = false;
    api.get<TrialBalance>("/finance/trial-balance", { params: asOf ? { as_of: asOf } : {} })
      .then((r) => { if (!off) { setTb(r.data); setError(""); if (!asOf) setAsOf(r.data.as_of); } })
      .catch((e) => { if (!off) setError(formatApiError(e)); });
    return () => { off = true; };
  }, [asOf]);

  const exportCsv = () => {
    if (!tb) return;
    const rows = [["Code", "Account", "Type", "Debit", "Credit"], ...tb.items.map((i) => [i.code, i.name, i.type, i.closing_debit || "", i.closing_credit || ""]), ["", "Total", "", tb.total_debit, tb.total_credit]];
    const csv = rows.map((r) => r.map((v) => `"${String(v ?? "").replace(/"/g, '""')}"`).join(",")).join("\n");
    const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" })); a.download = `trial-balance-${tb.as_of}.csv`; a.click();
  };

  return (
    <div className="space-y-6" data-testid="trial-balance-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="font-display text-2xl font-black tracking-tight">Trial balance</h2>
          <p className="mt-1 text-sm text-muted-foreground">Each account&apos;s closing balance on one date. Debits and credits must be equal; if they are not, something is wrong.</p>
        </div>
        <div className="flex items-end gap-2">
          <div><Label htmlFor="tb-date">As on</Label><Input id="tb-date" type="date" className="mt-1.5 h-10 w-[160px]" value={asOf} onChange={(e) => setAsOf(e.target.value)} data-testid="tb-date" /></div>
          <Button variant="outline" onClick={exportCsv} disabled={!tb} data-testid="tb-export"><Download size={16} /> CSV</Button>
        </div>
      </div>
      {error && <div className="text-sm text-destructive">{error}</div>}
      {!tb && !error && <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>}
      {tb && (
        <>
          <div className={tb.balanced ? "flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-400" : "flex items-center gap-2 text-sm text-destructive"} data-testid="tb-status" role="status">
            {tb.balanced ? <CheckCircle2 size={16} /> : <TriangleAlert size={16} />} {tb.balanced ? "The books balance." : "Debits and credits do not match. Please check the latest entries."}
          </div>
          <Card className="overflow-hidden rounded-lg">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader><TableRow><TableHead className="w-[90px]">Code</TableHead><TableHead>Account</TableHead><TableHead className="text-right">Debit</TableHead><TableHead className="text-right">Credit</TableHead></TableRow></TableHeader>
                <TableBody>
                  {tb.items.length === 0 && <TableRow><TableCell colSpan={4} className="py-10 text-center text-muted-foreground">Nothing posted up to this date.</TableCell></TableRow>}
                  {TYPE_ORDER.map((t) => {
                    const rows = tb.items.filter((i) => i.type === t);
                    if (!rows.length) return null;
                    return [
                      <TableRow key={`h-${t}`} className="bg-secondary/40"><TableCell colSpan={4} className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{TYPE_LABEL[t]}</TableCell></TableRow>,
                      ...rows.map((i) => (
                        <TableRow key={i.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => router.push(`/accounts/ledger?account=${i.id}`)} data-testid={`tb-row-${i.code}`}>
                          <TableCell className="font-mono text-sm">{i.code}</TableCell><TableCell className="text-sm">{i.name}</TableCell>
                          <TableCell className="text-right">{i.closing_debit ? INR2(i.closing_debit) : ""}</TableCell><TableCell className="text-right">{i.closing_credit ? INR2(i.closing_credit) : ""}</TableCell>
                        </TableRow>
                      )),
                    ];
                  })}
                </TableBody>
              </Table>
            </div>
            <div className="flex justify-end gap-10 border-t border-border bg-secondary/30 px-4 py-3 text-sm font-bold" data-testid="tb-totals">
              <span>Total debit {INR2(tb.total_debit)}</span><span>Total credit {INR2(tb.total_credit)}</span>
            </div>
          </Card>
        </>
      )}
    </div>
  );
}
