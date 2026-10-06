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
import type { BankOption, LedgerAccount, MoneyEntryRow } from "@/types";
import { PAY_MODES } from "./shared";

const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; };

/** Money going out (an expense) or coming in (other income) that is not a customer or supplier payment. */
export default function MoneyDialog({ open, onOpenChange, kind, onSaved }: {
  open: boolean; onOpenChange: (o: boolean) => void; kind: "expense" | "income"; onSaved: (m: MoneyEntryRow) => void;
}) {
  const out = kind === "expense";
  const [accounts, setAccounts] = useState<LedgerAccount[]>([]);
  const [banks, setBanks] = useState<BankOption[]>([]);
  const [account, setAccount] = useState("");
  const [amount, setAmount] = useState("");
  const [date, setDate] = useState(today());
  const [mode, setMode] = useState("bank_transfer");
  const [bank, setBank] = useState("auto");
  const [payee, setPayee] = useState("");
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setAccount(""); setAmount(""); setDate(today()); setMode("bank_transfer"); setBank("auto"); setPayee(""); setReference(""); setNotes(""); setError(""); setSaving(false);
    api.get<{ items: LedgerAccount[] }>("/finance/accounts", { params: { type: kind, active: "1" } }).then((r) => setAccounts(r.data.items.filter((a) => a.code !== "4000" && a.code !== "4090" && a.code !== "5000"))).catch(() => setAccounts([]));
    api.get<BankOption[]>("/finance/bank-options").then((r) => setBanks(r.data)).catch(() => setBanks([]));
  }, [open, kind]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!account) { setError(out ? "Choose what the money was spent on." : "Choose the type of income."); return; }
    if (!(Number(amount) > 0)) { setError("Enter the amount."); return; }
    setSaving(true); setError("");
    try {
      const { data } = await api.post<MoneyEntryRow>("/finance/money", {
        kind, account_id: account, amount: Number(amount), entry_date: date, mode, payee, reference, notes, ...(bank !== "auto" ? { bank_account_id: bank } : {}),
      });
      toast.success(`${data.entry_no} recorded`); onSaved(data); onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-w-md" data-testid="money-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">{out ? "Record money out" : "Record money in"}</DialogTitle>
          <DialogDescription>{out ? "Rent, salaries, software and other running costs. Supplier bills and customer payments have their own pages." : "Income that is not a customer payment, such as commission or interest."}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div>
            <Label>{out ? "Spent on" : "Income type"} *</Label>
            <Select value={account} onValueChange={setAccount}>
              <SelectTrigger className="mt-1.5 h-10" data-testid="money-account"><SelectValue placeholder="Choose an account" /></SelectTrigger>
              <SelectContent className="max-h-72">{accounts.map((a) => <SelectItem key={a.id} value={a.id}>{a.code} · {a.name}</SelectItem>)}</SelectContent>
            </Select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><Label htmlFor="mo-amt">Amount (₹) *</Label><Input id="mo-amt" type="number" min={0} step="0.01" className="mt-1.5 h-10" value={amount} onChange={(e) => setAmount(e.target.value)} data-testid="money-amount" /></div>
            <div><Label htmlFor="mo-date">Date</Label><Input id="mo-date" type="date" max={today()} className="mt-1.5 h-10" value={date} onChange={(e) => setDate(e.target.value)} /></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Mode</Label>
              <Select value={mode} onValueChange={setMode}><SelectTrigger className="mt-1.5 h-10"><SelectValue /></SelectTrigger><SelectContent>{PAY_MODES.map((m) => <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>)}</SelectContent></Select>
            </div>
            {banks.length > 0 && (
              <div>
                <Label>{out ? "Paid from" : "Deposited to"}</Label>
                <Select value={bank} onValueChange={setBank}>
                  <SelectTrigger className="mt-1.5 h-10" data-testid="money-bank"><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value="auto">{mode === "cash" ? "Cash in hand" : "Default bank"}</SelectItem>{banks.map((b) => <SelectItem key={b.id} value={b.id}>{b.name}</SelectItem>)}</SelectContent>
                </Select>
              </div>
            )}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><Label htmlFor="mo-payee">{out ? "Paid to" : "Received from"}</Label><Input id="mo-payee" className="mt-1.5 h-10" value={payee} onChange={(e) => setPayee(e.target.value)} /></div>
            <div><Label htmlFor="mo-ref">Reference</Label><Input id="mo-ref" className="mt-1.5 h-10" value={reference} onChange={(e) => setReference(e.target.value)} /></div>
          </div>
          <div><Label htmlFor="mo-notes">Notes</Label><Textarea id="mo-notes" rows={2} className="mt-1.5" value={notes} onChange={(e) => setNotes(e.target.value)} /></div>
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="money-save">{saving && <Loader2 size={14} className="animate-spin" />} {out ? "Record expense" : "Record income"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
