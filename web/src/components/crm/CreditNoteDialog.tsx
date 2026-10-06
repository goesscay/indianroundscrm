"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { Invoice } from "@/types";
import { INR2 } from "./shared";

export default function CreditNoteDialog({ invoiceId, open, onOpenChange, room, onSaved }: {
  invoiceId: string; open: boolean; onOpenChange: (o: boolean) => void; /** what is left of the invoice that can still be credited */ room: number; onSaved: (inv: Invoice) => void;
}) {
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => { if (open) { setAmount(""); setReason(""); setError(""); setSaving(false); } }, [open]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const n = Number(amount);
    if (!(n > 0)) { setError("Enter the amount to credit."); return; }
    if (!reason.trim()) { setError("Say why the credit note is being issued."); return; }
    setSaving(true); setError("");
    try {
      const { data } = await api.post<Invoice>(`/invoices/${invoiceId}/credit-notes`, { amount: n, reason: reason.trim() });
      toast.success("Credit note issued"); onSaved(data); onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-w-md" data-testid="credit-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">Issue credit note</DialogTitle>
          <DialogDescription>Reduces what the customer owes, for a cancellation or a correction. The GST on it is worked out in proportion to the invoice.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div>
            <Label htmlFor="cn-amt">Amount to credit, GST included (₹) *</Label>
            <Input id="cn-amt" type="number" min={0} step="0.01" className="mt-1.5 h-10" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus data-testid="credit-amount" />
            <div className="mt-1 flex items-center justify-between text-xs text-muted-foreground">
              <span>Up to {INR2(room)}</span>
              <button type="button" className="underline underline-offset-2" onClick={() => setAmount(String(room))}>Credit all</button>
            </div>
          </div>
          <div><Label htmlFor="cn-reason">Reason *</Label><Input id="cn-reason" className="mt-1.5 h-10" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Guest cancelled, rate corrected…" data-testid="credit-reason" /></div>
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="credit-save">{saving && <Loader2 size={14} className="animate-spin" />} Issue credit note</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
