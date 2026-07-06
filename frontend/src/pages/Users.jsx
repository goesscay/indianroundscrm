import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { toast } from "sonner";
import { useAuth } from "@/context/AuthContext";
import { Trash2 } from "lucide-react";

export default function Users() {
  const { user } = useAuth();
  const [users, setUsers] = useState([]);
  const [f, setF] = useState({ email: "", password: "", name: "", role: "sales", staff_code: "", consultant_code: "" });
  const [allocations, setAllocations] = useState([]);
  const [allocForm, setAllocForm] = useState({ destination: "", consultant_id: "" });
  const [destinations, setDestinations] = useState([]);

  const load = async () => {
    try {
      const { data } = await api.get("/users"); setUsers(data);
      const a = await api.get("/allocations"); setAllocations(a.data);
      const m = await api.get("/master?category=destination"); setDestinations(m.data.map(x => x.value));
    }
    catch (e) { toast.error(formatApiError(e)); }
  };
  useEffect(() => { load(); }, []);

  const create = async (e) => {
    e.preventDefault();
    try {
      await api.post("/users", f);
      toast.success("User created");
      setF({ email: "", password: "", name: "", role: "sales", staff_code: "", consultant_code: "" });
      load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const setAllocation = async (e) => {
    e.preventDefault();
    if (!allocForm.destination || !allocForm.consultant_id) return;
    try {
      await api.post("/allocations", allocForm);
      toast.success("Allocation saved");
      setAllocForm({ destination: "", consultant_id: "" });
      load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const removeAllocation = async (id) => {
    try { await api.delete(`/allocations/${id}`); toast.success("Removed"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const remove = async (uid) => {
    if (!window.confirm("Delete this user?")) return;
    try { await api.delete(`/users/${uid}`); toast.success("Deleted"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <div className="space-y-6" data-testid="users-page">
      <div>
        <div className="label-caps">Team</div>
        <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Users</h1>
      </div>

      <Card className="p-6 rounded-lg">
        <h3 className="font-display text-xl font-bold mb-4">Add new user</h3>
        <form onSubmit={create} className="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
          <div><Label className="label-caps">Name</Label><Input className="mt-1.5" value={f.name} onChange={e => setF({ ...f, name: e.target.value })} required data-testid="u-name" /></div>
          <div><Label className="label-caps">Email</Label><Input type="email" className="mt-1.5" value={f.email} onChange={e => setF({ ...f, email: e.target.value })} required data-testid="u-email" /></div>
          <div><Label className="label-caps">Password</Label><Input type="password" className="mt-1.5" value={f.password} onChange={e => setF({ ...f, password: e.target.value })} required data-testid="u-password" /></div>
          <div><Label className="label-caps">Role</Label>
            <Select value={f.role} onValueChange={(v) => setF({ ...f, role: v })}>
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
            <div className="text-[10px] text-muted-foreground mt-1">Used in Lead ID (IR<b>KA</b>07001)</div>
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
                <TableHead>Staff Code</TableHead>
                <TableHead>Consultant Code</TableHead>
                <TableHead>Created</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {users.map(u => (
                <TableRow key={u.id} data-testid={`user-row-${u.id}`}>
                  <TableCell className="font-medium">{u.name}</TableCell>
                  <TableCell>{u.email}</TableCell>
                  <TableCell><span className="label-caps px-2 py-1 rounded bg-secondary">{u.role}</span></TableCell>
                  <TableCell className="font-mono text-xs">{u.staff_code || "—"}</TableCell>
                  <TableCell className="font-mono font-bold text-[#106EBE]">{u.consultant_code || "—"}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{(u.created_at || "").slice(0, 10)}</TableCell>
                  <TableCell className="text-right">
                    {u.id !== user.id && (
                      <Button size="sm" variant="ghost" onClick={() => remove(u.id)} data-testid={`u-del-${u.id}`}>
                        <Trash2 size={14} className="text-destructive" />
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </Card>

      <Card className="p-6 rounded-lg" data-testid="allocations-section">
        <h3 className="font-display text-xl font-bold mb-1">Lead Allocation by Destination</h3>
        <p className="text-xs text-muted-foreground mb-4">When a customer picks a destination, the lead auto-assigns to the mapped consultant + uses their code in the Lead ID (e.g. IRKA07001 for Karuva).</p>
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
          {allocations.length === 0 && <div className="text-sm text-muted-foreground">No allocations yet. Add destination → consultant mappings above.</div>}
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
