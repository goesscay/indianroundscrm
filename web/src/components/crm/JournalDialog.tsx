"use client";

import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Loader2, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { JournalDetail, LedgerAccount } from "@/types";
import { INR2 } from "./shared";

interface Row { account_id: string; debit: string; credit: string; memo: string }
const blank = (): Row => ({ account_id: "", debit: "", credit: "", memo: "" });
const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; };

export default function JournalDialog({ open, onOpenChange, onSaved }: { open: boolean; onOpenChange: (o: boolean) => void; onSaved: (e: JournalDetail) => void }) {
  const [accounts, setAccounts] = useState<LedgerAccount[]>([]);
  const [date, setDate] = useState(today());
  const [narration, setNarration] = useState("");
  const [rows, setRows] = useState<Row[]>([blank(), blank()]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setDate(today()); setNarration(""); setRows([blank(), blank()]); setError(""); setSaving(false);
    api.get<{ items: LedgerAccount[] }>("/finance/accounts", { params: { active: "1" } }).then((r) => setAccounts(r.data.items)).catch(() => setAccounts([]));
  }, [open]);

  const totals = useMemo(() => {
    const dr = rows.reduce((a, r) => a + (Number(r.debit) || 0), 0);
    const cr = rows.reduce((a, r) => a + (Number(r.credit) || 0), 0);
    return { dr: Math.round(dr * 100) / 100, cr: Math.round(cr * 100) / 100, diff: Math.round((dr - cr) * 100) / 100 };
  }, [rows]);

  const upd = (i: number, patch: Partial<Row>) => setRows((l) => l.map((r, k) => (k === i ? { ...r, ...patch } : r)));
  // typing an amount on one side clears the other: a line is a debit or a credit
  const setDebit = (i: number, v: string) => upd(i, { debit: v, ...(v ? { credit: "" } : {}) });
  const setCredit = (i: number, v: string) => upd(i, { credit: v, ...(v ? { debit: "" } : {}) });

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!narration.trim()) { setError("Say what this entry is for."); return; }
    const lines = rows.filter((r) => r.account_id && (Number(r.debit) > 0 || Number(r.credit) > 0));
    if (lines.length < 2) { setError("Add at least two lines with an account and an amount."); return; }
    if (Math.abs(totals.diff) > 0.005) { setError(`Debits and credits must be equal. They differ by ${INR2(Math.abs(totals.diff))}.`); return; }
    setSaving(true); setError("");
    try {
      const { data } = await api.post<JournalDetail>("/finance/journal", {
        entry_date: date, narration: narration.trim(),
        lines: lines.map((r) => ({ account_id: r.account_id, debit: Number(r.debit) || 0, credit: Number(r.credit) || 0, memo: r.memo || null })),
      });
      toast.success(`${data.entry_no} posted`); onSaved(data); onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-h-[92vh] max-w-3xl overflow-y-auto" data-testid="journal-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">Journal entry</DialogTitle>
          <DialogDescription>For anything that does not come from an invoice or a receipt: expenses, transfers between accounts, corrections. Total debits must equal total credits.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-[170px_1fr]">
            <div><Label htmlFor="j-date">Date</Label><Input id="j-date" type="date" max={today()} className="mt-1.5 h-10" value={date} onChange={(e) => setDate(e.target.value)} data-testid="journal-date" /></div>
            <div><Label htmlFor="j-narr">What is this for? *</Label><Input id="j-narr" className="mt-1.5 h-10" value={narration} onChange={(e) => setNarration(e.target.value)} placeholder="e.g. October office rent" data-testid="journal-narration" /></div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[620px] text-sm">
              <thead><tr className="text-left text-xs uppercase tracking-wide text-muted-foreground"><th className="pb-2">Account</th><th className="w-[130px] pb-2">Debit ₹</th><th className="w-[130px] pb-2">Credit ₹</th><th className="pb-2">Note</th><th className="w-[36px]" /></tr></thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={i} data-testid={`journal-row-${i}`}>
                    <td className="pr-2 pb-2">
                      <Select value={r.account_id} onValueChange={(v) => upd(i, { account_id: v })}>
                        <SelectTrigger className="h-9" aria-label="Account" data-testid={`journal-account-${i}`}><SelectValue placeholder="Choose account" /></SelectTrigger>
                        <SelectContent className="max-h-72">{accounts.map((a) => <SelectItem key={a.id} value={a.id}>{a.code} · {a.name}</SelectItem>)}</SelectContent>
                      </Select>
                    </td>
                    <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} step="0.01" aria-label="Debit" value={r.debit} onChange={(e) => setDebit(i, e.target.value)} data-testid={`journal-debit-${i}`} /></td>
                    <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} step="0.01" aria-label="Credit" value={r.credit} onChange={(e) => setCredit(i, e.target.value)} data-testid={`journal-credit-${i}`} /></td>
                    <td className="pr-2 pb-2"><Input className="h-9" aria-label="Note" value={r.memo} onChange={(e) => upd(i, { memo: e.target.value })} /></td>
                    <td className="pb-2"><Button type="button" size="icon" variant="ghost" aria-label="Remove line" onClick={() => setRows((l) => (l.length > 2 ? l.filter((_, k) => k !== i) : l))}><Trash2 size={15} /></Button></td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="font-semibold">
                  <td className="pt-2"><Button type="button" size="sm" variant="outline" onClick={() => setRows((l) => [...l, blank()])} data-testid="journal-add-line"><Plus size={14} /> Add line</Button></td>
                  <td className="pt-2 pr-2" data-testid="journal-total-debit">{INR2(totals.dr)}</td><td className="pt-2 pr-2" data-testid="journal-total-credit">{INR2(totals.cr)}</td>
                  <td colSpan={2} className={Math.abs(totals.diff) > 0.005 ? "pt-2 text-destructive" : "pt-2 text-emerald-600 dark:text-emerald-400"} data-testid="journal-diff">{Math.abs(totals.diff) > 0.005 ? `Out by ${INR2(Math.abs(totals.diff))}` : totals.dr > 0 ? "Balanced" : ""}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="journal-save">{saving && <Loader2 size={14} className="animate-spin" />} Post entry</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
