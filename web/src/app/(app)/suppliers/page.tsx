"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Pagination, usePagination } from "@/components/ui/pagination";
import { toast } from "sonner";
import { Trash2, Plus, Pencil } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import { Checkbox } from "@/components/ui/checkbox";
import type { PortalUser, Supplier, SupplierCreate } from "@/types";

/** Who at the supplier may sign in to the supplier portal (only meaningful once the supplier exists). */
function PortalUsers({ supplierId }: { supplierId: string }) {
  const [users, setUsers] = useState<PortalUser[]>([]);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [busy, setBusy] = useState(false);

  const load = async () => {
    try { const { data } = await api.get<PortalUser[]>(`/suppliers/${supplierId}/portal-users`); setUsers(data); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { void load(); }, [supplierId]);

  const add = async () => {
    if (!email.trim()) return;
    setBusy(true);
    try { await api.post(`/suppliers/${supplierId}/portal-users`, { name: name.trim(), email: email.trim() }); setName(""); setEmail(""); await load(); toast.success("Added"); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };
  const toggle = async (u: PortalUser) => {
    try { await api.patch(`/suppliers/${supplierId}/portal-users/${u.id}`, { active: !u.active }); await load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  const remove = async (u: PortalUser) => {
    if (!window.confirm(`Remove portal access for ${u.email}?`)) return;
    try { await api.delete(`/suppliers/${supplierId}/portal-users/${u.id}`); await load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <div className="space-y-2 rounded-md border border-border p-3" data-testid="portal-users">
      <div className="label-caps">People who can sign in</div>
      {users.length === 0 && <div className="text-xs text-muted-foreground">No one yet. The main e-mail of the supplier is added when you save with the portal switched on.</div>}
      <ul className="divide-y divide-border">
        {users.map((u) => (
          <li key={u.id} className="flex items-center justify-between gap-2 py-1.5 text-sm">
            <div className="min-w-0">
              <div className={u.active ? "truncate" : "truncate text-muted-foreground line-through"}>{u.name ? `${u.name} · ` : ""}{u.email}</div>
              <div className="text-xs text-muted-foreground">{u.last_login_at ? `Last sign-in ${new Date(u.last_login_at).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" })}` : "Never signed in"}</div>
            </div>
            <div className="flex shrink-0 gap-1">
              <Button type="button" size="sm" variant="ghost" onClick={() => toggle(u)}>{u.active ? "Pause" : "Resume"}</Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => remove(u)} aria-label={`Remove ${u.email}`}><Trash2 size={14} className="text-destructive" /></Button>
            </div>
          </li>
        ))}
      </ul>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1.4fr_auto]">
        <Input placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} />
        <Input type="email" placeholder="colleague@supplier.com" value={email} onChange={(e) => setEmail(e.target.value)} data-testid="portal-user-email" />
        <Button type="button" variant="outline" onClick={add} disabled={busy || !email.trim()} data-testid="portal-user-add">Add</Button>
      </div>
    </div>
  );
}

export default function Suppliers() {
  const { can } = useCurrentUser();
  const canEdit = can("suppliers.manage");
  const [rows, setRows] = useState<Supplier[]>([]);
  const EMPTY: SupplierCreate = { name: "", email: "", phone: "", destination: "", country: "", portal_enabled: false };
  const [f, setF] = useState<SupplierCreate>(EMPTY);
  const [open, setOpen] = useState(false);
  const [editId, setEditId] = useState<string | null>(null); // null = adding
  const [saving, setSaving] = useState(false);

  const pg = usePagination(rows);

  // bulk portal switch
  const [sel, setSel] = useState<Set<string>>(new Set());
  const pageIds = pg.pageItems.map((s) => s.id);
  const allOnPage = pageIds.length > 0 && pageIds.every((id) => sel.has(id));
  const toggleOne = (id: string) => setSel((p) => { const n = new Set(p); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  const togglePage = () => setSel((p) => { const n = new Set(p); pageIds.forEach((id) => (allOnPage ? n.delete(id) : n.add(id))); return n; });
  const bulkPortal = async (enabled: boolean) => {
    const n = sel.size;
    if (!window.confirm(enabled
      ? `Switch the supplier portal ON for ${n} supplier${n === 1 ? "" : "s"}?\n\nFrom the next enquiry they get a short link e-mail and send their quote through the portal.`
      : `Switch the supplier portal OFF for ${n} supplier${n === 1 ? "" : "s"}?\n\nAnyone signed in is signed out, and they get the full enquiry by e-mail again.`)) return;
    try {
      const { data } = await api.post<{ changed: number; skipped: { name: string; reason: string }[] }>("/suppliers/portal-bulk", { ids: Array.from(sel), enabled });
      toast.success(`Portal ${enabled ? "on" : "off"} for ${data.changed} supplier${data.changed === 1 ? "" : "s"}`);
      if (data.skipped.length) toast.warning(`Skipped: ${data.skipped.map((x) => `${x.name} (${x.reason})`).join("; ")}`, { duration: 12000 });
      setSel(new Set()); load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const load = async () => {
    try { const { data } = await api.get<Supplier[]>("/suppliers"); setRows(data); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  useEffect(() => { load(); }, []);

  const openAdd = () => { setEditId(null); setF(EMPTY); setOpen(true); };
  const openEdit = (s: Supplier) => {
    setEditId(s.id);
    setF({ name: s.name ?? "", email: s.email ?? "", phone: s.phone ?? "", destination: s.destination ?? "", country: s.country ?? "", portal_enabled: !!s.portal_enabled });
    setOpen(true);
  };

  const save = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (editId) await api.patch(`/suppliers/${editId}`, f);
      else await api.post("/suppliers", f);
      toast.success(editId ? "Supplier updated" : "Supplier added");
      setF(EMPTY);
      setEditId(null);
      setOpen(false);
      load();
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setSaving(false); }
  };

  const remove = async (id: string) => {
    if (!window.confirm("Delete supplier?")) return;
    try { await api.delete(`/suppliers/${id}`); toast.success("Deleted"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <div className="space-y-6" data-testid="suppliers-page">
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="label-caps">Master data</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Suppliers</h1>
        </div>
        {canEdit && (
          <Button onClick={openAdd} data-testid="s-add-btn">
            <Plus size={15} /> Add supplier
          </Button>
        )}
      </div>

      {canEdit && (
        <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) { setF(EMPTY); setEditId(null); } }}>
          <DialogContent className="sm:max-w-lg" data-testid="add-supplier-dialog">
            <DialogHeader>
              <DialogTitle className="font-display">{editId ? "Edit supplier" : "Add supplier"}</DialogTitle>
              <DialogDescription>Supplier details used when sending quote requests.</DialogDescription>
            </DialogHeader>
            <form onSubmit={save} className="space-y-4" data-testid="add-supplier-form">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div className="sm:col-span-2"><Label className="label-caps">Name</Label><Input className="mt-1.5" value={f.name} onChange={e => setF({ ...f, name: e.target.value })} required autoFocus data-testid="s-name" /></div>
                <div className="sm:col-span-2"><Label className="label-caps">Email</Label><Input type="email" className="mt-1.5" value={f.email} onChange={e => setF({ ...f, email: e.target.value })} required data-testid="s-email" /></div>
                <div><Label className="label-caps">Phone</Label><Input className="mt-1.5" value={f.phone} onChange={e => setF({ ...f, phone: e.target.value })} data-testid="s-phone" /></div>
                <div><Label className="label-caps">Destination</Label><Input className="mt-1.5" value={f.destination} onChange={e => setF({ ...f, destination: e.target.value })} data-testid="s-destination" /></div>
                <div className="sm:col-span-2"><Label className="label-caps">Country</Label><Input className="mt-1.5" value={f.country} onChange={e => setF({ ...f, country: e.target.value })} data-testid="s-country" /></div>
              </div>
              <label className="flex cursor-pointer items-start gap-2 rounded-md border border-border p-3 text-sm" data-testid="s-portal-label">
                <Checkbox checked={!!f.portal_enabled} onCheckedChange={(v) => setF({ ...f, portal_enabled: v === true })} data-testid="s-portal" />
                <span>
                  <span className="font-medium">Supplier portal access</span>
                  <span className="block text-xs text-muted-foreground">Enquiries to this supplier go out as a short link e-mail, and they send their quote through the portal instead of by e-mail.</span>
                </span>
              </label>
              {editId && f.portal_enabled && <PortalUsers supplierId={editId} />}
              <DialogFooter className="gap-2 sm:gap-2">
                <Button type="button" variant="outline" onClick={() => setOpen(false)} data-testid="s-cancel">Cancel</Button>
                <Button type="submit" disabled={saving} data-testid="s-add">{saving ? "Saving…" : editId ? "Save changes" : "Add supplier"}</Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      )}

      {canEdit && sel.size > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-secondary/50 px-3 py-2 text-sm" data-testid="s-bulk-bar">
          <span className="font-medium">{sel.size} selected</span>
          <Button size="sm" onClick={() => bulkPortal(true)} data-testid="s-bulk-on">Portal on</Button>
          <Button size="sm" variant="outline" onClick={() => bulkPortal(false)} data-testid="s-bulk-off">Portal off</Button>
          <Button size="sm" variant="ghost" onClick={() => setSel(new Set())}>Clear</Button>
        </div>
      )}

      <Card className="rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                {canEdit && <TableHead className="w-8"><Checkbox checked={allOnPage} onCheckedChange={togglePage} aria-label="Select all on this page" data-testid="s-select-page" /></TableHead>}
                <TableHead>Name</TableHead><TableHead>Email</TableHead>
                <TableHead>Phone</TableHead><TableHead>Destination</TableHead>
                <TableHead>Country</TableHead><TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.length === 0 && <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground py-8">No suppliers yet</TableCell></TableRow>}
              {pg.pageItems.map(s => (
                <TableRow key={s.id}>
                  {canEdit && <TableCell><Checkbox checked={sel.has(s.id)} onCheckedChange={() => toggleOne(s.id)} aria-label={`Select ${s.name}`} data-testid={`s-select-${s.id}`} /></TableCell>}
                  <TableCell className="font-medium">{s.name}{s.portal_enabled && <span className="ml-2 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 dark:bg-sky-950/60 dark:text-sky-300" title="Uses the supplier portal">Portal</span>}</TableCell>
                  <TableCell>{s.email}</TableCell>
                  <TableCell>{s.phone}</TableCell>
                  <TableCell>{s.destination}</TableCell>
                  <TableCell>{s.country}</TableCell>
                  <TableCell className="text-right">
                    {canEdit && <Button size="sm" variant="ghost" onClick={() => openEdit(s)} aria-label={`Edit ${s.name}`} title="Edit" data-testid={`s-edit-${s.id}`}><Pencil size={14} /></Button>}
                    {canEdit && <Button size="sm" variant="ghost" onClick={() => remove(s.id)} aria-label={`Delete ${s.name}`} title="Delete"><Trash2 size={14} className="text-destructive" /></Button>}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Pagination {...pg.paginationProps} />
      </Card>
    </div>
  );
}
