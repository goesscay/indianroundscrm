"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { toast } from "sonner";
import { useCurrentUser } from "@/context/AuthContext";
import type { DestinationAllocation, MasterItem, Role, User, UserCreate } from "@/types";
import { Trash2, KeyRound, Unlock as UnlockIcon, Shield, LogOut } from "lucide-react";

const STATUS_STYLES: Record<string, string> = {
  active: "bg-emerald-100 text-emerald-800",
  inactive: "bg-gray-100 text-gray-700",
  suspended: "bg-red-100 text-red-800",
  locked: "bg-amber-100 text-amber-800",
  password_reset_required: "bg-amber-100 text-amber-800",
};

export default function Users() {
  const { user } = useCurrentUser();
  const [users, setUsers] = useState<User[]>([]);
  const [f, setF] = useState<UserCreate>({ email: "", password: "", name: "", role: "sales", staff_code: "", consultant_code: "" });
  const [allocations, setAllocations] = useState<DestinationAllocation[]>([]);
  const [allocForm, setAllocForm] = useState({ destination: "", consultant_id: "" });
  const [destinations, setDestinations] = useState<string[]>([]);

  const load = async () => {
    try {
      const { data } = await api.get<User[]>("/users"); setUsers(data);
      const a = await api.get<DestinationAllocation[]>("/allocations"); setAllocations(a.data);
      const m = await api.get<MasterItem[]>("/master?category=destination"); setDestinations(m.data.map(x => x.value));
    }
    catch (e) { toast.error(formatApiError(e)); }
  };
  useEffect(() => { load(); }, []);

  const create = async (e: FormEvent) => {
    e.preventDefault();
    try {
      await api.post("/users", f);
      toast.success("User created");
      setF({ email: "", password: "", name: "", role: "sales", staff_code: "", consultant_code: "" });
      load();
    } catch (e) { toast.error(formatApiError(e)); }
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

  return (
    <div className="space-y-6" data-testid="users-page">
      <div>
        <div className="label-caps">Team</div>
        <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Users & Security</h1>
      </div>

      <Card className="p-6 rounded-lg">
        <h3 className="font-display text-xl font-bold mb-4">Add new user</h3>
        <form onSubmit={create} className="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
          <div><Label className="label-caps">Name</Label><Input className="mt-1.5" value={f.name} onChange={e => setF({ ...f, name: e.target.value })} required data-testid="u-name" /></div>
          <div><Label className="label-caps">Email</Label><Input type="email" className="mt-1.5" value={f.email} onChange={e => setF({ ...f, email: e.target.value })} required data-testid="u-email" /></div>
          <div><Label className="label-caps">Password</Label><Input type="password" className="mt-1.5" value={f.password} onChange={e => setF({ ...f, password: e.target.value })} required data-testid="u-password" placeholder="Min 8, Aa1@" /></div>
          <div><Label className="label-caps">Role</Label>
            <Select value={f.role} onValueChange={(v) => setF({ ...f, role: v as Role })}>
              <SelectTrigger className="mt-1.5" data-testid="u-role"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="sales">Sales Executive</SelectItem>
                <SelectItem value="operations">Operations</SelectItem>
                <SelectItem value="admin">Admin</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div><Label className="label-caps">Staff Code</Label><Input className="mt-1.5" placeholder="SAL001" value={f.staff_code} onChange={e => setF({ ...f, staff_code: e.target.value })} data-testid="u-code" /></div>
          <div><Label className="label-caps">Consultant Code</Label>
            <div className="flex gap-2 mt-1.5">
              <Input placeholder="KA" maxLength={2} value={f.consultant_code} onChange={e => setF({ ...f, consultant_code: e.target.value.toUpperCase() })} data-testid="u-consultant" />
              <Button type="submit" style={{ background: "#106EBE" }} data-testid="u-add">Add</Button>
            </div>
            <div className="text-[10px] text-muted-foreground mt-1">Password must meet policy · Used in Lead ID</div>
          </div>
        </form>
      </Card>

      <Card className="rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Last Login</TableHead>
                <TableHead>Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {users.map(u => {
                const status = u.account_status || "active";
                const isSelf = u.id === user.id;
                return (
                  <TableRow key={u.id} data-testid={`user-row-${u.id}`}>
                    <TableCell className="font-medium">{u.name || "—"}</TableCell>
                    <TableCell className="text-xs">{u.email}</TableCell>
                    <TableCell><span className="label-caps px-2 py-1 rounded bg-secondary">{u.role}</span></TableCell>
                    <TableCell>
                      <Select value={status} onValueChange={(v) => setStatus(u, v)} disabled={isSelf}>
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
                      <div className="flex items-center gap-1">
                        <Button size="sm" variant="ghost" onClick={() => forceReset(u)} title="Force password reset on next login" data-testid={`u-forcereset-${u.id}`}>
                          <KeyRound size={14} />
                        </Button>
                        {status === "locked" && (
                          <Button size="sm" variant="ghost" onClick={() => unlock(u)} title="Unlock account" data-testid={`u-unlock-${u.id}`}>
                            <UnlockIcon size={14} className="text-amber-600" />
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
                      </div>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
        <div className="px-4 py-3 border-t border-border bg-secondary/30 flex items-center gap-2 text-xs text-muted-foreground">
          <Shield size={12} />
          Key: <KeyRound size={11} className="ml-1" /> Force reset · <UnlockIcon size={11} /> Unlock · <LogOut size={11} /> Kick sessions · <Trash2 size={11} /> Delete
        </div>
      </Card>

      <Card className="p-6 rounded-lg" data-testid="allocations-section">
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
                {users.filter(u => u.role === "sales" || u.role === "admin").map(u =>
                  <SelectItem key={u.id} value={u.id}>{u.name} · {u.consultant_code || "??"}</SelectItem>
                )}
              </SelectContent>
            </Select>
          </div>
          <Button type="submit" style={{ background: "#106EBE" }} data-testid="a-save">Set allocation</Button>
        </form>
        <div className="flex flex-wrap gap-2">
          {allocations.length === 0 && <div className="text-sm text-muted-foreground">No allocations yet.</div>}
          {allocations.map(a => (
            <span key={a.id} className="inline-flex items-center gap-2 px-3 py-2 rounded-md border border-border bg-secondary/40" data-testid={`alloc-${a.destination}`}>
              <span className="font-semibold text-sm">{a.destination}</span>
              <span className="text-muted-foreground">→</span>
              <span className="font-medium text-sm">{a.consultant_name}</span>
              <span className="font-mono font-bold text-[#106EBE] text-xs">{a.consultant_code}</span>
              <button onClick={() => removeAllocation(a.id)} className="text-muted-foreground hover:text-destructive ml-1"><Trash2 size={12} /></button>
            </span>
          ))}
        </div>
      </Card>
    </div>
  );
}
