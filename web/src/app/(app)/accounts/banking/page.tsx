"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { INR2 } from "@/components/crm/shared";
import type { BankAccountRow } from "@/types";

const KINDS = [{ value: "bank", label: "Bank account" }, { value: "cash", label: "Cash" }, { value: "card", label: "Card" }, { value: "wallet", label: "Wallet / UPI" }];

function BankDialog({ open, onOpenChange, bank, onSaved }: { open: boolean; onOpenChange: (o: boolean) => void; bank: BankAccountRow | null; onSaved: () => void }) {
  const editing = !!bank;
  const [name, setName] = useState("");
  const [kind, setKind] = useState("bank");
  const [bankName, setBankName] = useState("");
  const [last4, setLast4] = useState("");
  const [opening, setOpening] = useState("");
  const [openingDate, setOpeningDate] = useState("");
  const [isDefault, setIsDefault] = useState(false);
  const [active, setActive] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setName(bank?.name ?? ""); setKind(bank?.kind ?? "bank"); setBankName(bank?.bank_name ?? ""); setLast4(bank?.last4 ?? "");
    setOpening(""); setOpeningDate(""); setIsDefault(bank?.is_default ?? false); setActive(bank?.active ?? true); setError(""); setSaving(false);
  }, [open, bank]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!name.trim()) { setError("Give the account a name."); return; }
    setSaving(true); setError("");
    try {
      if (editing) await api.patch(`/finance/banks/${bank!.id}`, { name: name.trim(), bank_name: bankName, last4, is_default: isDefault, active });
      else await api.post("/finance/banks", { name: name.trim(), kind, bank_name: bankName, last4, is_default: isDefault, ...(opening.trim() !== "" ? { opening_balance: Number(opening), opening_date: openingDate || undefined } : {}) });
      toast.success(editing ? "Account updated" : "Account added"); onSaved(); onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-w-md" data-testid="bank-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">{editing ? `Edit ${bank!.name}` : "Add a bank or cash account"}</DialogTitle>
          <DialogDescription>Each account gets its own line in the books. Payments you record are deposited into the account you pick.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div><Label htmlFor="b-name">Name *</Label><Input id="b-name" className="mt-1.5 h-10" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. HDFC Current Account" autoFocus data-testid="bank-name" /></div>
          {!editing && (
            <div>
              <Label>Type</Label>
              <Select value={kind} onValueChange={setKind}><SelectTrigger className="mt-1.5 h-10"><SelectValue /></SelectTrigger><SelectContent>{KINDS.map((k) => <SelectItem key={k.value} value={k.value}>{k.label}</SelectItem>)}</SelectContent></Select>
            </div>
          )}
          <div className="grid grid-cols-2 gap-3">
            <div><Label htmlFor="b-bank">Bank</Label><Input id="b-bank" className="mt-1.5 h-10" value={bankName} onChange={(e) => setBankName(e.target.value)} /></div>
            <div><Label htmlFor="b-last">Last 4 digits</Label><Input id="b-last" className="mt-1.5 h-10" maxLength={8} value={last4} onChange={(e) => setLast4(e.target.value)} /></div>
          </div>
          {!editing && (
            <div className="grid grid-cols-2 gap-3">
              <div><Label htmlFor="b-open">Balance today (₹)</Label><Input id="b-open" type="number" step="0.01" className="mt-1.5 h-10" value={opening} onChange={(e) => setOpening(e.target.value)} placeholder="0" data-testid="bank-opening" /></div>
              <div><Label htmlFor="b-od">As on</Label><Input id="b-od" type="date" className="mt-1.5 h-10" value={openingDate} onChange={(e) => setOpeningDate(e.target.value)} /></div>
              <p className="col-span-2 -mt-1 text-xs text-muted-foreground">If the account already holds money, enter it here. It is booked against Opening balance equity.</p>
            </div>
          )}
          <label className="flex cursor-pointer items-center gap-2 text-sm"><Checkbox checked={isDefault} onCheckedChange={(v) => setIsDefault(v === true)} /> Use as the default for payments</label>
          {editing && <label className="flex cursor-pointer items-center gap-2 text-sm"><Checkbox checked={active} onCheckedChange={(v) => setActive(v === true)} /> Active</label>}
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="bank-save">{saving && <Loader2 size={14} className="animate-spin" />} {editing ? "Save" : "Add account"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function BankingPage() {
  const router = useRouter();
  const { can } = useCurrentUser();
  const [rows, setRows] = useState<BankAccountRow[] | null>(null);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState("");
  const [dlg, setDlg] = useState<{ open: boolean; bank: BankAccountRow | null }>({ open: false, bank: null });

  const load = useCallback(async () => {
    try { const { data } = await api.get<{ items: BankAccountRow[]; total: number }>("/finance/banks"); setRows(data.items); setTotal(data.total); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, []);
  useEffect(() => { void load(); }, [load]);
  const manage = can("finance.manage");

  return (
    <div className="space-y-6" data-testid="banking-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="font-display text-2xl font-black tracking-tight">Banking</h2>
          <p className="mt-1 text-sm text-muted-foreground">Your bank, cash and card accounts and what each holds according to the books.</p>
        </div>
        <div className="flex items-center gap-4">
          <div className="text-right"><div className="label-caps">Total</div><div className="font-display text-2xl font-black" data-testid="banking-total">{INR2(total)}</div></div>
          {manage && <Button onClick={() => setDlg({ open: true, bank: null })} data-testid="add-bank-btn">+ Add account</Button>}
        </div>
      </div>
      {error && <div className="text-sm text-destructive">{error}</div>}
      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader><TableRow><TableHead>Account</TableHead><TableHead>Type</TableHead><TableHead>Ledger code</TableHead><TableHead className="text-right">Balance</TableHead><TableHead /></TableRow></TableHeader>
            <TableBody>
              {rows === null && <TableRow><TableCell colSpan={5} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {rows?.length === 0 && <TableRow><TableCell colSpan={5} className="py-12 text-center text-muted-foreground">No accounts yet. Add your first bank account to start.</TableCell></TableRow>}
              {rows?.map((b) => (
                <TableRow key={b.id} className={cn("cursor-pointer hover:bg-secondary/50", !b.active && "opacity-60")} onClick={() => router.push(`/accounts/ledger?account=${b.account_id}`)} data-testid={`bank-row-${b.id}`}>
                  <TableCell><div className="font-medium">{b.name}{b.is_default && <span className="ml-2 rounded bg-secondary px-1.5 py-0.5 text-[10px] uppercase tracking-wide">Default</span>}{!b.active && <span className="ml-2 text-xs text-muted-foreground">inactive</span>}</div><div className="text-xs text-muted-foreground">{[b.bank_name, b.last4 ? `••${b.last4}` : null].filter(Boolean).join(" · ")}</div></TableCell>
                  <TableCell className="text-sm">{KINDS.find((k) => k.value === b.kind)?.label ?? b.kind}</TableCell>
                  <TableCell className="font-mono text-sm">{b.account_code}</TableCell>
                  <TableCell className={cn("text-right font-semibold", b.balance < 0 && "text-red-600 dark:text-red-400")}>{INR2(b.balance)}</TableCell>
                  <TableCell className="text-right">{manage && <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setDlg({ open: true, bank: b }); }} data-testid={`edit-bank-${b.id}`}>Edit</Button>}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </Card>
      <BankDialog open={dlg.open} onOpenChange={(o) => setDlg((d) => ({ ...d, open: o }))} bank={dlg.bank} onSaved={() => void load()} />
    </div>
  );
}
