"use client";

import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Loader2, Plus, Search, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { Deal, LedgerAccount, Supplier, SupplierBill } from "@/types";
import { INR2 } from "./shared";

interface Row { description: string; amount: string; account_id: string }
const blank = (account = ""): Row => ({ description: "", amount: "", account_id: account });
const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; };

/** Enter a supplier's bill. Each line goes to a cost or expense account; a deal links the cost to a trip. */
export default function BillDialog({ open, onOpenChange, deal, onSaved }: {
  open: boolean; onOpenChange: (o: boolean) => void; deal?: { id: string; deal_no: string; name: string } | null; onSaved: (b: SupplierBill) => void;
}) {
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [accounts, setAccounts] = useState<LedgerAccount[]>([]);
  const [supplier, setSupplier] = useState("");
  const [ref, setRef] = useState("");
  const [billDate, setBillDate] = useState(today());
  const [due, setDue] = useState("");
  const [dealSel, setDealSel] = useState<{ id: string; deal_no: string; name: string } | null>(null);
  const [q, setQ] = useState("");
  const [results, setResults] = useState<Deal[]>([]);
  const reqId = useRef(0);
  const [notes, setNotes] = useState("");
  const [rows, setRows] = useState<Row[]>([blank()]);
  const [dup, setDup] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const costAccount = useMemo(() => accounts.find((a) => a.code === "5000")?.id ?? "", [accounts]);

  useEffect(() => {
    if (!open) return;
    setSupplier(""); setRef(""); setBillDate(today()); setDue(""); setDealSel(deal ?? null); setQ(""); setResults([]); setNotes(""); setDup(""); setError(""); setSaving(false);
    api.get<Supplier[]>("/suppliers").then((r) => setSuppliers(r.data)).catch(() => setSuppliers([]));
    api.get<{ accounts: LedgerAccount[] }>("/finance/bill-options").then((r) => {
      setAccounts(r.data.accounts); setRows([blank(r.data.accounts.find((a) => a.code === "5000")?.id ?? "")]);
    }).catch(() => setAccounts([]));
  }, [open, deal]);

  useEffect(() => {
    if (!open || dealSel || !q.trim()) { setResults([]); return; }
    const id = ++reqId.current;
    const t = setTimeout(() => {
      api.get<Deal[]>("/deals", { params: { q: q.trim(), limit: 8 } }).then((r) => { if (id === reqId.current) setResults(r.data); }).catch(() => { if (id === reqId.current) setResults([]); });
    }, 250);
    return () => clearTimeout(t);
  }, [q, dealSel, open]);

  const total = rows.reduce((a, r) => a + (Number(r.amount) || 0), 0);
  const upd = (i: number, patch: Partial<Row>) => setRows((l) => l.map((r, k) => (k === i ? { ...r, ...patch } : r)));

  const submit = async (e?: FormEvent, allowDuplicate = false) => {
    e?.preventDefault();
    if (!supplier) { setError("Choose the supplier."); return; }
    const lines = rows.filter((r) => r.description.trim() && Number(r.amount) > 0);
    if (lines.length === 0) { setError("Add at least one line with a description and an amount."); return; }
    setSaving(true); setError(""); setDup("");
    try {
      const { data } = await api.post<SupplierBill>("/finance/bills", {
        supplier_id: supplier, supplier_ref: ref.trim() || null, deal_id: dealSel?.id ?? null, bill_date: billDate, due_date: due || null, notes: notes.trim() || null,
        lines: lines.map((r) => ({ description: r.description.trim(), amount: Number(r.amount), account_id: r.account_id || costAccount })),
        ...(allowDuplicate ? { allow_duplicate: true } : {}),
      });
      toast.success(`${data.bill_no} entered`); onSaved(data); onOpenChange(false);
    } catch (err) {
      const msg = formatApiError(err);
      if (/already been entered/i.test(msg)) setDup(msg); else setError(msg);
    } finally { setSaving(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-h-[92vh] max-w-3xl overflow-y-auto" data-testid="bill-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">New supplier bill</DialogTitle>
          <DialogDescription>What a supplier is charging you. It is booked as a cost when you save it, and you pay it later.</DialogDescription>
        </DialogHeader>
        <form onSubmit={(e) => submit(e)} className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label>Supplier *</Label>
              <Select value={supplier} onValueChange={setSupplier}>
                <SelectTrigger className="mt-1.5 h-10" data-testid="bill-supplier"><SelectValue placeholder="Choose a supplier" /></SelectTrigger>
                <SelectContent className="max-h-72">{suppliers.map((s) => <SelectItem key={s.id} value={s.id}>{s.name}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <div><Label htmlFor="bl-ref">Supplier&apos;s invoice number</Label><Input id="bl-ref" className="mt-1.5 h-10" value={ref} onChange={(e) => setRef(e.target.value)} data-testid="bill-ref" /></div>
            <div><Label htmlFor="bl-date">Bill date</Label><Input id="bl-date" type="date" max={today()} className="mt-1.5 h-10" value={billDate} onChange={(e) => setBillDate(e.target.value)} /></div>
            <div><Label htmlFor="bl-due">Due date</Label><Input id="bl-due" type="date" className="mt-1.5 h-10" value={due} onChange={(e) => setDue(e.target.value)} data-testid="bill-due" /></div>
            <div className="sm:col-span-2">
              <Label>Trip (deal)</Label>
              {dealSel ? (
                <div className="mt-1.5 flex h-10 items-center justify-between rounded border border-border bg-secondary/50 px-3 text-sm">
                  <span className="truncate"><span className="font-medium">{dealSel.name}</span> <span className="font-mono text-xs text-muted-foreground">{dealSel.deal_no}</span></span>
                  {!deal && <button type="button" onClick={() => setDealSel(null)} aria-label="Remove deal" className="rounded p-1 hover:bg-accent"><X size={14} /></button>}
                </div>
              ) : (
                <div className="relative mt-1.5">
                  <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                  <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Link this cost to a trip: search deals…" className="h-10 pl-9" data-testid="bill-deal-search" />
                  {results.length > 0 && (
                    <ul className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded border border-border bg-popover shadow-md">
                      {results.map((d) => (
                        <li key={d.id}><button type="button" onClick={() => { setDealSel({ id: d.id, deal_no: d.deal_no, name: d.name }); setQ(""); }} className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-accent" data-testid={`bill-deal-opt-${d.id}`}>
                          <span className="truncate">{d.name}<span className="text-muted-foreground"> · {d.customer?.name}</span></span><span className="font-mono text-xs text-muted-foreground">{d.deal_no}</span></button></li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-sm">
              <thead><tr className="text-left text-xs uppercase tracking-wide text-muted-foreground"><th className="pb-2">What for</th><th className="w-[130px] pb-2">Amount ₹</th><th className="w-[220px] pb-2">Booked to</th><th className="w-[36px]" /></tr></thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={i} data-testid={`bill-line-${i}`}>
                    <td className="pr-2 pb-2"><Input className="h-9" aria-label="Description" value={r.description} onChange={(e) => upd(i, { description: e.target.value })} placeholder="e.g. Hotel, 4 nights" data-testid={`bill-desc-${i}`} /></td>
                    <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} step="0.01" aria-label="Amount" value={r.amount} onChange={(e) => upd(i, { amount: e.target.value })} data-testid={`bill-amount-${i}`} /></td>
                    <td className="pr-2 pb-2">
                      <Select value={r.account_id || costAccount} onValueChange={(v) => upd(i, { account_id: v })}>
                        <SelectTrigger className="h-9" aria-label="Account"><SelectValue placeholder="Cost of tours" /></SelectTrigger>
                        <SelectContent className="max-h-72">{accounts.map((a) => <SelectItem key={a.id} value={a.id}>{a.code} · {a.name}</SelectItem>)}</SelectContent>
                      </Select>
                    </td>
                    <td className="pb-2"><Button type="button" size="icon" variant="ghost" aria-label="Remove line" onClick={() => setRows((l) => (l.length > 1 ? l.filter((_, k) => k !== i) : l))}><Trash2 size={15} /></Button></td>
                  </tr>
                ))}
              </tbody>
              <tfoot><tr className="font-semibold"><td className="pt-1"><Button type="button" size="sm" variant="outline" onClick={() => setRows((l) => [...l, blank(costAccount)])} data-testid="bill-add-line"><Plus size={14} /> Add line</Button></td><td className="pt-2" colSpan={3} data-testid="bill-total">Total {INR2(total)}</td></tr></tfoot>
            </table>
          </div>
          <div><Label htmlFor="bl-notes">Notes</Label><Textarea id="bl-notes" rows={2} className="mt-1.5" value={notes} onChange={(e) => setNotes(e.target.value)} /></div>

          {dup && (
            <div className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200" role="alert" data-testid="bill-duplicate">
              {dup}
              <div className="mt-2 flex gap-2"><Button type="button" size="sm" variant="outline" onClick={() => submit(undefined, true)} disabled={saving}>Enter anyway</Button><Button type="button" size="sm" variant="ghost" onClick={() => setDup("")}>Cancel</Button></div>
            </div>
          )}
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="bill-save">{saving && <Loader2 size={14} className="animate-spin" />} Save bill</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
