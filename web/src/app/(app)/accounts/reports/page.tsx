"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { CheckCircle2, Download } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { INR2, fmtDate } from "@/components/crm/shared";
import type { AgeRow, BalanceSheet, GstSummary, PayablesAgeing, ProfitLoss, ReceivablesAgeing, ReportRow } from "@/types";

type Key = "profit-loss" | "balance-sheet" | "receivables-ageing" | "payables-ageing" | "gst-summary";
const REPORTS: { key: Key; label: string; range: "period" | "asof" | "none" }[] = [
  { key: "profit-loss", label: "Profit and loss", range: "period" },
  { key: "balance-sheet", label: "Balance sheet", range: "asof" },
  { key: "receivables-ageing", label: "Customers owe you", range: "none" },
  { key: "payables-ageing", label: "You owe suppliers", range: "none" },
  { key: "gst-summary", label: "GST summary", range: "period" },
];

function csv(name: string, rows: (string | number)[][]) {
  const text = rows.map((r) => r.map((v) => `"${String(v ?? "").replace(/"/g, '""')}"`).join(",")).join("\n");
  const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob([text], { type: "text/csv" })); a.download = `${name}-${new Date().toISOString().slice(0, 10)}.csv`; a.click();
}

const money = (n: number) => <span className={cn(n < 0 && "text-red-600 dark:text-red-400")}>{INR2(n)}</span>;

function Section({ title, rows, total, totalLabel }: { title: string; rows: ReportRow[]; total: number; totalLabel: string }) {
  return (
    <>
      <TableRow className="bg-secondary/40"><TableCell colSpan={3} className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</TableCell></TableRow>
      {rows.length === 0 && <TableRow><TableCell colSpan={3} className="text-sm text-muted-foreground">None</TableCell></TableRow>}
      {rows.map((r) => (
        <TableRow key={r.code}><TableCell className="w-[80px] font-mono text-xs text-muted-foreground">{r.code}</TableCell><TableCell className="text-sm">{r.name}</TableCell><TableCell className="text-right">{money(r.amount)}</TableCell></TableRow>
      ))}
      <TableRow><TableCell /><TableCell className="text-sm font-semibold">{totalLabel}</TableCell><TableCell className="text-right font-semibold">{money(total)}</TableCell></TableRow>
    </>
  );
}

function ProfitLossView({ d }: { d: ProfitLoss }) {
  return (
    <Card className="overflow-hidden rounded-lg" data-testid="report-pl">
      <Table><TableBody>
        <Section title="Income" rows={d.income} total={d.total_income} totalLabel="Total income" />
        <Section title="Cost of tours" rows={d.cost_of_sales} total={d.total_cost_of_sales} totalLabel="Total cost of tours" />
        <TableRow className="bg-secondary/30"><TableCell /><TableCell className="font-bold">Gross profit</TableCell><TableCell className="text-right font-bold" data-testid="gross-profit">{money(d.gross_profit)}</TableCell></TableRow>
        <Section title="Running costs" rows={d.expenses} total={d.total_expenses} totalLabel="Total running costs" />
        <TableRow className="border-t-2 border-foreground"><TableCell /><TableCell className="text-base font-bold">Net profit</TableCell><TableCell className="text-right text-base font-bold" data-testid="net-profit">{money(d.net_profit)}</TableCell></TableRow>
      </TableBody></Table>
    </Card>
  );
}

function BalanceSheetView({ d }: { d: BalanceSheet }) {
  return (
    <div className="space-y-3" data-testid="report-bs">
      <div className={cn("flex items-center gap-2 text-sm", d.balanced ? "text-emerald-700 dark:text-emerald-400" : "text-destructive")} role="status"><CheckCircle2 size={16} /> {d.balanced ? "Assets equal liabilities plus equity." : "The balance sheet does not balance. Please check the latest entries."}</div>
      <Card className="overflow-hidden rounded-lg"><Table><TableBody>
        <Section title="Assets" rows={d.assets} total={d.total_assets} totalLabel="Total assets" />
        <Section title="Liabilities" rows={d.liabilities} total={d.total_liabilities} totalLabel="Total liabilities" />
        <TableRow className="bg-secondary/40"><TableCell colSpan={3} className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Equity</TableCell></TableRow>
        {d.equity.map((r) => <TableRow key={r.code}><TableCell className="w-[80px] font-mono text-xs text-muted-foreground">{r.code}</TableCell><TableCell className="text-sm">{r.name}</TableCell><TableCell className="text-right">{money(r.amount)}</TableCell></TableRow>)}
        <TableRow><TableCell /><TableCell className="text-sm">Profit to date</TableCell><TableCell className="text-right">{money(d.profit_to_date)}</TableCell></TableRow>
        <TableRow><TableCell /><TableCell className="text-sm font-semibold">Total equity</TableCell><TableCell className="text-right font-semibold">{money(d.total_equity)}</TableCell></TableRow>
        <TableRow className="border-t-2 border-foreground"><TableCell /><TableCell className="font-bold">Liabilities plus equity</TableCell><TableCell className="text-right font-bold">{money(d.total_liabilities + d.total_equity)}</TableCell></TableRow>
      </TableBody></Table></Card>
    </div>
  );
}

const BUCKET_HEADS: { k: keyof AgeRow; label: string }[] = [{ k: "current", label: "Not due" }, { k: "d1_30", label: "1–30 days" }, { k: "d31_60", label: "31–60" }, { k: "d61_90", label: "61–90" }, { k: "d90p", label: "90+ days" }];

function AgeTable({ rows, totals, nameHead, testId }: { rows: { key: string; name: string; sub: string; href: string; row: AgeRow }[]; totals: AgeRow; nameHead: string; testId: string }) {
  return (
    <Card className="overflow-hidden rounded-lg" data-testid={testId}>
      <div className="overflow-x-auto">
        <Table>
          <TableHeader><TableRow><TableHead>{nameHead}</TableHead>{BUCKET_HEADS.map((b) => <TableHead key={b.k} className="text-right">{b.label}</TableHead>)}<TableHead className="text-right">Total</TableHead></TableRow></TableHeader>
          <TableBody>
            {rows.length === 0 && <TableRow><TableCell colSpan={7} className="py-10 text-center text-muted-foreground">Nothing outstanding.</TableCell></TableRow>}
            {rows.map((r) => (
              <TableRow key={r.key}>
                <TableCell><Link href={r.href} className="font-medium hover:underline">{r.name}</Link><div className="text-xs text-muted-foreground">{r.sub}</div></TableCell>
                {BUCKET_HEADS.map((b) => <TableCell key={b.k} className={cn("text-right", b.k === "d90p" && r.row[b.k] > 0 && "text-red-600 dark:text-red-400")}>{r.row[b.k] ? INR2(r.row[b.k]) : ""}</TableCell>)}
                <TableCell className="text-right font-semibold">{INR2(r.row.balance)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
          <tfoot><tr className="border-t-2 border-foreground font-bold"><td className="px-4 py-3">Total</td>{BUCKET_HEADS.map((b) => <td key={b.k} className="px-4 py-3 text-right" data-testid={`age-total-${b.k}`}>{INR2(totals[b.k])}</td>)}<td className="px-4 py-3 text-right" data-testid="age-total">{INR2(totals.balance)}</td></tr></tfoot>
        </Table>
      </div>
    </Card>
  );
}

function GstView({ d }: { d: GstSummary }) {
  return (
    <div className="space-y-3" data-testid="report-gst">
      <Card className="overflow-hidden rounded-lg"><div className="overflow-x-auto"><Table>
        <TableHeader><TableRow><TableHead>Month</TableHead><TableHead className="text-right">Taxable sales</TableHead><TableHead className="text-right">CGST</TableHead><TableHead className="text-right">SGST</TableHead><TableHead className="text-right">IGST</TableHead><TableHead className="text-right">GST (state not set)</TableHead><TableHead className="text-right">Total GST</TableHead><TableHead className="text-right">TCS</TableHead></TableRow></TableHeader>
        <TableBody>
          {d.items.length === 0 && <TableRow><TableCell colSpan={8} className="py-10 text-center text-muted-foreground">No sales in this period.</TableCell></TableRow>}
          {d.items.map((m) => (
            <TableRow key={m.month}><TableCell className="font-medium">{new Date(`${m.month}-01T00:00:00`).toLocaleDateString(undefined, { month: "short", year: "numeric" })}</TableCell>
              <TableCell className="text-right">{INR2(m.taxable)}</TableCell><TableCell className="text-right">{INR2(m.cgst)}</TableCell><TableCell className="text-right">{INR2(m.sgst)}</TableCell><TableCell className="text-right">{INR2(m.igst)}</TableCell>
              <TableCell className="text-right">{INR2(m.gst_other)}</TableCell><TableCell className="text-right font-semibold">{INR2(m.gst_total)}</TableCell><TableCell className="text-right">{INR2(m.tcs)}</TableCell></TableRow>
          ))}
        </TableBody>
        <tfoot><tr className="border-t-2 border-foreground font-bold"><td className="px-4 py-3">Total</td><td className="px-4 py-3 text-right">{INR2(d.totals.taxable)}</td><td className="px-4 py-3 text-right">{INR2(d.totals.cgst)}</td><td className="px-4 py-3 text-right">{INR2(d.totals.sgst)}</td><td className="px-4 py-3 text-right">{INR2(d.totals.igst)}</td><td className="px-4 py-3 text-right">{INR2(d.totals.gst_other)}</td><td className="px-4 py-3 text-right" data-testid="gst-total">{INR2(d.totals.gst_total)}</td><td className="px-4 py-3 text-right">{INR2(d.totals.tcs)}</td></tr></tfoot>
      </Table></div></Card>
      <p className="text-xs text-muted-foreground">{d.note}</p>
    </div>
  );
}

function Reports() {
  const router = useRouter();
  const sp = useSearchParams();
  const key = (REPORTS.find((r) => r.key === sp.get("r"))?.key ?? "profit-loss") as Key;
  const meta = REPORTS.find((r) => r.key === key)!;
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [asOf, setAsOf] = useState("");
  const [data, setData] = useState<unknown>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let off = false;
    setData(null);
    const params = meta.range === "period" ? { ...(from ? { from } : {}), ...(to ? { to } : {}) } : meta.range === "asof" ? (asOf ? { as_of: asOf } : {}) : {};
    api.get(`/finance/reports/${key}`, { params }).then((r) => {
      if (off) return;
      setData(r.data); setError("");
      const d = r.data as { from?: string; to?: string; as_of?: string };
      if (meta.range === "period") { if (!from && d.from) setFrom(d.from); if (!to && d.to) setTo(d.to); }
      if (meta.range === "asof" && !asOf && d.as_of) setAsOf(d.as_of);
    }).catch((e) => { if (!off) setError(formatApiError(e)); });
    return () => { off = true; };
  }, [key, from, to, asOf, meta.range]);

  const exportCsv = () => {
    if (!data) return;
    if (key === "profit-loss") {
      const d = data as ProfitLoss;
      csv("profit-and-loss", [["Section", "Code", "Account", "Amount"], ...d.income.map((r) => ["Income", r.code, r.name, r.amount]), ...d.cost_of_sales.map((r) => ["Cost of tours", r.code, r.name, r.amount]), ["", "", "Gross profit", d.gross_profit], ...d.expenses.map((r) => ["Running costs", r.code, r.name, r.amount]), ["", "", "Net profit", d.net_profit]]);
    } else if (key === "balance-sheet") {
      const d = data as BalanceSheet;
      csv("balance-sheet", [["Section", "Code", "Account", "Amount"], ...d.assets.map((r) => ["Assets", r.code, r.name, r.amount]), ...d.liabilities.map((r) => ["Liabilities", r.code, r.name, r.amount]), ...d.equity.map((r) => ["Equity", r.code, r.name, r.amount]), ["Equity", "", "Profit to date", d.profit_to_date]]);
    } else if (key === "receivables-ageing") {
      const d = data as ReceivablesAgeing;
      csv("receivables-ageing", [["Customer", "Invoice", "Not due", "1-30", "31-60", "61-90", "90+", "Total"], ...d.items.map((r) => [r.customer ?? "", r.invoice_no ?? "", r.current, r.d1_30, r.d31_60, r.d61_90, r.d90p, r.balance])]);
    } else if (key === "payables-ageing") {
      const d = data as PayablesAgeing;
      csv("payables-ageing", [["Supplier", "Bill", "Due", "Not due", "1-30", "31-60", "61-90", "90+", "Total"], ...d.items.map((r) => [r.supplier ?? "", r.bill_no, r.due_date, r.current, r.d1_30, r.d31_60, r.d61_90, r.d90p, r.balance])]);
    } else {
      const d = data as GstSummary;
      csv("gst-summary", [["Month", "Taxable", "CGST", "SGST", "IGST", "GST other", "Total GST", "TCS"], ...d.items.map((m) => [m.month, m.taxable, m.cgst, m.sgst, m.igst, m.gst_other, m.gst_total, m.tcs])]);
    }
  };

  return (
    <div className="space-y-6" data-testid="reports-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="font-display text-2xl font-black tracking-tight">Reports</h2>
          <p className="mt-1 text-sm text-muted-foreground">Worked out from the journal. Pick a report, set the dates, and export it if you need a copy.</p>
        </div>
        <Button variant="outline" onClick={exportCsv} disabled={!data} data-testid="report-export"><Download size={16} /> CSV</Button>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div className="inline-flex flex-wrap rounded border border-border p-0.5" role="tablist" aria-label="Report">
          {REPORTS.map((r) => (
            <button key={r.key} type="button" role="tab" aria-selected={key === r.key} onClick={() => router.replace(`/accounts/reports?r=${r.key}`)} data-testid={`report-tab-${r.key}`}
                    className={cn("rounded px-3 py-1.5 text-sm", key === r.key ? "bg-foreground text-background" : "text-muted-foreground hover:text-foreground")}>{r.label}</button>
          ))}
        </div>
        {meta.range === "period" && (<>
          <div><Label htmlFor="r-from">From</Label><Input id="r-from" type="date" className="mt-1.5 h-10 w-[150px]" value={from} onChange={(e) => setFrom(e.target.value)} data-testid="report-from" /></div>
          <div><Label htmlFor="r-to">To</Label><Input id="r-to" type="date" className="mt-1.5 h-10 w-[150px]" value={to} onChange={(e) => setTo(e.target.value)} data-testid="report-to" /></div>
        </>)}
        {meta.range === "asof" && <div><Label htmlFor="r-asof">As on</Label><Input id="r-asof" type="date" className="mt-1.5 h-10 w-[160px]" value={asOf} onChange={(e) => setAsOf(e.target.value)} data-testid="report-asof" /></div>}
      </div>

      {error && <div className="text-sm text-destructive">{error}</div>}
      {!data && !error && <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>}
      {data != null && key === "profit-loss" && <ProfitLossView d={data as ProfitLoss} />}
      {data != null && key === "balance-sheet" && <BalanceSheetView d={data as BalanceSheet} />}
      {data != null && key === "receivables-ageing" && (() => { const d = data as ReceivablesAgeing; return <AgeTable testId="report-receivables" nameHead="Customer and invoice" totals={d.totals} rows={d.items.map((r) => ({ key: r.invoice_id, name: r.customer ?? "—", sub: `${r.invoice_no ?? ""} · ${r.title}${r.next_due ? ` · next due ${fmtDate(r.next_due)}` : ""}`, href: `/invoices/${r.invoice_id}`, row: r }))} />; })()}
      {data != null && key === "payables-ageing" && (() => { const d = data as PayablesAgeing; return <AgeTable testId="report-payables" nameHead="Supplier and bill" totals={d.totals} rows={d.items.map((r) => ({ key: r.bill_id, name: r.supplier ?? "—", sub: `${r.bill_no}${r.supplier_ref ? ` · ${r.supplier_ref}` : ""} · due ${fmtDate(r.due_date)}`, href: `/accounts/bills/${r.bill_id}`, row: r }))} />; })()}
      {data != null && key === "gst-summary" && <GstView d={data as GstSummary} />}
    </div>
  );
}

export default function ReportsPage() {
  return <Suspense fallback={null}><Reports /></Suspense>;
}
