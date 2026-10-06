"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { BankOption, Receipt } from "@/types";
import { INR2, PAY_MODES } from "./shared";

interface Props {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  /** POST target: /invoices/{id}/receipts, /invoices/{id}/refunds or /customers/{id}/advances */
  endpoint: string;
  kind: "receipt" | "refund" | "advance" | "supplier" | "supplier_advance";
  /** Name of the date field the endpoint expects. */
  dateField?: "received_date" | "paid_date";
  /** Suggested amount (invoice balance / refundable amount). */
  suggested?: number;
  /** Largest amount the server will accept, shown as a hint. */
  max?: number;
  onSaved: (r: Receipt) => void;
}

const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

const COPY = {
  receipt: { title: "Record payment", desc: "Money received from the customer against this invoice.", btn: "Record payment" },
  advance: { title: "Record advance", desc: "Money received before an invoice exists. You can apply it to an invoice later.", btn: "Record advance" },
  refund: { title: "Record refund", desc: "Money paid back to the customer after a credit note.", btn: "Record refund" },
  supplier: { title: "Pay supplier", desc: "A payment to the supplier against this bill.", btn: "Record payment" },
  supplier_advance: { title: "Advance to supplier", desc: "Money paid before a bill exists. Apply it to a bill later.", btn: "Record advance" },
} as const;

export default function PaymentDialog({ open, onOpenChange, endpoint, kind, dateField = "received_date", suggested, max, onSaved }: Props) {
  const [amount, setAmount] = useState("");
  const [date, setDate] = useState(today());
  const [mode, setMode] = useState("upi");
  const [reference, setReference] = useState("");
  const [deposited, setDeposited] = useState("");
  const [banks, setBanks] = useState<BankOption[]>([]);
  const [bank, setBank] = useState("auto");
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setAmount(suggested ? String(suggested) : ""); setDate(today()); setMode("upi"); setReference(""); setDeposited(""); setBank("auto"); setNotes(""); setError(""); setSaving(false);
  }, [open, suggested]);

  useEffect(() => {
    if (!open) return;
    api.get<BankOption[]>("/finance/bank-options").then((r) => setBanks(r.data)).catch(() => setBanks([]));
  }, [open]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const n = Number(amount);
    if (!(n > 0)) { setError("Enter the amount."); return; }
    setSaving(true); setError("");
    try {
      const { data } = await api.post<{ receipt?: Receipt; payment?: Receipt } & Receipt>(endpoint, { amount: n, [dateField]: date, mode, reference, notes, ...(banks.length ? (bank !== "auto" ? { bank_account_id: bank } : {}) : { deposited_to: deposited }) });
      const rec = (data.receipt ?? data.payment ?? data) as Receipt;
      toast.success(`${rec.receipt_no ?? (rec as unknown as { payment_no: string }).payment_no} recorded`);
      onSaved(rec); onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  const c = COPY[kind];
  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-w-md" data-testid="payment-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">{c.title}</DialogTitle>
          <DialogDescription>{c.desc}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div>
            <Label htmlFor="pay-amt">Amount (₹) *</Label>
            <Input id="pay-amt" type="number" min={0} step="0.01" className="mt-1.5 h-10" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus data-testid="payment-amount" />
            {max != null && <p className="mt-1 text-xs text-muted-foreground">{kind === "refund" ? "Can refund up to" : kind === "supplier" ? "Owed on this bill" : "Balance"} {INR2(max)}</p>}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><Label htmlFor="pay-date">Date</Label><Input id="pay-date" type="date" max={today()} className="mt-1.5 h-10" value={date} onChange={(e) => setDate(e.target.value)} /></div>
            <div>
              <Label>Mode</Label>
              <Select value={mode} onValueChange={setMode}>
                <SelectTrigger className="mt-1.5 h-10" data-testid="payment-mode"><SelectValue /></SelectTrigger>
                <SelectContent>{PAY_MODES.map((m) => <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>)}</SelectContent>
              </Select>
            </div>
          </div>
          <div><Label htmlFor="pay-ref">Reference</Label><Input id="pay-ref" className="mt-1.5 h-10" value={reference} onChange={(e) => setReference(e.target.value)} placeholder="UTR, cheque number, card last digits…" data-testid="payment-reference" /></div>
          {banks.length > 0 ? (
            <div>
              <Label>{kind === "refund" || kind === "supplier" || kind === "supplier_advance" ? "Paid from" : "Deposited to"}</Label>
              <Select value={bank} onValueChange={setBank}>
                <SelectTrigger className="mt-1.5 h-10" data-testid="payment-bank"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="auto">{mode === "cash" ? "Cash in hand" : `Default (${banks.find((b) => b.is_default)?.name ?? "Bank"})`}</SelectItem>
                  {banks.map((b) => <SelectItem key={b.id} value={b.id}>{b.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          ) : (
            <div><Label htmlFor="pay-dep">{kind === "refund" ? "Paid from" : "Deposited to"}</Label><Input id="pay-dep" className="mt-1.5 h-10" value={deposited} onChange={(e) => setDeposited(e.target.value)} placeholder="Bank or cash account (optional)" /></div>
          )}
          <div><Label htmlFor="pay-notes">Notes</Label><Textarea id="pay-notes" rows={2} className="mt-1.5" value={notes} onChange={(e) => setNotes(e.target.value)} /></div>
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="payment-save">{saving && <Loader2 size={14} className="animate-spin" />} {c.btn}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
