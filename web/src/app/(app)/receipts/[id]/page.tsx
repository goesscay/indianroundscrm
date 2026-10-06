"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, FileText, Send, XCircle } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import SendDocDialog from "@/components/crm/SendDocDialog";
import { INR2, fmtDate, modeLabel } from "@/components/crm/shared";
import type { Invoice, Receipt } from "@/types";

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className="grid grid-cols-[130px_1fr] gap-2 py-1.5 text-sm"><dt className="text-muted-foreground">{label}</dt><dd className="min-w-0 break-words">{children}</dd></div>;
}

export default function ReceiptDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const [r, setR] = useState<Receipt | null>(null);
  const [error, setError] = useState("");
  const [send, setSend] = useState(false);
  const [open, setOpen] = useState<Invoice[]>([]);
  const [target, setTarget] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try { const { data } = await api.get<Receipt>(`/receipts/${id}`); setR(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  const isAdvance = !!r && r.kind === "receipt" && r.status === "valid" && !r.invoice_id;
  useEffect(() => {
    if (!isAdvance || !r || !can("receipts.record")) return;
    api.get<Invoice[]>("/invoices", { params: { customer_id: r.customer_id, status: "unpaid", limit: 100 } }).then((x) => setOpen(x.data.filter((i) => i.balance + 0.005 >= r.amount))).catch(() => setOpen([]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAdvance, r?.customer_id]);

  const openPdf = async () => {
    try {
      const res = await api.get(`/receipts/${id}/pdf`, { responseType: "blob" });
      const url = URL.createObjectURL(new Blob([res.data], { type: "application/pdf" }));
      window.open(url, "_blank", "noopener"); setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const cancel = async () => {
    const reason = window.prompt("Why is this receipt being cancelled?");
    if (!reason || !reason.trim()) return;
    setBusy(true);
    try { const { data } = await api.post<Receipt>(`/receipts/${id}/cancel`, { reason: reason.trim() }); setR(data); toast.success("Receipt cancelled"); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const apply = async () => {
    if (!target) return;
    setBusy(true);
    try { const { data } = await api.post<Receipt>(`/receipts/${id}/apply`, { invoice_id: target }); setR(data); toast.success("Advance applied to the invoice"); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  if (error) return <div className="space-y-3"><Button variant="ghost" size="sm" onClick={() => router.push("/receipts")}><ArrowLeft size={16} /> Receipts</Button><div className="text-sm text-destructive">{error}</div></div>;
  if (!r) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;
  const refund = r.kind === "refund";

  return (
    <div className="space-y-6" data-testid="receipt-detail">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.push("/receipts")} className="-ml-3 mb-2"><ArrowLeft size={16} /> Receipts</Button>
          <div className="label-caps">{refund ? "Refund" : "Receipt"}</div>
          <div className="mt-1 flex flex-wrap items-center gap-3">
            <h1 className="font-mono text-3xl font-black tracking-tight sm:text-4xl" data-testid="receipt-no">{r.receipt_no}</h1>
            {r.status === "cancelled" && <span className="rounded border border-border px-2 py-0.5 text-xs text-muted-foreground">Cancelled</span>}
          </div>
          <p className="mt-2 text-sm text-muted-foreground">{r.customer && <Link href={`/customers/${r.customer.id}`} className="font-medium text-foreground hover:underline">{r.customer.name}</Link>} · {fmtDate(r.received_date)}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={openPdf} data-testid="receipt-pdf"><FileText size={16} /> PDF</Button>
          {r.status === "valid" && can("receipts.record") && <Button variant="outline" onClick={() => setSend(true)} data-testid="receipt-send"><Send size={16} /> E-mail</Button>}
          {r.status === "valid" && can("receipts.cancel") && <Button variant="outline" disabled={busy} onClick={cancel} data-testid="receipt-cancel"><XCircle size={16} /> Cancel receipt</Button>}
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card className="rounded-lg p-5">
          <div className="label-caps">{refund ? "Refunded" : "Received"}</div>
          <div className="mt-1 font-display text-4xl font-black" data-testid="receipt-amount">{INR2(r.amount)}</div>
          <dl className="mt-4">
            <Row label="Mode">{modeLabel(r.mode)}</Row>
            {r.reference && <Row label="Reference">{r.reference}</Row>}
            {r.deposited_to && <Row label={refund ? "Paid from" : "Deposited to"}>{r.deposited_to}</Row>}
            <Row label="Against">{r.invoice ? <Link href={`/invoices/${r.invoice.id}`} className="font-mono hover:underline" data-testid="receipt-invoice-link">{r.invoice.invoice_no}</Link> : "Advance on account"}</Row>
            {r.notes && <Row label="Notes"><span className="whitespace-pre-line">{r.notes}</span></Row>}
            {r.created_by_name && <Row label="Entered by">{r.created_by_name}</Row>}
            {r.status === "cancelled" && <Row label="Cancelled">{r.cancel_reason}</Row>}
          </dl>
        </Card>

        {isAdvance && can("receipts.record") && (
          <Card className="space-y-3 rounded-lg p-5" data-testid="apply-advance">
            <h3 className="font-display font-bold">Apply to an invoice</h3>
            <p className="text-sm text-muted-foreground">This money is held on the customer&apos;s account. Apply it to one of their open invoices that it fits within.</p>
            {open.length === 0 ? <p className="text-sm text-muted-foreground">No open invoice of this customer can take {INR2(r.amount)} yet.</p> : (
              <div className="flex gap-2">
                <Select value={target} onValueChange={setTarget}>
                  <SelectTrigger className="h-10" data-testid="apply-target"><SelectValue placeholder="Choose an invoice" /></SelectTrigger>
                  <SelectContent>{open.map((i) => <SelectItem key={i.id} value={i.id}>{i.invoice_no} · balance {INR2(i.balance)}</SelectItem>)}</SelectContent>
                </Select>
                <Button onClick={apply} disabled={!target || busy} data-testid="apply-submit">Apply</Button>
              </div>
            )}
          </Card>
        )}
      </div>

      <SendDocDialog basePath={`/receipts/${r.id}`} title="Receipt" open={send} onOpenChange={setSend} />
    </div>
  );
}
