"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import PlacesField from "@/components/app/PlacesField";
import { myDestinations } from "@/lib/places";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Pagination, usePagination } from "@/components/ui/pagination";
import { toast } from "sonner";
import { useCurrentUser } from "@/context/AuthContext";
import type { DestinationAllocation, MasterItem, RoleDef, User, UserCreate } from "@/types";
import { Trash2, KeyRound, Unlock as UnlockIcon, Shield, LogOut, UserPlus, Pencil } from "lucide-react";

const STATUS_STYLES: Record<string, string> = {
  active: "bg-emerald-100 text-emerald-800 dark:text-emerald-300 dark:bg-emerald-950/60 dark:text-emerald-300",
  inactive: "bg-gray-100 text-gray-700 dark:bg-neutral-800 dark:text-neutral-300",
  suspended: "bg-red-100 text-red-800 dark:text-red-300 dark:bg-red-950/60 dark:text-red-300",
  locked: "bg-amber-100 text-amber-800 dark:text-amber-300 dark:bg-amber-950/60 dark:text-amber-300",
  password_reset_required: "bg-amber-100 text-amber-800 dark:text-amber-300 dark:bg-amber-950/60 dark:text-amber-300",
};

export default function Users() {
  const { user, can } = useCurrentUser();
  // only a Super Admin can hand out (or change) the Super Admin role
  const roleChoices = (current?: string) => roles.filter((r) => r.slug !== "super_admin" || user.role === "super_admin" || r.slug === current);
  const canManage = can("users.manage");
  const canAlloc = can("allocations.manage");
  const [roles, setRoles] = useState<RoleDef[]>([]);
  const [roleFilter, setRoleFilter] = useState("all");
  const [users, setUsers] = useState<User[]>([]);
  const EMPTY_USER: UserCreate = { email: "", password: "", name: "", role: "sales", staff_code: "", consultant_code: "", destinations: [] };
  const [f, setF] = useState<UserCreate>(EMPTY_USER);
  const [createOpen, setCreateOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState<User | null>(null);
  const [ef, setEf] = useState({ name: "", email: "", mobile: "", role: "sales", staff_code: "", consultant_code: "", destinations: [] as string[], password: "" });
  const [esaving, setEsaving] = useState(false);
  const [allocations, setAllocations] = useState<DestinationAllocation[]>([]);
  const [allocForm, setAllocForm] = useState({ destination: "", consultant_id: "" });
  const [destinations, setDestinations] = useState<string[]>([]);

  const load = async () => {
    try {
      const { data } = await api.get<User[]>("/users"); setUsers(data);
      try { const rl = await api.get<RoleDef[]>("/roles"); setRoles(rl.data); } catch { /* roles list is optional for read-only viewers */ }
      try {
        const a = await api.get<DestinationAllocation[]>("/allocations"); setAllocations(a.data);
        const m = await api.get<MasterItem[]>("/master?category=destination"); setDestinations(m.data.map(x => x.value));
      } catch { /* allocations need extra permissions */ }
    }
    catch (e) { toast.error(formatApiError(e)); }
  };
  useEffect(() => { load(); }, []);

  const openEdit = (u: User) => {
    setEf({ name: u.name || "", email: u.email || "", mobile: (u.mobile as string) || "", role: u.role, staff_code: u.staff_code || "", consultant_code: u.consultant_code || "", destinations: myDestinations(u), password: "" });
    setEditing(u);
  };

  const saveEdit = async (e: FormEvent) => {
    e.preventDefault();
    if (!editing) return;
    if (!ef.name.trim() || !ef.email.trim()) { toast.error("Name and e-mail are required"); return; }
    setEsaving(true);
    try {
      const isSelf = editing.id === user.id;
      const body: Record<string, unknown> = { name: ef.name.trim(), email: ef.email.trim(), mobile: ef.mobile.trim(), staff_code: ef.staff_code.trim(), consultant_code: ef.consultant_code.trim(), destinations: ef.destinations };
      if (!isSelf && ef.role !== editing.role) body.role = ef.role;
      if (ef.password) body.password = ef.password;
      await api.patch(`/users/${editing.id}`, body);
      toast.success(ef.password ? "User updated. Their other sessions were signed out." : "User updated");
      setEditing(null);
      load();
    } catch (err) { toast.error(formatApiError(err)); }
    finally { setEsaving(false); }
  };

  const create = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      await api.post("/users", f);
      toast.success("User created");
      setF(EMPTY_USER);
      setCreateOpen(false);
      load();
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setSaving(false); }
  };

  const forceReset = async (u: User) => {
    if (!window.confirm(`Force ${u.name || u.email} to reset password on next login? All their active sessions will be invalidated.`)) return;
    try { await api.post(`/users/${u.id}/force-reset`, { reason: "Admin initiated" }); toast.success("Force reset applied"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const unlock = async (u: User) => {
    try { await api.post(`/users/${u.id}/unlock`); toast.success("Unlocked"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const setStatus = async (u: User, account_status: string) => {
    if (account_status !== "active" && !window.confirm(`Set ${u.name || u.email} status to '${account_status}'? Sessions will be invalidated.`)) return;
    try { await api.post(`/users/${u.id}/status`, { account_status }); toast.success(`Status → ${account_status}`); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const logoutUser = async (u: User) => {
    if (!window.confirm(`Log out ${u.name || u.email} from all devices?`)) return;
    try { await api.post(`/users/${u.id}/logout`); toast.success("All sessions invalidated"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const setAllocation = async (e: FormEvent) => {
    e.preventDefault();
    if (!allocForm.destination || !allocForm.consultant_id) return;
    try { await api.post("/allocations", allocForm); toast.success("Allocation saved"); setAllocForm({ destination: "", consultant_id: "" }); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  const removeAllocation = async (id: string) => {
    try { await api.delete(`/allocations/${id}`); toast.success("Removed"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  const remove = async (uid: string) => {
    if (!window.confirm("Delete this user? Historical leads/activity are preserved.")) return;
    try { await api.delete(`/users/${uid}`); toast.success("Deleted"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const shownUsers = roleFilter === "all" ? users : users.filter(u => u.role === roleFilter);
  const pg = usePagination(shownUsers, { resetKey: roleFilter });

  return (
    <div className="space-y-6" data-testid="users-page">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <div className="label-caps">Team</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Users & Security</h1>
        </div>
        {canManage && (
          <Button onClick={() => setCreateOpen(true)} data-testid="u-create-btn">
            <UserPlus size={15} /> Create user
          </Button>
        )}
      </div>

      {canManage && (
        <Dialog open={createOpen} onOpenChange={(o) => { setCreateOpen(o); if (!o) setF(EMPTY_USER); }}>
          <DialogContent className="sm:max-w-lg" data-testid="create-user-dialog">
            <DialogHeader>
              <DialogTitle className="font-display">Create user</DialogTitle>
              <DialogDescription>Add a team member and choose what they can access.</DialogDescription>
            </DialogHeader>
            <form onSubmit={create} className="space-y-4" data-testid="create-user-form">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <Label className="label-caps">Full name</Label>
                  <Input className="mt-1.5" value={f.name} onChange={e => setF({ ...f, name: e.target.value })} required autoFocus data-testid="u-name" />
                </div>
                <div>
                  <Label className="label-caps">Email</Label>
                  <Input type="email" className="mt-1.5" value={f.email} onChange={e => setF({ ...f, email: e.target.value })} required data-testid="u-email" />
                </div>
                <div>
                  <Label className="label-caps">Password</Label>
                  <Input type="password" className="mt-1.5" autoComplete="new-password" value={f.password} onChange={e => setF({ ...f, password: e.target.value })} required data-testid="u-password" placeholder="Min 8, Aa1@" />
                </div>
                <div>
                  <Label className="label-caps">Role</Label>
                  <Select value={f.role} onValueChange={(v) => setF({ ...f, role: v })}>
                    <SelectTrigger className="mt-1.5" data-testid="u-role"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {roleChoices().map(r => <SelectItem key={r.slug} value={r.slug}>{r.name}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label className="label-caps">Staff code</Label>
                  <Input className="mt-1.5" placeholder="SAL001" value={f.staff_code} onChange={e => setF({ ...f, staff_code: e.target.value })} data-testid="u-code" />
                </div>
                <div>
                  <Label className="label-caps">Consultant code</Label>
                  <Input className="mt-1.5" placeholder="KA" maxLength={2} value={f.consultant_code} onChange={e => setF({ ...f, consultant_code: e.target.value.toUpperCase() })} data-testid="u-consultant" />
                  <div className="text-[10px] text-muted-foreground mt-1">2 letters · used in the Lead ID</div>
                </div>
                <div className="sm:col-span-2">
                  <Label className="label-caps">Destinations</Label>
                  <div className="mt-1.5"><PlacesField options={destinations} value={f.destinations ?? []} onChange={(v) => setF({ ...f, destinations: v })} testId="u-destinations" /></div>
                  <div className="text-[10px] text-muted-foreground mt-1">The places they work. The first (starred) one is pre-filled on their new leads, quotes and deals, and all of them show in their Leads list. They can change it at any time.</div>
                </div>
              </div>
              <div className="text-xs text-muted-foreground">
                Password needs 8+ characters with an uppercase letter, a lowercase letter, a number and a special character.
              </div>
              <DialogFooter className="gap-2 sm:gap-2">
                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)} data-testid="u-cancel">Cancel</Button>
                <Button type="submit" disabled={saving} data-testid="u-add">{saving ? "Creating…" : "Create user"}</Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      )}

      {canManage && (
        <Dialog open={!!editing} onOpenChange={(o) => { if (!o && !esaving) setEditing(null); }}>
          <DialogContent className="sm:max-w-lg" data-testid="edit-user-dialog">
            <DialogHeader>
              <DialogTitle className="font-display">Edit user</DialogTitle>
              <DialogDescription>{editing?.id === user.id ? "You are editing your own account. Your role cannot be changed here." : "Change this team member's details, role or password."}</DialogDescription>
            </DialogHeader>
            <form onSubmit={saveEdit} className="space-y-4" data-testid="edit-user-form">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div><Label className="label-caps">Full name</Label><Input className="mt-1.5" value={ef.name} onChange={(e) => setEf({ ...ef, name: e.target.value })} required autoFocus data-testid="eu-name" /></div>
                <div><Label className="label-caps">Email</Label><Input type="email" className="mt-1.5" value={ef.email} onChange={(e) => setEf({ ...ef, email: e.target.value })} required data-testid="eu-email" /></div>
                <div><Label className="label-caps">Mobile</Label><Input className="mt-1.5" value={ef.mobile} onChange={(e) => setEf({ ...ef, mobile: e.target.value })} inputMode="tel" data-testid="eu-mobile" /></div>
                <div>
                  <Label className="label-caps">Role</Label>
                  <Select value={ef.role} onValueChange={(v) => setEf({ ...ef, role: v })} disabled={editing?.id === user.id}>
                    <SelectTrigger className="mt-1.5" data-testid="eu-role"><SelectValue /></SelectTrigger>
                    <SelectContent>{roleChoices(ef.role).map((r) => <SelectItem key={r.slug} value={r.slug}>{r.name}</SelectItem>)}</SelectContent>
                  </Select>
                </div>
                <div><Label className="label-caps">Staff code</Label><Input className="mt-1.5" value={ef.staff_code} onChange={(e) => setEf({ ...ef, staff_code: e.target.value })} data-testid="eu-code" /></div>
                <div>
                  <Label className="label-caps">Consultant code</Label>
                  <Input className="mt-1.5" maxLength={2} value={ef.consultant_code} onChange={(e) => setEf({ ...ef, consultant_code: e.target.value.toUpperCase() })} data-testid="eu-consultant" />
                  <div className="text-[10px] text-muted-foreground mt-1">2 letters · used in new Lead IDs</div>
                </div>
                <div className="sm:col-span-2">
                  <Label className="label-caps">Destinations</Label>
                  <div className="mt-1.5"><PlacesField options={destinations} value={ef.destinations} onChange={(v) => setEf({ ...ef, destinations: v })} testId="eu-destinations" /></div>
                  <div className="text-[10px] text-muted-foreground mt-1">The places they work. The first (starred) one is pre-filled on their new leads, quotes and deals, and all of them show in their Leads list. They can change it at any time.</div>
                </div>
                <div className="sm:col-span-2">
                  <Label className="label-caps">New password</Label>
                  <Input type="password" className="mt-1.5" autoComplete="new-password" value={ef.password} onChange={(e) => setEf({ ...ef, password: e.target.value })} placeholder="Leave empty to keep the current password" data-testid="eu-password" />
                  <div className="text-[10px] text-muted-foreground mt-1">Setting a password signs the user out everywhere. Min 8 characters with upper, lower, number and a special character.</div>
                </div>
              </div>
              <DialogFooter className="gap-2 sm:gap-2">
                <Button type="button" variant="outline" onClick={() => setEditing(null)} disabled={esaving} data-testid="eu-cancel">Cancel</Button>
                <Button type="submit" disabled={esaving} data-testid="eu-save">{esaving ? "Saving…" : "Save changes"}</Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      )}

      <Card className="rounded-lg overflow-hidden">
        <div className="px-4 py-3 border-b border-border flex items-center gap-3 flex-wrap">
          <Label className="label-caps">Filter by role</Label>
          <Select value={roleFilter} onValueChange={setRoleFilter}>
            <SelectTrigger className="h-8 w-[200px] text-xs" data-testid="u-role-filter"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All roles</SelectItem>
              {roles.map(r => <SelectItem key={r.slug} value={r.slug}>{r.name}</SelectItem>)}
            </SelectContent>
          </Select>
          <span className="text-xs text-muted-foreground">{shownUsers.length} user{shownUsers.length === 1 ? "" : "s"}</span>
        </div>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Destinations</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Last Login</TableHead>
                <TableHead>Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {pg.pageItems.map(u => {
                const status = u.account_status || "active";
                const isSelf = u.id === user.id;
                return (
                  <TableRow key={u.id} data-testid={`user-row-${u.id}`}>
                    <TableCell className="font-medium">{u.name || "—"}</TableCell>
                    <TableCell className="text-xs">{u.email}</TableCell>
                    <TableCell><span className="label-caps px-2 py-1 rounded bg-secondary whitespace-nowrap" data-testid={`u-role-badge-${u.id}`}>{u.role_name || u.role}</span></TableCell>
                    <TableCell className="text-xs" data-testid={`u-destination-${u.id}`}>{myDestinations(u).length ? myDestinations(u).join(", ") : <span className="text-muted-foreground">—</span>}</TableCell>
                    <TableCell>
                      <Select value={status} onValueChange={(v) => setStatus(u, v)} disabled={isSelf || !canManage}>
                        <SelectTrigger className={`h-7 w-[140px] text-xs font-semibold ${STATUS_STYLES[status] || ""}`} data-testid={`u-status-${u.id}`}>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="active">Active</SelectItem>
                          <SelectItem value="inactive">Inactive</SelectItem>
                          <SelectItem value="suspended">Suspended</SelectItem>
                          {status === "locked" && <SelectItem value="locked">Locked</SelectItem>}
                          {status === "password_reset_required" && <SelectItem value="password_reset_required">Reset Required</SelectItem>}
                        </SelectContent>
                      </Select>
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">{(u.last_login_at || "").slice(0, 16).replace("T", " ") || "—"}</TableCell>
                    <TableCell>
                      {canManage && <div className="flex items-center gap-1">
                        <Button size="sm" variant="ghost" onClick={() => openEdit(u)} title="Edit user" data-testid={`u-edit-${u.id}`}>
                          <Pencil size={14} />
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => forceReset(u)} title="Force password reset on next login" data-testid={`u-forcereset-${u.id}`}>
                          <KeyRound size={14} />
                        </Button>
                        {status === "locked" && (
                          <Button size="sm" variant="ghost" onClick={() => unlock(u)} title="Unlock account" data-testid={`u-unlock-${u.id}`}>
                            <UnlockIcon size={14} className="text-amber-600 dark:text-amber-400" />
                          </Button>
                        )}
                        {!isSelf && (
                          <Button size="sm" variant="ghost" onClick={() => logoutUser(u)} title="Terminate all sessions" data-testid={`u-logout-${u.id}`}>
                            <LogOut size={14} />
                          </Button>
                        )}
                        {!isSelf && (
                          <Button size="sm" variant="ghost" onClick={() => remove(u.id)} title="Delete user" data-testid={`u-del-${u.id}`}>
                            <Trash2 size={14} className="text-destructive" />
                          </Button>
                        )}
                      </div>}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
        <Pagination {...pg.paginationProps} />
        <div className="px-4 py-3 border-t border-border bg-secondary/30 flex items-center gap-2 text-xs text-muted-foreground">
          <Shield size={12} />
          Key: <Pencil size={11} className="ml-1" /> Edit · <KeyRound size={11} /> Force reset · <UnlockIcon size={11} /> Unlock · <LogOut size={11} /> Kick sessions · <Trash2 size={11} /> Delete
        </div>
      </Card>

      {canAlloc && <Card className="p-6 rounded-lg" data-testid="allocations-section">
        <h3 className="font-display text-xl font-bold mb-1">Lead Allocation by Destination</h3>
        <p className="text-xs text-muted-foreground mb-4">When a customer picks a destination, the lead auto-assigns to the mapped consultant + uses their code in the Lead ID.</p>
        <form onSubmit={setAllocation} className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end mb-6">
          <div>
            <Label className="label-caps">Destination</Label>
            <Select value={allocForm.destination || undefined} onValueChange={(v) => setAllocForm({ ...allocForm, destination: v })}>
              <SelectTrigger className="mt-1.5" data-testid="a-destination"><SelectValue placeholder="Choose destination" /></SelectTrigger>
              <SelectContent>{destinations.map(d => <SelectItem key={d} value={d}>{d}</SelectItem>)}</SelectContent>
            </Select>
          </div>
          <div>
            <Label className="label-caps">Assign to consultant</Label>
            <Select value={allocForm.consultant_id || undefined} onValueChange={(v) => setAllocForm({ ...allocForm, consultant_id: v })}>
              <SelectTrigger className="mt-1.5" data-testid="a-consultant"><SelectValue placeholder="Choose sales consultant" /></SelectTrigger>
              <SelectContent>
                {users.filter(u => u.active !== false && (!!u.consultant_code || u.role === "sales" || u.role === "admin" || u.role === "super_admin")).map(u =>
                  <SelectItem key={u.id} value={u.id}>{u.name} · {u.consultant_code || "??"}</SelectItem>
                )}
              </SelectContent>
            </Select>
          </div>
          <Button type="submit" data-testid="a-save">Set allocation</Button>
        </form>
        <div className="flex flex-wrap gap-2">
          {allocations.length === 0 && <div className="text-sm text-muted-foreground">No allocations yet.</div>}
          {allocations.map(a => (
            <span key={a.id} className="inline-flex items-center gap-2 px-3 py-2 rounded-md border border-border bg-secondary/40" data-testid={`alloc-${a.destination}`}>
              <span className="font-semibold text-sm">{a.destination}</span>
              <span className="text-muted-foreground">→</span>
              <span className="font-medium text-sm">{a.consultant_name}</span>
              <span className="font-mono font-bold text-[#0A0A0A] dark:text-foreground text-xs">{a.consultant_code}</span>
              <button onClick={() => removeAllocation(a.id)} className="text-muted-foreground hover:text-destructive ml-1"><Trash2 size={12} /></button>
            </span>
          ))}
        </div>
      </Card>}
    </div>
  );
}
