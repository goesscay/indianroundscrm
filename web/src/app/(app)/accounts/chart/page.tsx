"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Loader2, Search } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { INR2 } from "@/components/crm/shared";
import type { AccountType, LedgerAccount } from "@/types";

const TYPES: { value: AccountType; label: string }[] = [
  { value: "asset", label: "Assets" }, { value: "liability", label: "Liabilities" }, { value: "equity", label: "Equity" },
  { value: "income", label: "Income" }, { value: "expense", label: "Expenses" },
];

function AccountDialog({ open, onOpenChange, account, onSaved }: { open: boolean; onOpenChange: (o: boolean) => void; account: LedgerAccount | null; onSaved: () => void }) {
  const editing = !!account;
  const locked = !!account && (account.is_system || account.used);
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [type, setType] = useState<AccountType>("expense");
  const [desc, setDesc] = useState("");
  const [active, setActive] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setCode(account?.code ?? ""); setName(account?.name ?? ""); setType(account?.type ?? "expense"); setDesc(account?.description ?? ""); setActive(account?.active ?? true); setError(""); setSaving(false);
  }, [open, account]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!code.trim() || !name.trim()) { setError("Enter a code and a name."); return; }
    setSaving(true); setError("");
    try {
      if (editing) await api.patch(`/finance/accounts/${account!.id}`, { name: name.trim(), description: desc, active, ...(locked ? {} : { code: code.trim(), type }) });
      else await api.post("/finance/accounts", { code: code.trim(), name: name.trim(), type, description: desc });
      toast.success(editing ? "Account updated" : "Account added"); onSaved(); onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-w-md" data-testid="account-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">{editing ? `Edit ${account!.code}` : "New account"}</DialogTitle>
          <DialogDescription>{locked ? "This account is used by the system or already has postings, so its code and type are fixed." : "Accounts are where journal entries land: assets, liabilities, equity, income and expenses."}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="grid grid-cols-[110px_1fr] gap-3">
            <div><Label htmlFor="a-code">Code *</Label><Input id="a-code" className="mt-1.5 h-10 font-mono" value={code} onChange={(e) => setCode(e.target.value)} disabled={locked} data-testid="account-code" /></div>
            <div><Label htmlFor="a-name">Name *</Label><Input id="a-name" className="mt-1.5 h-10" value={name} onChange={(e) => setName(e.target.value)} data-testid="account-name" /></div>
          </div>
          <div>
            <Label>Type</Label>
            <Select value={type} onValueChange={(v) => setType(v as AccountType)} disabled={locked}>
              <SelectTrigger className="mt-1.5 h-10" data-testid="account-type"><SelectValue /></SelectTrigger>
              <SelectContent>{TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label.replace(/s$/, "")}</SelectItem>)}</SelectContent>
            </Select>
          </div>
          <div><Label htmlFor="a-desc">Description</Label><Textarea id="a-desc" rows={2} className="mt-1.5" value={desc} onChange={(e) => setDesc(e.target.value)} /></div>
          {editing && !account!.is_system && <label className="flex cursor-pointer items-center gap-2 text-sm"><Checkbox checked={active} onCheckedChange={(v) => setActive(v === true)} /> Active (can be used in new entries)</label>}
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="account-save">{saving && <Loader2 size={14} className="animate-spin" />} {editing ? "Save" : "Add account"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function ChartPage() {
  const router = useRouter();
  const { can } = useCurrentUser();
  const [items, setItems] = useState<LedgerAccount[] | null>(null);
  const [q, setQ] = useState("");
  const [type, setType] = useState("all");
  const [error, setError] = useState("");
  const [dlg, setDlg] = useState<{ open: boolean; account: LedgerAccount | null }>({ open: false, account: null });

  const load = useCallback(async () => {
    try { const { data } = await api.get<{ items: LedgerAccount[] }>("/finance/accounts"); setItems(data.items); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, []);
  useEffect(() => { void load(); }, [load]);
  const manage = can("finance.manage");

  const shown = (items ?? []).filter((a) => (type === "all" || a.type === type) && (!q.trim() || `${a.code} ${a.name}`.toLowerCase().includes(q.trim().toLowerCase())));

  return (
    <div className="space-y-6" data-testid="chart-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="font-display text-2xl font-black tracking-tight">Chart of accounts</h2>
          <p className="mt-1 text-sm text-muted-foreground">Every place money can sit or come from. System accounts are used by automatic postings and cannot be removed.</p>
        </div>
        {manage && <Button onClick={() => setDlg({ open: true, account: null })} data-testid="add-account-btn">+ New account</Button>}
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-[220px] max-w-[320px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search code or name…" className="h-10 pl-9" data-testid="account-search" />
        </div>
        <Select value={type} onValueChange={setType}>
          <SelectTrigger className="h-10 w-[160px]" data-testid="filter-account-type"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">All types</SelectItem>{TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}</SelectContent>
        </Select>
      </div>
      {error && <div className="text-sm text-destructive">{error}</div>}
      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader><TableRow><TableHead className="w-[90px]">Code</TableHead><TableHead>Account</TableHead><TableHead>Type</TableHead><TableHead className="text-right">Balance</TableHead><TableHead /></TableRow></TableHeader>
            <TableBody>
              {items === null && <TableRow><TableCell colSpan={5} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {items && shown.length === 0 && <TableRow><TableCell colSpan={5} className="py-10 text-center text-muted-foreground">No accounts match.</TableCell></TableRow>}
              {shown.map((a) => (
                <TableRow key={a.id} className={cn("cursor-pointer hover:bg-secondary/50", !a.active && "opacity-60")} onClick={() => router.push(`/accounts/ledger?account=${a.id}`)} data-testid={`account-row-${a.code}`}>
                  <TableCell className="font-mono text-sm">{a.code}</TableCell>
                  <TableCell><span className="font-medium">{a.name}</span>{a.is_system && <span className="ml-2 rounded bg-secondary px-1.5 py-0.5 text-[10px] uppercase tracking-wide">System</span>}{!a.active && <span className="ml-2 text-xs text-muted-foreground">inactive</span>}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{a.type_label}</TableCell>
                  <TableCell className={cn("text-right font-medium", a.balance < 0 && "text-red-600 dark:text-red-400")}>{a.used ? INR2(a.balance) : "—"}</TableCell>
                  <TableCell className="text-right">{manage && <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setDlg({ open: true, account: a }); }} data-testid={`edit-account-${a.code}`}>Edit</Button>}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </Card>
      <AccountDialog open={dlg.open} onOpenChange={(o) => setDlg((d) => ({ ...d, open: o }))} account={dlg.account} onSaved={() => void load()} />
    </div>
  );
}
