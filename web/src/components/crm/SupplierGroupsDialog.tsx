"use client";

import { useEffect, useMemo, useState } from "react";
import { Pencil, Plus, Search, Trash2, Users } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { Supplier, SupplierGroup } from "@/types";

/** Make, change and remove supplier groups (for example "Vietnam desk"). A group only bundles suppliers; deleting it never deletes them. */
export default function SupplierGroupsDialog({ open, onOpenChange, suppliers, groups, onChanged }: {
  open: boolean; onOpenChange: (o: boolean) => void; suppliers: Supplier[]; groups: SupplierGroup[]; onChanged: () => void;
}) {
  const [editing, setEditing] = useState<SupplierGroup | "new" | null>(null);
  const [name, setName] = useState("");
  const [destination, setDestination] = useState("");
  const [members, setMembers] = useState<Set<string>>(new Set());
  const [q, setQ] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => { if (!open) setEditing(null); }, [open]);

  const start = (g: SupplierGroup | "new") => {
    setEditing(g);
    setName(g === "new" ? "" : g.name);
    setDestination(g === "new" ? "" : g.destination ?? "");
    setMembers(new Set(g === "new" ? [] : g.supplier_ids));
    setQ("");
  };

  const shown = useMemo(() => {
    const query = q.trim().toLowerCase();
    return suppliers.filter((s) => !query || s.name.toLowerCase().includes(query) || (s.destination ?? "").toLowerCase().includes(query) || (s.email ?? "").toLowerCase().includes(query));
  }, [suppliers, q]);
  const toggle = (id: string) => setMembers((cur) => { const n = new Set(cur); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  // one tap to take everyone who handles the destination typed above
  const addByDestination = () => {
    const d = destination.trim().toLowerCase();
    if (!d) return;
    setMembers((cur) => { const n = new Set(cur); suppliers.filter((s) => (s.destination ?? "").trim().toLowerCase() === d).forEach((s) => n.add(s.id)); return n; });
  };

  const save = async () => {
    if (!name.trim()) { toast.error("Give the group a name"); return; }
    setBusy(true);
    try {
      const body = { name: name.trim(), destination: destination.trim(), supplier_ids: Array.from(members) };
      if (editing === "new") await api.post("/supplier-groups", body);
      else if (editing) await api.patch(`/supplier-groups/${editing.id}`, body);
      toast.success(editing === "new" ? "Group created" : "Group saved");
      setEditing(null);
      onChanged();
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const remove = async (g: SupplierGroup) => {
    if (!window.confirm(`Delete the group "${g.name}"?\n\nThe ${g.supplier_ids.length} supplier${g.supplier_ids.length === 1 ? "" : "s"} in it are not deleted.`)) return;
    try { await api.delete(`/supplier-groups/${g.id}`); toast.success("Group deleted"); onChanged(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
      <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg" data-testid="groups-dialog">
        <DialogHeader>
          <DialogTitle className="font-display">{editing ? (editing === "new" ? "New group" : "Edit group") : "Supplier groups"}</DialogTitle>
          <DialogDescription>{editing ? "Pick the suppliers that belong together." : "Pick a whole group at once when sending a supplier mail."}</DialogDescription>
        </DialogHeader>

        {!editing ? (
          <div className="space-y-3">
            {groups.length === 0 && <p className="text-sm text-muted-foreground">No groups yet. Make one, for example “Vietnam desk”, with every supplier who quotes Vietnam.</p>}
            <ul className="divide-y divide-border rounded-md border border-border" data-testid="group-list">
              {groups.map((g) => (
                <li key={g.id} className="flex items-center gap-2 px-3 py-2" data-testid={`group-item-${g.id}`}>
                  <Users size={15} className="shrink-0 text-muted-foreground" />
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium">{g.name}</div>
                    <div className="truncate text-xs text-muted-foreground">{g.supplier_ids.length} supplier{g.supplier_ids.length === 1 ? "" : "s"}{g.destination ? ` · ${g.destination}` : ""}</div>
                  </div>
                  <Button type="button" size="sm" variant="ghost" onClick={() => start(g)} aria-label={`Edit ${g.name}`} data-testid={`group-edit-${g.id}`}><Pencil size={14} /></Button>
                  <Button type="button" size="sm" variant="ghost" onClick={() => remove(g)} aria-label={`Delete ${g.name}`}><Trash2 size={14} className="text-destructive" /></Button>
                </li>
              ))}
            </ul>
            <Button type="button" onClick={() => start("new")} data-testid="group-new"><Plus size={15} /> New group</Button>
          </div>
        ) : (
          <div className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div><Label className="label-caps">Group name</Label><Input className="mt-1.5" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Vietnam desk" autoFocus data-testid="group-name" /></div>
              <div><Label className="label-caps">Destination (optional)</Label><Input className="mt-1.5" value={destination} onChange={(e) => setDestination(e.target.value)} placeholder="e.g. Vietnam" data-testid="group-destination" /></div>
            </div>
            <div>
              <div className="flex items-center justify-between gap-2">
                <Label className="label-caps">Suppliers · {members.size} in this group</Label>
                {destination.trim() && <button type="button" onClick={addByDestination} className="text-xs font-medium underline underline-offset-2 text-muted-foreground hover:text-foreground" data-testid="group-add-dest">Add all for “{destination.trim()}”</button>}
              </div>
              <div className="relative mt-1.5">
                <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search suppliers" className="h-8 pl-8 text-[13px]" />
              </div>
              <ul className="mt-1.5 max-h-60 divide-y divide-border overflow-y-auto rounded-md border border-border" data-testid="group-members">
                {shown.map((s) => (
                  <li key={s.id}>
                    <label className="flex cursor-pointer items-start gap-2 px-2.5 py-1.5 text-sm hover:bg-secondary/50">
                      <Checkbox className="mt-0.5" checked={members.has(s.id)} onCheckedChange={() => toggle(s.id)} data-testid={`group-member-${s.id}`} />
                      <span className="min-w-0"><span className="block truncate font-medium leading-tight">{s.name}</span><span className="block truncate text-[11px] text-muted-foreground">{s.email}{s.destination ? ` · ${s.destination}` : ""}</span></span>
                    </label>
                  </li>
                ))}
                {shown.length === 0 && <li className="p-3 text-sm text-muted-foreground">No suppliers match.</li>}
              </ul>
            </div>
            <DialogFooter className="gap-2 sm:gap-2">
              <Button type="button" variant="outline" onClick={() => setEditing(null)} disabled={busy}>Back</Button>
              <Button type="button" onClick={save} disabled={busy} data-testid="group-save">{busy ? "Saving…" : "Save group"}</Button>
            </DialogFooter>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
