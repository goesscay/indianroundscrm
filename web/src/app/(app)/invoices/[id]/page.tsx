"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Banknote, BellRing, FilePlus2, FileText, Pencil, Send, Trash2, Undo2, XCircle } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import PaymentDialog from "@/components/crm/PaymentDialog";
import CreditNoteDialog from "@/components/crm/CreditNoteDialog";
import CustomerReplies from "@/components/crm/CustomerReplies";
import SendDocDialog from "@/components/crm/SendDocDialog";
import { INR2, PayPill, fmtDate, modeLabel } from "@/components/crm/shared";
import type { Invoice } from "@/types";

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-[120px_1fr] gap-2 py-1.5 text-sm"><dt className="text-muted-foreground">{label}</dt><dd className="min-w-0 break-words">{children}</dd></div>
  );
}

const SCHED: Record<string, string> = { paid: "Paid", partial: "Part paid", due: "Due", overdue: "Overdue" };

export default function InvoiceDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const [x, setX] = useState<Invoice | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [pay, setPay] = useState<"receipt" | "refund" | null>(null);
  const [credit, setCredit] = useState(false);
  const [send, setSend] = useState(false);
  const [remind, setRemind] = useState(false);

  const load = useCallback(async () => {
    try { const { data } = await api.get<Invoice>(`/invoices/${id}`); setX(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  const openPdf = async () => {
    try {
      const res = await api.get(`/invoices/${id}/pdf`, { responseType: "blob" });
      const url = URL.createObjectURL(new Blob([res.data], { type: "application/pdf" }));
      window.open(url, "_blank", "noopener"); setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const applyAdvance = async (receiptId: string) => {
    setBusy(true);
    try { await api.post(`/receipts/${receiptId}/apply`, { invoice_id: id }); toast.success("Advance applied to this invoice"); await load(); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };
  const issue = async () => {
    if (!x || !window.confirm("Issue this invoice? It gets its invoice number and can no longer be edited. Corrections are made with a credit note.")) return;
    setBusy(true);
    try { const { data } = await api.post<Invoice>(`/invoices/${id}/issue`, {}); setX(data); toast.success(`Issued as ${data.invoice_no}`); if (data.advances_applied?.count) toast.success(`${INR2(data.advances_applied.amount)} already paid by the customer was applied to this invoice.`); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const cancel = async () => {
    const reason = window.prompt("Why is this invoice being cancelled?");
    if (!reason || !reason.trim()) return;
    setBusy(true);
    try { const { data } = await api.post<Invoice>(`/invoices/${id}/cancel`, { reason: reason.trim() }); setX(data); toast.success("Invoice cancelled"); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const remove = async () => {
    if (!window.confirm("Delete this draft invoice?")) return;
    try { await api.delete(`/invoices/${id}`); toast.success("Draft deleted"); router.replace("/invoices"); } catch (e) { toast.error(formatApiError(e)); }
  };

  if (error) return <div className="space-y-3"><Button variant="ghost" size="sm" onClick={() => router.push("/invoices")}><ArrowLeft size={16} /> Invoices</Button><div className="text-sm text-destructive">{error}</div></div>;
  if (!x) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;

  const draft = x.status === "draft"; const issued = x.status === "issued";
  const hasMoney = (x.receipts ?? []).some((r) => r.status === "valid") || (x.credit_notes?.length ?? 0) > 0;
  const creditRoom = Math.round((x.total - x.credited_total) * 100) / 100;

  return (
    <div className="space-y-6" data-testid="invoice-detail">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.push("/invoices")} className="-ml-3 mb-2"><ArrowLeft size={16} /> Invoices</Button>
          <div className="label-caps">Invoice · <span className="font-mono">{x.invoice_no ?? "Draft"}</span></div>
          <div className="mt-1 flex flex-wrap items-center gap-3">
            <h1 className="font-display text-3xl font-black tracking-tight sm:text-4xl" data-testid="invoice-title-h">{x.title}</h1>
            <PayPill status={x.payment_status} />
          </div>
          <p className="mt-2 text-sm text-muted-foreground">
            {x.customer && <Link href={`/customers/${x.customer.id}`} className="font-medium text-foreground hover:underline">{x.customer.name}</Link>}
            {x.deal && <> · <Link href={`/deals/${x.deal.id}`} className="hover:underline">{x.deal.deal_no}</Link></>}
            {x.quote_id && <> · <Link href={`/quotes/${x.quote_id}`} className="hover:underline">from a quote</Link></>}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {!draft && <Button variant="outline" onClick={openPdf} data-testid="invoice-pdf"><FileText size={16} /> PDF</Button>}
          {draft && can("invoices.create") && <Button variant="outline" onClick={() => router.push(`/invoices/${x.id}/edit`)} data-testid="invoice-edit"><Pencil size={16} /> Edit</Button>}
          {draft && can("invoices.issue") && <Button onClick={issue} disabled={busy} data-testid="invoice-issue"><FilePlus2 size={16} /> Issue invoice</Button>}
          {issued && can("invoices.issue") && <Button variant="outline" onClick={() => setSend(true)} data-testid="invoice-send"><Send size={16} /> E-mail</Button>}
          {issued && x.balance > 0 && can("invoices.issue") && <Button variant="outline" onClick={() => setRemind(true)} data-testid="invoice-remind"><BellRing size={16} /> Send reminder</Button>}
          {issued && x.balance > 0 && can("receipts.record") && <Button onClick={() => setPay("receipt")} data-testid="record-payment"><Banknote size={16} /> Record payment</Button>}
        </div>
      </div>

      {issued && x.balance > 0 && (x.unapplied_advances?.length ?? 0) > 0 && can("receipts.record") && (
        <Card className="rounded-lg border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/30" data-testid="unapplied-advances">
          <div className="font-semibold text-amber-900 dark:text-amber-200">The customer has paid in advance, but it is not on this invoice yet</div>
          <ul className="mt-2 space-y-1.5">
            {x.unapplied_advances?.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center justify-between gap-2">
                <span><Link href={`/receipts/${a.id}`} className="font-mono underline">{a.receipt_no}</Link> · {INR2(a.amount)} · {fmtDate(a.received_date)}</span>
                <Button size="sm" variant="outline" disabled={busy} onClick={() => void applyAdvance(a.id)} data-testid={`apply-advance-${a.id}`}>
                  Apply{a.amount > x.balance ? ` ${INR2(x.balance)}` : ""} to this invoice
                </Button>
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-amber-900/80 dark:text-amber-200/80">Apply it only if it was paid for this trip. If it was entered twice by mistake, open the receipt and cancel the extra one instead.</p>
        </Card>
      )}
      {x.status === "cancelled" && <Card className="rounded-lg p-4 text-sm">Cancelled{x.cancel_reason ? `: ${x.cancel_reason}` : ""}. The number {x.invoice_no} stays on record.</Card>}
      {x.payment_status === "overdue" && <Card className="rounded-lg border-red-300 p-4 text-sm text-red-800 dark:border-red-900 dark:text-red-300" data-testid="invoice-overdue">A payment is overdue{x.overdue_days ? ` by ${x.overdue_days} day${x.overdue_days === 1 ? "" : "s"}` : ""}: {INR2(x.balance)} is still owed.{" "}
        {x.reminders && x.reminders.length > 0 ? `Reminded ${x.reminders.length}× — last on ${fmtDate(x.reminders[0].created_at)} (${x.reminders[0].trigger === "auto" ? "automatic" : `by ${x.reminders[0].sent_by ?? "a colleague"}`}).` : "No reminder has been sent yet."}</Card>}
      {x.refundable > 0 && (
        <Card className="flex flex-wrap items-center justify-between gap-3 rounded-lg border-amber-300 p-4 text-sm dark:border-amber-900" data-testid="invoice-refundable">
          <span>The customer has paid {INR2(x.refundable)} more than is now owed. Refund it to settle the invoice.</span>
          {can("invoices.credit") && <Button size="sm" variant="outline" onClick={() => setPay("refund")} data-testid="record-refund"><Undo2 size={14} /> Record refund</Button>}
        </Card>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card className="rounded-lg p-4"><div className="label-caps">Total</div><div className="mt-1 font-display text-2xl font-black" data-testid="invoice-total-h">{INR2(x.total)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Received</div><div className="mt-1 font-display text-2xl font-black text-emerald-600 dark:text-emerald-400">{INR2(x.paid_total)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Credited</div><div className="mt-1 font-display text-2xl font-black">{INR2(x.credited_total)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Balance due</div><div className={cn("mt-1 font-display text-2xl font-black", x.balance > 0 && issued && "text-red-600 dark:text-red-400")} data-testid="invoice-balance">{issued ? INR2(x.balance) : "—"}</div>{issued && x.balance > 0 && x.next_due && <div className="text-xs text-muted-foreground">next {fmtDate(x.next_due)}</div>}</Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <div className="space-y-6">
          <Card className="overflow-hidden rounded-lg">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-sm">
                <thead><tr className="border-b border-border bg-secondary/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <th className="px-4 py-2.5">Description</th><th className="px-2 py-2.5 text-right">Qty</th><th className="px-2 py-2.5 text-right">Rate</th><th className="px-2 py-2.5 text-right">Discount</th><th className="px-2 py-2.5 text-right">GST</th><th className="px-4 py-2.5 text-right">Amount</th>
                </tr></thead>
                <tbody>
                  {x.lines?.map((l, i) => (
                    <tr key={i} className="border-b border-border last:border-0">
                      <td className="px-4 py-2.5">{l.description}</td><td className="px-2 py-2.5 text-right">{l.qty}</td><td className="px-2 py-2.5 text-right">{INR2(l.unit_price)}</td>
                      <td className="px-2 py-2.5 text-right">{l.discount ? INR2(l.discount) : "—"}</td><td className="px-2 py-2.5 text-right">{l.tax_rate}%</td><td className="px-4 py-2.5 text-right font-medium">{INR2(l.total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="ml-auto w-full max-w-xs space-y-1.5 px-4 py-3 text-sm" data-testid="invoice-tax-box">
              <div className="flex justify-between"><span className="text-muted-foreground">Subtotal</span><span>{INR2(x.subtotal)}</span></div>
              {x.discount_total > 0 && <div className="flex justify-between"><span className="text-muted-foreground">Discount</span><span>− {INR2(x.discount_total)}</span></div>}
              {x.gst_mode === "cgst_sgst" && <><div className="flex justify-between"><span className="text-muted-foreground">CGST</span><span>{INR2(x.cgst_total)}</span></div><div className="flex justify-between"><span className="text-muted-foreground">SGST</span><span>{INR2(x.sgst_total)}</span></div></>}
              {x.gst_mode === "igst" && <div className="flex justify-between"><span className="text-muted-foreground">IGST</span><span>{INR2(x.igst_total)}</span></div>}
              {x.gst_mode === "unspecified" && <div className="flex justify-between"><span className="text-muted-foreground">GST</span><span>{INR2(x.tax_total)}</span></div>}
              {x.tcs_total > 0 && <div className="flex justify-between"><span className="text-muted-foreground">TCS ({x.tcs_rate}%)</span><span>{INR2(x.tcs_total)}</span></div>}
              <div className="flex justify-between border-t border-border pt-2 font-bold"><span>Total</span><span>{INR2(x.total)}</span></div>
            </div>
          </Card>

          <Card className="rounded-lg p-5" data-testid="invoice-schedule">
            <h3 className="mb-2 font-display font-bold">Payment schedule</h3>
            {(x.installments?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">No schedule.</p>}
            <ul className="divide-y divide-border">
              {x.installments?.map((s, i) => (
                <li key={i} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                  <div><div className="font-medium">{s.label}</div><div className="text-xs text-muted-foreground">Due {fmtDate(s.due_date)}</div></div>
                  <div className="flex items-center gap-3">
                    <span>{INR2(s.amount)}</span>
                    {issued && <span className={cn("rounded border px-2 py-0.5 text-xs", s.status === "paid" ? "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300" : s.status === "overdue" ? "border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300" : "border-border text-muted-foreground")}>{SCHED[s.status ?? "due"]}</span>}
                  </div>
                </li>
              ))}
            </ul>
          </Card>

          {!draft && (
            <Card className="rounded-lg p-5" data-testid="invoice-payments">
              <h3 className="mb-2 font-display font-bold">Payments</h3>
              {(x.receipts?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">No payments yet.</p>}
              <ul className="divide-y divide-border">
                {x.receipts?.map((r) => (
                  <li key={r.id}>
                    <Link href={`/receipts/${r.id}`} className="-mx-2 flex items-center justify-between gap-3 rounded px-2 py-2.5 text-sm hover:bg-secondary/40" data-testid={`invoice-receipt-${r.id}`}>
                      <div className="min-w-0">
                        <div className={cn("font-medium", r.status === "cancelled" && "line-through text-muted-foreground")}>{r.kind === "refund" ? "Refund" : "Payment"} · <span className="font-mono">{r.receipt_no}</span></div>
                        <div className="text-xs text-muted-foreground">{fmtDate(r.received_date)} · {modeLabel(r.mode)}{r.reference ? ` · ${r.reference}` : ""}{r.status === "cancelled" ? " · cancelled" : ""}</div>
                      </div>
                      <span className={cn("shrink-0 font-semibold", r.kind === "refund" && "text-amber-700 dark:text-amber-300")}>{r.kind === "refund" ? "− " : ""}{INR2(r.amount)}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </div>

        <div className="space-y-6">
          <Card className="rounded-lg p-5">
            <h3 className="mb-2 font-display font-bold">Billed to</h3>
            <dl>
              <Row label="Name">{x.bill_name || x.customer?.name}</Row>
              {x.bill_address && <Row label="Address">{x.bill_address}</Row>}
              {x.bill_gstin && <Row label="GSTIN"><span className="font-mono">{x.bill_gstin}</span></Row>}
              <Row label="Supply">{x.place_of_supply ? `${x.place_of_supply}${x.gst_mode === "igst" ? " (IGST)" : x.gst_mode === "cgst_sgst" ? " (CGST + SGST)" : ""}` : "State not set. Add the customer's state for the GST split."}</Row>
              {x.sac_code && <Row label="SAC"><span className="font-mono">{x.sac_code}</span></Row>}
              <Row label="Trip">{[x.destination, x.travel_date ? `Travel ${fmtDate(x.travel_date)}` : null].filter(Boolean).join(" · ") || "—"}</Row>
              <Row label="Issued">{x.status === "draft" ? "Not issued" : fmtDate(x.issue_date)}</Row>
              <Row label="Owner">{x.owner?.name ?? "Unassigned"}</Row>
              {x.notes && <Row label="Notes"><span className="whitespace-pre-line">{x.notes}</span></Row>}
            </dl>
          </Card>

          {(x.credit_notes?.length ?? 0) > 0 && (
            <Card className="rounded-lg p-5" data-testid="invoice-credit-notes">
              <h3 className="mb-2 font-display font-bold">Credit notes</h3>
              <ul className="divide-y divide-border">
                {x.credit_notes!.map((c) => (
                  <li key={c.id} className="py-2.5 text-sm">
                    <div className="flex justify-between"><span className="font-mono font-medium">{c.credit_note_no}</span><span className="font-semibold">− {INR2(c.amount)}</span></div>
                    <div className="text-xs text-muted-foreground">{fmtDate(c.issue_date)} · {c.reason} · GST {INR2(c.tax_part)}</div>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {issued && (
            <Card className="space-y-2 rounded-lg p-5">
              <h3 className="font-display font-bold">Corrections</h3>
              <p className="text-sm text-muted-foreground">Issued invoices are never edited. Use a credit note to reduce what the customer owes.</p>
              <div className="flex flex-wrap gap-2">
                {can("invoices.credit") && creditRoom > 0 && <Button size="sm" variant="outline" onClick={() => setCredit(true)} data-testid="issue-credit-note">Issue credit note</Button>}
                {can("invoices.issue") && !hasMoney && <Button size="sm" variant="outline" disabled={busy} onClick={cancel} data-testid="invoice-cancel"><XCircle size={14} /> Cancel invoice</Button>}
              </div>
              {hasMoney && can("invoices.issue") && <p className="text-xs text-muted-foreground">An invoice with payments cannot be cancelled. Credit the full amount instead.</p>}
            </Card>
          )}
          <CustomerReplies base={`/invoices/${x.id}`} />
          {draft && can("invoices.delete", "invoices.create") && <Button variant="ghost" size="sm" className="text-destructive" onClick={remove} data-testid="invoice-delete"><Trash2 size={14} /> Delete draft</Button>}
        </div>
      </div>

      <PaymentDialog open={pay !== null} onOpenChange={(o) => !o && setPay(null)} endpoint={pay === "refund" ? `/invoices/${x.id}/refunds` : `/invoices/${x.id}/receipts`}
                     kind={pay === "refund" ? "refund" : "receipt"} suggested={pay === "refund" ? x.refundable : x.balance} max={pay === "refund" ? x.refundable : x.balance} onSaved={() => void load()} />
      <CreditNoteDialog invoiceId={x.id} open={credit} onOpenChange={setCredit} room={creditRoom} onSaved={(inv) => setX(inv)} />
      <SendDocDialog basePath={`/invoices/${x.id}`} title="Invoice" open={send} onOpenChange={setSend} />
      <SendDocDialog reminder basePath={`/invoices/${x.id}`} title="Reminder" open={remind} onOpenChange={setRemind} onSent={load} />
    </div>
  );
}
