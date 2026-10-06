"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Banknote, XCircle } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import PaymentDialog from "@/components/crm/PaymentDialog";
import { INR2, PayPill, fmtDate, modeLabel } from "@/components/crm/shared";
import type { SupplierBill } from "@/types";

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className="grid grid-cols-[120px_1fr] gap-2 py-1.5 text-sm"><dt className="text-muted-foreground">{label}</dt><dd className="min-w-0 break-words">{children}</dd></div>;
}

export default function BillDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const [b, setB] = useState<SupplierBill | null>(null);
  const [error, setError] = useState("");
  const [pay, setPay] = useState(false);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try { const { data } = await api.get<SupplierBill>(`/finance/bills/${id}`); setB(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  const cancel = async () => {
    const reason = window.prompt("Why is this bill being cancelled?");
    if (!reason || !reason.trim()) return;
    setBusy(true);
    try { const { data } = await api.post<SupplierBill>(`/finance/bills/${id}/cancel`, { reason: reason.trim() }); setB(data); toast.success("Bill cancelled"); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  if (error) return <div className="space-y-3"><Button variant="ghost" size="sm" onClick={() => router.push("/accounts/bills")}><ArrowLeft size={16} /> Bills</Button><div className="text-sm text-destructive">{error}</div></div>;
  if (!b) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;
  const hasPayments = (b.payments ?? []).some((p) => p.status === "valid");

  return (
    <div className="space-y-6" data-testid="bill-detail">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.push("/accounts/bills")} className="-ml-3 mb-2"><ArrowLeft size={16} /> Bills</Button>
          <div className="label-caps">Supplier bill</div>
          <div className="mt-1 flex flex-wrap items-center gap-3">
            <h1 className="font-mono text-3xl font-black tracking-tight sm:text-4xl" data-testid="bill-no">{b.bill_no}</h1>
            <PayPill status={b.payment_status === "open" ? "unpaid" : b.payment_status} />
          </div>
          <p className="mt-2 text-sm text-muted-foreground">{b.supplier?.name}{b.supplier_ref ? ` · their invoice ${b.supplier_ref}` : ""}{b.deal && <> · <Link href={`/deals/${b.deal.id}`} className="hover:underline">{b.deal.deal_no}</Link></>}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {b.status === "open" && b.balance > 0 && can("finance.payables") && <Button onClick={() => setPay(true)} data-testid="pay-bill"><Banknote size={16} /> Pay supplier</Button>}
          {b.status === "open" && !hasPayments && can("finance.payables") && <Button variant="outline" disabled={busy} onClick={cancel} data-testid="cancel-bill"><XCircle size={16} /> Cancel bill</Button>}
        </div>
      </div>
      {b.status === "cancelled" && <Card className="rounded-lg p-4 text-sm">Cancelled{b.cancel_reason ? `: ${b.cancel_reason}` : ""}.</Card>}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card className="rounded-lg p-4"><div className="label-caps">Total</div><div className="mt-1 font-display text-2xl font-black">{INR2(b.total)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Paid</div><div className="mt-1 font-display text-2xl font-black text-emerald-600 dark:text-emerald-400">{INR2(b.paid_total)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Balance</div><div className={cn("mt-1 font-display text-2xl font-black", b.balance > 0 && "text-red-600 dark:text-red-400")} data-testid="bill-balance">{INR2(b.balance)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Due</div><div className="mt-1 font-display text-2xl font-black">{fmtDate(b.due_date)}</div></Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
        <div className="space-y-6">
          <Card className="overflow-hidden rounded-lg">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-border bg-secondary/50 text-left text-xs uppercase tracking-wide text-muted-foreground"><th className="px-4 py-2.5">What for</th><th className="px-2 py-2.5">Booked to</th><th className="px-4 py-2.5 text-right">Amount</th></tr></thead>
              <tbody>{b.lines?.map((l, i) => <tr key={i} className="border-b border-border last:border-0"><td className="px-4 py-2.5">{l.description}</td><td className="px-2 py-2.5 text-muted-foreground">{l.account}</td><td className="px-4 py-2.5 text-right font-medium">{INR2(l.amount)}</td></tr>)}</tbody>
            </table>
          </Card>
          <Card className="rounded-lg p-5" data-testid="bill-payments">
            <h3 className="mb-2 font-display font-bold">Payments</h3>
            {(b.payments?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">Nothing paid yet.</p>}
            <ul className="divide-y divide-border">
              {b.payments?.map((p) => (
                <li key={p.id} className="flex items-center justify-between gap-3 py-2.5 text-sm" data-testid={`bill-payment-${p.id}`}>
                  <div><div className={cn("font-mono font-medium", p.status === "cancelled" && "line-through text-muted-foreground")}>{p.payment_no}</div><div className="text-xs text-muted-foreground">{fmtDate(p.paid_date)} · {modeLabel(p.mode)}{p.reference ? ` · ${p.reference}` : ""}{p.status === "cancelled" ? " · cancelled" : ""}</div></div>
                  <span className="font-semibold">{INR2(p.amount)}</span>
                </li>
              ))}
            </ul>
          </Card>
        </div>
        <Card className="rounded-lg p-5">
          <h3 className="mb-2 font-display font-bold">Details</h3>
          <dl>
            <Row label="Supplier">{b.supplier?.name}</Row><Row label="Bill date">{fmtDate(b.bill_date)}</Row><Row label="Their invoice">{b.supplier_ref ?? "—"}</Row>
            <Row label="Trip">{b.deal ? <Link href={`/deals/${b.deal.id}`} className="hover:underline">{b.deal.deal_no} · {b.deal.name}</Link> : "Not linked to a trip"}</Row>
            {b.notes && <Row label="Notes"><span className="whitespace-pre-line">{b.notes}</span></Row>}
          </dl>
        </Card>
      </div>

      <PaymentDialog open={pay} onOpenChange={setPay} endpoint={`/finance/bills/${b.id}/payments`} kind="supplier" dateField="paid_date" suggested={b.balance} max={b.balance} onSaved={() => void load()} />
    </div>
  );
}
