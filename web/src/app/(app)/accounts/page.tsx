"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Lock, LockOpen } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { INR2, fmtDate, inrShort } from "@/components/crm/shared";
import type { FinanceDashboard } from "@/types";

function Kpi({ label, value, sub, tone, href }: { label: string; value: string; sub?: string; tone?: string; href?: string }) {
  const body = (
    <Card className="h-full rounded-lg p-4">
      <div className="label-caps">{label}</div>
      <div className={cn("mt-1 font-display text-2xl font-black tracking-tight", tone)}>{value}</div>
      {sub && <div className="text-xs text-muted-foreground">{sub}</div>}
    </Card>
  );
  return href ? <Link href={href} className="block hover:opacity-90">{body}</Link> : body;
}

export default function AccountsDashboard() {
  const { can } = useCurrentUser();
  const [d, setD] = useState<FinanceDashboard | null>(null);
  const [error, setError] = useState("");
  const [lock, setLock] = useState("");
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    try { const { data } = await api.get<FinanceDashboard>("/finance/dashboard"); setD(data); setLock(data.lock_date ?? ""); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const saveLock = async (date: string | null) => {
    setSaving(true);
    try { await api.put("/finance/settings", { lock_date: date }); toast.success(date ? `Books closed up to ${fmtDate(date)}` : "Books re-opened"); await load(); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setSaving(false); }
  };

  if (error) return <div className="text-sm text-destructive">{error}</div>;
  if (!d) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;

  const max = Math.max(1, ...d.months.flatMap((m) => [m.income, m.expenses]));
  const profit = d.month.income - d.month.expenses;
  const yProfit = d.year.income - d.year.expenses;

  return (
    <div className="space-y-6" data-testid="accounts-dashboard">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="label-caps">Accounts</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">Accounts</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">How the business is doing this month and this year, where the money is, and what is waiting. Every figure comes from your journal; invoices and receipts post to it automatically.</p>
        </div>
        {can("finance.journal") && <Link href="/accounts/journal?new=1"><Button data-testid="new-entry-btn">+ Journal entry</Button></Link>}
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="accounts-kpis">
        <Kpi label="Income this month" value={INR2(d.month.income)} tone="text-emerald-600 dark:text-emerald-400" href="/accounts/ledger" />
        <Kpi label="Expenses this month" value={INR2(d.month.expenses)} />
        <Kpi label="Profit this month" value={INR2(profit)} tone={profit < 0 ? "text-red-600 dark:text-red-400" : undefined} />
        <Kpi label={`Profit · ${d.year.label}`} value={INR2(yProfit)} tone={yProfit < 0 ? "text-red-600 dark:text-red-400" : undefined} sub={`Since 1 Apr · income ${inrShort(d.year.income)}, expenses ${inrShort(d.year.expenses)}`} />
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
        <Card className="rounded-lg p-5" data-testid="accounts-chart">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h3 className="font-display font-bold">Income and expenses, last six months</h3>
            <div className="flex gap-4 text-xs text-muted-foreground">
              <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-emerald-500" /> Income</span>
              <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-slate-400" /> Expenses</span>
            </div>
          </div>
          <div className="grid grid-cols-6 gap-2">
            {d.months.map((m) => (
              <div key={m.month} className="flex flex-col items-center" data-testid={`month-${m.month}`}>
                <div className="flex h-36 items-end gap-1">
                  <div className="flex h-full w-4 items-end sm:w-6" title={`Income ${INR2(m.income)}`}><div className="w-full rounded-t bg-emerald-500" style={{ height: `${Math.max(m.income > 0 ? 3 : 0, (m.income / max) * 100)}%` }} /></div>
                  <div className="flex h-full w-4 items-end sm:w-6" title={`Expenses ${INR2(m.expenses)}`}><div className="w-full rounded-t bg-slate-400" style={{ height: `${Math.max(m.expenses > 0 ? 3 : 0, (m.expenses / max) * 100)}%` }} /></div>
                </div>
                <div className="mt-2 text-xs text-muted-foreground">{m.label}</div>
              </div>
            ))}
          </div>
        </Card>

        <Card className="rounded-lg p-5" data-testid="accounts-banks">
          <div className="mb-2 flex items-center justify-between"><h3 className="font-display font-bold">Where the money is</h3><Link href="/accounts/banking" className="text-xs underline underline-offset-2">Banking</Link></div>
          {d.banks.length === 0 && <p className="text-sm text-muted-foreground">No bank accounts yet. Add one under Banking so payments land in the right place.</p>}
          <ul className="divide-y divide-border">
            {d.banks.map((b) => <li key={`${b.id}-${b.name}`} className="flex justify-between py-2.5 text-sm"><span>{b.name}</span><span className={cn("font-medium", b.balance < 0 && "text-red-600 dark:text-red-400")}>{INR2(b.balance)}</span></li>)}
          </ul>
          <div className="mt-2 flex justify-between border-t border-border pt-2 font-bold"><span>Total</span><span data-testid="bank-total">{INR2(d.bank_total)}</span></div>
        </Card>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="accounts-position">
        <Kpi label="Customers owe you" value={INR2(d.receivable)} sub={`${d.invoices_outstanding.count} open invoice${d.invoices_outstanding.count === 1 ? "" : "s"}`} href="/invoices?status=unpaid" />
        <Kpi label="You owe suppliers" value={INR2(d.payable)} sub={d.bills_outstanding ? `${d.bills_outstanding.count} open bill${d.bills_outstanding.count === 1 ? "" : "s"}` : undefined} href="/accounts/bills?status=unpaid" />
        <Kpi label="Advances held" value={INR2(d.advances)} sub="Received, not yet on an invoice" href="/receipts" />
        <Kpi label="GST and TCS to pay" value={INR2(d.gst_payable + d.tcs_payable)} sub={`GST ${INR2(d.gst_payable)} · TCS ${INR2(d.tcs_payable)}`} />
      </div>

      <Card className="flex flex-wrap items-center justify-between gap-3 rounded-lg p-4" data-testid="accounts-lock">
        <div className="flex items-center gap-2 text-sm">
          {d.lock_date ? <Lock size={16} /> : <LockOpen size={16} className="text-muted-foreground" />}
          {d.lock_date ? <span>Books are closed up to <span className="font-semibold">{fmtDate(d.lock_date)}</span>. Nothing can be posted on or before that date.</span> : <span className="text-muted-foreground">Books are open. Close them up to a date once a period has been checked, so figures cannot change under you.</span>}
        </div>
        {can("finance.manage") && (
          <div className="flex items-center gap-2">
            <Input type="date" aria-label="Close books up to" className="h-9 w-[170px]" max={new Date().toISOString().slice(0, 10)} value={lock} onChange={(e) => setLock(e.target.value)} data-testid="lock-date" />
            <Button size="sm" variant="outline" disabled={saving || !lock || lock === d.lock_date} onClick={() => void saveLock(lock)} data-testid="lock-save">Close books</Button>
            {d.lock_date && <Button size="sm" variant="ghost" disabled={saving} onClick={() => void saveLock(null)}>Re-open</Button>}
          </div>
        )}
      </Card>
    </div>
  );
}
