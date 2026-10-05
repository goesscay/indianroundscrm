"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { toast } from "sonner";
import { Trash2 } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import type { Supplier, SupplierCreate } from "@/types";

export default function Suppliers() {
  const { can } = useCurrentUser();
  const canEdit = can("suppliers.manage");
  const [rows, setRows] = useState<Supplier[]>([]);
  const [f, setF] = useState<SupplierCreate>({ name: "", email: "", phone: "", destination: "", country: "" });

  const load = async () => {
    try { const { data } = await api.get<Supplier[]>("/suppliers"); setRows(data); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  useEffect(() => { load(); }, []);

  const create = async (e: FormEvent) => {
    e.preventDefault();
    try { await api.post("/suppliers", f); toast.success("Supplier added");
      setF({ name: "", email: "", phone: "", destination: "", country: "" }); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const remove = async (id: string) => {
    if (!window.confirm("Delete supplier?")) return;
    try { await api.delete(`/suppliers/${id}`); toast.success("Deleted"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <div className="space-y-6" data-testid="suppliers-page">
      <div>
        <div className="label-caps">Master data</div>
        <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Suppliers</h1>
      </div>

      {canEdit && (
        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Add supplier</h3>
          <form onSubmit={create} className="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            <div className="md:col-span-2"><Label className="label-caps">Name</Label><Input className="mt-1.5" value={f.name} onChange={e => setF({ ...f, name: e.target.value })} required data-testid="s-name" /></div>
            <div className="md:col-span-2"><Label className="label-caps">Email</Label><Input type="email" className="mt-1.5" value={f.email} onChange={e => setF({ ...f, email: e.target.value })} required data-testid="s-email" /></div>
            <div><Label className="label-caps">Phone</Label><Input className="mt-1.5" value={f.phone} onChange={e => setF({ ...f, phone: e.target.value })} data-testid="s-phone" /></div>
            <div><Label className="label-caps">Destination</Label><Input className="mt-1.5" value={f.destination} onChange={e => setF({ ...f, destination: e.target.value })} data-testid="s-destination" /></div>
            <div><Label className="label-caps">Country</Label><Input className="mt-1.5" value={f.country} onChange={e => setF({ ...f, country: e.target.value })} data-testid="s-country" /></div>
            <div className="md:col-span-6 flex justify-end">
              <Button type="submit" style={{ background: "#0A0A0A" }} data-testid="s-add">Add supplier</Button>
            </div>
          </form>
        </Card>
      )}

      <Card className="rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead><TableHead>Email</TableHead>
                <TableHead>Phone</TableHead><TableHead>Destination</TableHead>
                <TableHead>Country</TableHead><TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground py-8">No suppliers yet</TableCell></TableRow>}
              {rows.map(s => (
                <TableRow key={s.id}>
                  <TableCell className="font-medium">{s.name}</TableCell>
                  <TableCell>{s.email}</TableCell>
                  <TableCell>{s.phone}</TableCell>
                  <TableCell>{s.destination}</TableCell>
                  <TableCell>{s.country}</TableCell>
                  <TableCell className="text-right">
                    {canEdit && <Button size="sm" variant="ghost" onClick={() => remove(s.id)}><Trash2 size={14} className="text-destructive" /></Button>}
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
