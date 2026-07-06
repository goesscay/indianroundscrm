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
  const [f, setF] = useState({ email: "", password: "", name: "", role: "sales", staff_code: "" });

  const load = async () => {
    try { const { data } = await api.get("/users"); setUsers(data); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  useEffect(() => { load(); }, []);

  const create = async (e) => {
    e.preventDefault();
    try {
      await api.post("/users", f);
      toast.success("User created");
      setF({ email: "", password: "", name: "", role: "sales", staff_code: "" });
      load();
    } catch (e) { toast.error(formatApiError(e)); }
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
        <form onSubmit={create} className="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
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
          <div className="flex gap-2">
            <Input placeholder="Staff Code" value={f.staff_code} onChange={e => setF({ ...f, staff_code: e.target.value })} data-testid="u-code" />
            <Button type="submit" style={{ background: "#106EBE" }} data-testid="u-add">Add</Button>
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
    </div>
  );
}
