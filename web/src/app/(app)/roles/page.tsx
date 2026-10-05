"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { ArrowLeft, Lock, Plus, Search, ShieldCheck, Trash2, Users as UsersIcon } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import type { PermissionGroup, RoleDef, RoleInput, RoleUser } from "@/types";

const BADGE = "inline-flex items-center rounded-sm px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider";

function RoleBadges({ role }: { role: RoleDef }) {
  return (
    <span className="inline-flex flex-wrap gap-1">
      <span className={cn(BADGE, role.is_system ? "bg-neutral-100 text-neutral-600" : "bg-neutral-900 text-white")}>
        {role.is_system ? "System" : "Custom"}
      </span>
      {role.all_permissions && <span className={cn(BADGE, "bg-emerald-50 text-emerald-700")}>Full access</span>}
    </span>
  );
}

const sameSet = (a: Set<string>, b: string[]) => a.size === b.length && b.every((k) => a.has(k));

export default function RolesPage() {
  const [roles, setRoles] = useState<RoleDef[]>([]);
  const [catalog, setCatalog] = useState<PermissionGroup[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [perms, setPerms] = useState<Set<string>>(new Set());
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [search, setSearch] = useState("");
  const [saving, setSaving] = useState(false);
  const [roleUsers, setRoleUsers] = useState<RoleUser[]>([]);
  const [mobileDetail, setMobileDetail] = useState(false);

  const [newOpen, setNewOpen] = useState(false);
  const [newName, setNewName] = useState("");
  const [newDesc, setNewDesc] = useState("");
  const [copyFrom, setCopyFrom] = useState("none");
  const [creating, setCreating] = useState(false);

  const [delOpen, setDelOpen] = useState(false);
  const [delError, setDelError] = useState("");
  const [deleting, setDeleting] = useState(false);

  const selected = useMemo(() => roles.find((r) => r.id === selectedId) ?? null, [roles, selectedId]);
  const allKeys = useMemo(() => catalog.flatMap((g) => g.items.map((i) => i.key)), [catalog]);
  const fullAccess = !!selected?.all_permissions;
  const dirty =
    !!selected &&
    !fullAccess &&
    (!sameSet(perms, selected.permissions) ||
      (!selected.is_system && (name.trim() !== selected.name || description !== (selected.description ?? ""))));

  const loadRoles = useCallback(async (keepId?: string | null) => {
    const { data } = await api.get<RoleDef[]>("/roles");
    setRoles(data);
    setSelectedId((cur) => {
      const want = keepId === undefined ? cur : keepId;
      return data.some((r) => r.id === want) ? want : data[0]?.id ?? null;
    });
    return data;
  }, []);

  useEffect(() => {
    (async () => {
      try {
        const [c] = await Promise.all([api.get<PermissionGroup[]>("/permissions"), loadRoles()]);
        setCatalog(c.data);
      } catch (e) {
        toast.error(formatApiError(e));
      } finally {
        setLoading(false);
      }
    })();
  }, [loadRoles]);

  // Reset the editor whenever the selected role (or its saved data) changes.
  useEffect(() => {
    if (!selected) return;
    setPerms(new Set(selected.permissions));
    setName(selected.name);
    setDescription(selected.description ?? "");
  }, [selected]);

  const userCount = selected?.user_count;
  useEffect(() => {
    if (!selectedId) {
      setRoleUsers([]);
      return;
    }
    let live = true;
    api
      .get<RoleUser[]>(`/roles/${selectedId}/users`)
      .then((r) => {
        if (live) setRoleUsers(r.data);
      })
      .catch(() => {
        if (live) setRoleUsers([]);
      });
    return () => {
      live = false;
    };
  }, [selectedId, userCount]);

  const selectRole = (id: string) => {
    if (id !== selectedId && dirty && !window.confirm("You have unsaved changes. Discard them?")) return;
    setSelectedId(id);
    setMobileDetail(true);
    setSearch("");
  };

  const discard = () => {
    if (!selected) return;
    setPerms(new Set(selected.permissions));
    setName(selected.name);
    setDescription(selected.description ?? "");
  };

  const toggle = (key: string, on: boolean) =>
    setPerms((p) => {
      const n = new Set(p);
      if (on) n.add(key);
      else n.delete(key);
      return n;
    });

  const toggleGroup = (g: PermissionGroup, on: boolean) =>
    setPerms((p) => {
      const n = new Set(p);
      g.items.forEach((i) => (on ? n.add(i.key) : n.delete(i.key)));
      return n;
    });

  const save = async () => {
    if (!selected || !dirty) return;
    if (!selected.is_system && !name.trim()) {
      toast.error("Role name is required");
      return;
    }
    setSaving(true);
    try {
      const body: RoleInput = { permissions: [...perms] };
      if (!selected.is_system) {
        body.name = name.trim();
        body.description = description;
      }
      await api.patch(`/roles/${selected.id}`, body);
      await loadRoles(selected.id);
      toast.success("Role saved");
    } catch (e) {
      toast.error(formatApiError(e));
    } finally {
      setSaving(false);
    }
  };

  const openNew = () => {
    setNewName("");
    setNewDesc("");
    setCopyFrom("none");
    setNewOpen(true);
  };

  const create = async (e: FormEvent) => {
    e.preventDefault();
    if (!newName.trim()) return;
    setCreating(true);
    try {
      const src = roles.find((r) => r.id === copyFrom);
      const body: RoleInput = { name: newName.trim(), description: newDesc.trim(), permissions: src ? src.permissions : [] };
      const { data } = await api.post<RoleDef>("/roles", body);
      await loadRoles(data.id);
      setNewOpen(false);
      setMobileDetail(true);
      toast.success(`Role "${data.name}" created`);
    } catch (err) {
      toast.error(formatApiError(err));
    } finally {
      setCreating(false);
    }
  };

  const remove = async () => {
    if (!selected) return;
    setDeleting(true);
    setDelError("");
    try {
      await api.delete(`/roles/${selected.id}`);
      await loadRoles(null);
      setDelOpen(false);
      setMobileDetail(false);
      toast.success("Role deleted");
    } catch (e) {
      setDelError(formatApiError(e));
    } finally {
      setDeleting(false);
    }
  };

  const q = search.trim().toLowerCase();
  const visibleGroups = useMemo(
    () =>
      catalog
        .map((g) => ({
          ...g,
          items: q ? g.items.filter((i) => `${i.key} ${i.label} ${i.description ?? ""} ${g.label}`.toLowerCase().includes(q)) : g.items,
        }))
        .filter((g) => g.items.length > 0),
    [catalog, q]
  );
  const granted = fullAccess ? allKeys.length : allKeys.filter((k) => perms.has(k)).length;

  return (
    <div className="space-y-6" data-testid="roles-page">
      <div className="flex items-end justify-between flex-wrap gap-4">
        <div>
          <div className="label-caps">Administration</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Roles & Permissions</h1>
          <p className="text-sm text-muted-foreground mt-2">Control what each role can see and do. Changes apply to every user with the role.</p>
        </div>
        <Button onClick={openNew} style={{ background: "#0A0A0A" }} data-testid="role-new">
          <Plus size={16} /> New role
        </Button>
      </div>

      {loading ? (
        <div className="text-sm text-muted-foreground">Loading roles…</div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-[300px_minmax(0,1fr)] gap-4 items-start">
          {/* Role list */}
          <Card className={cn("rounded-lg overflow-hidden", mobileDetail && "hidden lg:block")} data-testid="role-list">
            <div className="divide-y divide-border">
              {roles.map((r) => (
                <button
                  key={r.id}
                  onClick={() => selectRole(r.id)}
                  data-testid={`role-item-${r.slug}`}
                  className={cn(
                    "w-full text-left px-4 py-3 transition-colors hover:bg-neutral-50",
                    r.id === selectedId && "bg-neutral-100 shadow-[inset_3px_0_0_#0A0A0A]"
                  )}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold text-sm truncate">{r.name}</span>
                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground shrink-0">
                      <UsersIcon size={12} /> {r.user_count}
                    </span>
                  </div>
                  {r.description && <div className="text-xs text-muted-foreground mt-0.5 line-clamp-2">{r.description}</div>}
                  <div className="mt-1.5">
                    <RoleBadges role={r} />
                  </div>
                </button>
              ))}
              {roles.length === 0 && <div className="p-4 text-sm text-muted-foreground">No roles yet.</div>}
            </div>
          </Card>

          {/* Role detail */}
          {selected ? (
            <div className={cn("space-y-4 min-w-0", !mobileDetail && "hidden lg:block")} data-testid="role-detail">
              <button
                onClick={() => {
                  if (!dirty || window.confirm("Discard unsaved changes?")) {
                    discard();
                    setMobileDetail(false);
                  }
                }}
                className="lg:hidden inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                data-testid="role-back"
              >
                <ArrowLeft size={14} /> All roles
              </button>

              <Card className="p-5 rounded-lg">
                <div className="flex items-start justify-between gap-3 flex-wrap">
                  <div className="flex items-center gap-2 flex-wrap">
                    <h2 className="font-display text-xl font-bold">{selected.name}</h2>
                    <RoleBadges role={selected} />
                    <span className="text-xs text-muted-foreground font-mono">{selected.slug}</span>
                  </div>
                  {!selected.is_system && (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setDelError("");
                        setDelOpen(true);
                      }}
                      data-testid="role-delete"
                    >
                      <Trash2 size={14} className="text-destructive" /> Delete role
                    </Button>
                  )}
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-4">
                  <div>
                    <Label className="label-caps">Name</Label>
                    <Input
                      className="mt-1.5"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      disabled={selected.is_system}
                      maxLength={60}
                      data-testid="role-name"
                    />
                    {selected.is_system && (
                      <div className="text-[11px] text-muted-foreground mt-1 flex items-center gap-1">
                        <Lock size={10} /> System role names are locked.
                      </div>
                    )}
                  </div>
                  <div>
                    <Label className="label-caps">Description</Label>
                    <Input
                      className="mt-1.5"
                      value={description}
                      onChange={(e) => setDescription(e.target.value)}
                      disabled={selected.is_system}
                      data-testid="role-description"
                    />
                  </div>
                </div>
              </Card>

              {fullAccess && (
                <div className="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4" data-testid="role-full-access">
                  <ShieldCheck size={18} className="text-emerald-700 mt-0.5 shrink-0" />
                  <div className="text-sm text-emerald-900">
                    <div className="font-semibold">Full access</div>
                    <div className="text-emerald-800">This role always has every permission, including any added in future. It cannot be edited.</div>
                  </div>
                </div>
              )}

              <Card className="rounded-lg overflow-hidden">
                <div className="p-4 border-b border-border flex items-center gap-3 flex-wrap">
                  <div className="relative flex-1 min-w-[200px]">
                    <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      className="pl-9 h-9"
                      placeholder="Filter permissions…"
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      data-testid="perm-search"
                    />
                  </div>
                  <div className="text-sm text-muted-foreground" data-testid="perm-count">
                    <b className="text-foreground">{granted}</b> of {allKeys.length} permissions granted
                  </div>
                </div>

                <div className="divide-y divide-border" data-testid="perm-matrix">
                  {visibleGroups.length === 0 && (
                    <div className="p-6 text-sm text-muted-foreground text-center">No permissions match &ldquo;{search}&rdquo;.</div>
                  )}
                  {visibleGroups.map((g) => {
                    const full = catalog.find((c) => c.key === g.key) ?? g;
                    const count = full.items.filter((i) => fullAccess || perms.has(i.key)).length;
                    const state: boolean | "indeterminate" = count === 0 ? false : count === full.items.length ? true : "indeterminate";
                    return (
                      <section key={g.key} data-testid={`perm-group-${g.key}`}>
                        <div className="flex items-center gap-3 px-4 py-2.5 bg-secondary/50">
                          <Checkbox
                            checked={state}
                            disabled={fullAccess}
                            onCheckedChange={(v) => toggleGroup(full, v === true)}
                            aria-label={`Select all ${g.label}`}
                            data-testid={`perm-group-all-${g.key}`}
                          />
                          <span className="font-semibold text-sm">{g.label}</span>
                          <span className="text-xs text-muted-foreground ml-auto">
                            {count}/{full.items.length}
                          </span>
                        </div>
                        <ul>
                          {g.items.map((i) => (
                            <li key={i.key}>
                              <label className={cn("flex items-start gap-3 px-4 py-2.5 hover:bg-neutral-50", fullAccess ? "cursor-default" : "cursor-pointer")}>
                                <Checkbox
                                  className="mt-0.5"
                                  checked={fullAccess || perms.has(i.key)}
                                  disabled={fullAccess}
                                  onCheckedChange={(v) => toggle(i.key, v === true)}
                                  data-testid={`perm-${i.key}`}
                                />
                                <span className="min-w-0">
                                  <span className="block text-sm font-medium">{i.label}</span>
                                  {i.description && <span className="block text-xs text-muted-foreground">{i.description}</span>}
                                  <span className="block text-[10px] font-mono text-neutral-400 mt-0.5">{i.key}</span>
                                </span>
                              </label>
                            </li>
                          ))}
                        </ul>
                      </section>
                    );
                  })}
                </div>
              </Card>

              <Card className="p-5 rounded-lg" data-testid="role-users">
                <div className="flex items-center justify-between gap-2 mb-3">
                  <h3 className="font-display text-lg font-bold">
                    Users with this role <span className="text-muted-foreground font-normal text-sm">({roleUsers.length})</span>
                  </h3>
                  <Link href="/users" className="text-sm font-medium underline underline-offset-2 hover:text-neutral-600" data-testid="role-users-link">
                    Manage users
                  </Link>
                </div>
                {roleUsers.length === 0 ? (
                  <div className="text-sm text-muted-foreground">No users are assigned to this role.</div>
                ) : (
                  <ul className="divide-y divide-border">
                    {roleUsers.map((u) => (
                      <li key={u.id} className="py-2 flex items-center justify-between gap-3 text-sm">
                        <span className="min-w-0">
                          <span className="font-medium">{u.name || u.email}</span>
                          <span className="text-muted-foreground text-xs ml-2 break-all">{u.email}</span>
                        </span>
                        {u.active === false && <span className={cn(BADGE, "bg-neutral-100 text-neutral-500")}>Inactive</span>}
                      </li>
                    ))}
                  </ul>
                )}
              </Card>

              {dirty && (
                <div className="sticky bottom-3 z-20" data-testid="role-unsaved-bar">
                  <div className="flex items-center justify-between gap-3 flex-wrap rounded-lg bg-neutral-900 text-white px-4 py-3 shadow-lg">
                    <span className="text-sm">You have unsaved changes</span>
                    <span className="flex gap-2">
                      <Button
                        size="sm"
                        variant="outline"
                        className="bg-transparent text-white border-white/30 hover:bg-white/10 hover:text-white"
                        onClick={discard}
                        disabled={saving}
                        data-testid="role-discard"
                      >
                        Discard
                      </Button>
                      <Button size="sm" className="bg-white text-neutral-900 hover:bg-neutral-100" onClick={save} disabled={saving} data-testid="role-save">
                        {saving ? "Saving…" : "Save changes"}
                      </Button>
                    </span>
                  </div>
                </div>
              )}
            </div>
          ) : (
            <Card className="p-8 rounded-lg text-sm text-muted-foreground">Select a role to view its permissions.</Card>
          )}
        </div>
      )}

      {/* New role */}
      <Dialog open={newOpen} onOpenChange={setNewOpen}>
        <DialogContent data-testid="role-new-dialog">
          <form onSubmit={create} className="space-y-4">
            <DialogHeader>
              <DialogTitle>New role</DialogTitle>
              <DialogDescription>Create a custom role, then fine-tune its permissions.</DialogDescription>
            </DialogHeader>
            <div>
              <Label className="label-caps">Name</Label>
              <Input className="mt-1.5" value={newName} onChange={(e) => setNewName(e.target.value)} required maxLength={60} autoFocus data-testid="role-new-name" />
            </div>
            <div>
              <Label className="label-caps">Description</Label>
              <Textarea className="mt-1.5" rows={2} value={newDesc} onChange={(e) => setNewDesc(e.target.value)} data-testid="role-new-description" />
            </div>
            <div>
              <Label className="label-caps">Copy permissions from</Label>
              <Select value={copyFrom} onValueChange={setCopyFrom}>
                <SelectTrigger className="mt-1.5" data-testid="role-new-copy">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Start with no permissions</SelectItem>
                  {roles.map((r) => (
                    <SelectItem key={r.id} value={r.id}>
                      {r.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setNewOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" style={{ background: "#0A0A0A" }} disabled={creating || !newName.trim()} data-testid="role-new-submit">
                {creating ? "Creating…" : "Create role"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete role */}
      <Dialog open={delOpen} onOpenChange={setDelOpen}>
        <DialogContent data-testid="role-delete-dialog">
          <DialogHeader>
            <DialogTitle>Delete role &ldquo;{selected?.name}&rdquo;?</DialogTitle>
            <DialogDescription>This cannot be undone. Roles with assigned users cannot be deleted.</DialogDescription>
          </DialogHeader>
          {delError && (
            <div className="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" data-testid="role-delete-error">
              {delError}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setDelOpen(false)}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={remove} disabled={deleting} data-testid="role-delete-confirm">
              {deleting ? "Deleting…" : "Delete"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
