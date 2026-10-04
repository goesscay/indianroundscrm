"use client";

import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { Mail, Phone, MessageCircle, FileText, Bell } from "lucide-react";
import { toast } from "sonner";
import type { LeadSupplier } from "@/types";

const SUPPLIER_STATUSES = [
  "Email Not Sent","Email Sent","Awaiting Reply","Follow-up Required","Quote Received",
  "Quote Rejected","Quote Accepted","Supplier Declined","No Response","Booking Confirmed",
];
const STATUS_COLOR: Record<string, string> = {
  "Email Not Sent":     "bg-slate-200 text-slate-700",
  "Email Sent":         "bg-blue-100 text-blue-800",
  "Awaiting Reply":     "bg-amber-100 text-amber-800",
  "Follow-up Required": "bg-orange-100 text-orange-800",
  "Quote Received":     "bg-emerald-100 text-emerald-800",
  "Quote Rejected":     "bg-red-100 text-red-800",
  "Quote Accepted":     "bg-[#0FFCBE] text-[#024a37]",
  "Supplier Declined":  "bg-rose-100 text-rose-800",
  "No Response":        "bg-slate-300 text-slate-800",
  "Booking Confirmed":  "bg-[#106EBE] text-white",
};

interface TrackerForm {
  status: string;
  quote_received: boolean;
  quote_amount: string | number;
  currency: string;
  hotel_offered: string;
  flight_included: boolean;
  transfer_included: boolean;
  activities_included: string;
  valid_until: string;
  remarks: string;
}

const EMPTY_FORM: TrackerForm = {
  status: "Email Sent", quote_received: false, quote_amount: "", currency: "INR", hotel_offered: "",
  flight_included: false, transfer_included: false, activities_included: "", valid_until: "", remarks: "",
};

export default function SupplierTracker({ leadId, leadPhone }: { leadId: string; leadPhone?: string }) {
  const [rows, setRows] = useState<LeadSupplier[]>([]);
  const [editing, setEditing] = useState<LeadSupplier | null>(null);
  const [form, setForm] = useState<TrackerForm>(EMPTY_FORM);

  const load = async () => {
    try { const { data } = await api.get<LeadSupplier[]>(`/lead-suppliers/${leadId}`); setRows(data); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, [leadId]);

  const openEdit = (r: LeadSupplier) => {
    setEditing(r);
    setForm({
      status: r.status || "Email Sent",
      quote_received: !!r.quote_received,
      quote_amount: r.quote_amount || "",
      currency: r.currency || "INR",
      hotel_offered: r.hotel_offered || "",
      flight_included: !!r.flight_included,
      transfer_included: !!r.transfer_included,
      activities_included: r.activities_included || "",
      valid_until: (r.valid_until || "").slice(0, 10),
      remarks: r.remarks || "",
    });
  };

  const save = async () => {
    if (!editing) return;
    if (form.quote_received && !form.quote_amount) { toast.error("Quote Amount is required"); return; }
    try {
      const payload = { ...form, quote_amount: form.quote_amount ? Number(form.quote_amount) : null };
      await api.patch(`/lead-suppliers/${editing.id}`, payload);
      toast.success("Response updated");
      setEditing(null); load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const followup = async (id: string) => {
    try { await api.post(`/lead-suppliers/${id}/followup`); toast.success("Follow-up logged"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  if (rows.length === 0) return null;
  const waPhone = (leadPhone || "").replace(/\D/g, "");

  return (
    <Card className="p-6 rounded-lg lg:col-span-3" data-testid="supplier-tracker">
      <h3 className="font-display text-xl font-bold mb-1">Supplier Response Tracker</h3>
      <p className="text-xs text-muted-foreground mb-4">Sequence # helps identify suppliers on phone / email replies quickly.</p>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border">
              <th className="text-left py-2 px-2 label-caps">#</th>
              <th className="text-left py-2 px-2 label-caps">Supplier</th>
              <th className="text-left py-2 px-2 label-caps">Status</th>
              <th className="text-left py-2 px-2 label-caps">Sent · F/U</th>
              <th className="text-right py-2 px-2 label-caps">Quote</th>
              <th className="text-right py-2 px-2 label-caps">Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const overdue = (r.hours_since_email ?? 0) > 24 && !r.reply_received;
              return (
                <tr key={r.id} className="border-b border-border/50" data-testid={`tracker-${r.sequence}`}>
                  <td className="py-3 px-2 font-mono font-bold text-base">#{r.sequence}</td>
                  <td className="py-3 px-2">
                    <div className="font-semibold">{r.supplier_name}</div>
                    <div className="text-xs text-muted-foreground">{r.supplier_email}</div>
                  </td>
                  <td className="py-3 px-2">
                    <span className={`text-xs font-semibold px-2 py-1 rounded ${STATUS_COLOR[r.status] || "bg-slate-100"}`}>
                      {r.status}
                    </span>
                    {overdue && <div className="text-[10px] text-red-600 font-bold mt-1">🔴 {r.hours_since_email}h overdue</div>}
                  </td>
                  <td className="py-3 px-2 text-xs">
                    <div>{r.hours_since_email}h ago</div>
                    <div className="text-muted-foreground">{r.followups} follow-up{r.followups !== 1 ? "s" : ""}</div>
                  </td>
                  <td className="py-3 px-2 text-right font-semibold">
                    {r.quote_received ? `${r.currency || "INR"} ${(r.quote_amount || 0).toLocaleString("en-IN")}` : "—"}
                  </td>
                  <td className="py-3 px-2 text-right space-x-1 whitespace-nowrap">
                    <Button size="sm" variant="outline" onClick={() => followup(r.id)} title="Log follow-up email" data-testid={`fu-${r.sequence}`}>
                      <Bell size={12} />
                    </Button>
                    <a href={`mailto:${r.supplier_email}`}><Button size="sm" variant="outline" title="Email"><Mail size={12} /></Button></a>
                    <a href={`tel:${r.supplier_email}`}><Button size="sm" variant="outline" title="Call"><Phone size={12} /></Button></a>
                    {waPhone && <a href={`https://wa.me/${waPhone}`} target="_blank" rel="noreferrer"><Button size="sm" variant="outline" title="WhatsApp customer"><MessageCircle size={12} /></Button></a>}
                    <Button size="sm" onClick={() => openEdit(r)} style={{ background: "#106EBE" }} data-testid={`edit-${r.sequence}`}>
                      <FileText size={12} /> Update
                    </Button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <Dialog open={!!editing} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto" data-testid="update-dialog">
          <DialogHeader><DialogTitle>#{editing?.sequence} · {editing?.supplier_name}</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <div>
              <Label className="label-caps">Status</Label>
              <Select value={form.status} onValueChange={(v) => setForm({ ...form, status: v })}>
                <SelectTrigger className="mt-1.5" data-testid="upd-status"><SelectValue /></SelectTrigger>
                <SelectContent>{SUPPLIER_STATUSES.map(s => <SelectItem key={s} value={s}>{s}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <label className="flex items-center gap-2 pt-2">
              <Checkbox checked={form.quote_received} onCheckedChange={(v) => setForm({ ...form, quote_received: !!v })} data-testid="upd-quote-received" />
              <span className="text-sm font-medium">Quote Received</span>
            </label>
            {form.quote_received && (
              <div className="grid grid-cols-3 gap-2">
                <div className="col-span-2"><Label className="label-caps">Quote Amount *</Label><Input type="number" className="mt-1.5" value={form.quote_amount} onChange={(e) => setForm({ ...form, quote_amount: e.target.value })} data-testid="upd-quote-amt" /></div>
                <div><Label className="label-caps">Currency</Label>
                  <Select value={form.currency} onValueChange={(v) => setForm({ ...form, currency: v })}>
                    <SelectTrigger className="mt-1.5"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {["INR","USD","EUR","GBP","AED","THB","SGD"].map(c => <SelectItem key={c} value={c}>{c}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
              </div>
            )}
            <div><Label className="label-caps">Hotel Offered</Label><Input className="mt-1.5" value={form.hotel_offered} onChange={(e) => setForm({ ...form, hotel_offered: e.target.value })} /></div>
            <div className="grid grid-cols-2 gap-3">
              <label className="flex items-center gap-2 pt-6"><Checkbox checked={form.flight_included} onCheckedChange={(v) => setForm({ ...form, flight_included: !!v })} /><span className="text-sm">Flight Included</span></label>
              <label className="flex items-center gap-2 pt-6"><Checkbox checked={form.transfer_included} onCheckedChange={(v) => setForm({ ...form, transfer_included: !!v })} /><span className="text-sm">Transfer Included</span></label>
            </div>
            <div><Label className="label-caps">Activities Included</Label><Input className="mt-1.5" value={form.activities_included} onChange={(e) => setForm({ ...form, activities_included: e.target.value })} /></div>
            <div><Label className="label-caps">Valid Until</Label><Input type="date" className="mt-1.5" value={form.valid_until} onChange={(e) => setForm({ ...form, valid_until: e.target.value })} /></div>
            <div><Label className="label-caps">Operations Remarks</Label><Input className="mt-1.5" value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} /></div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditing(null)}>Cancel</Button>
            <Button onClick={save} style={{ background: "#106EBE" }} data-testid="upd-save">Save Response</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
