"use client";

import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { Textarea } from "@/components/ui/textarea";
import { useCurrentUser } from "@/context/AuthContext";
import { Mail, Phone, MessageCircle, FileText, Bell, MessagesSquare, Paperclip } from "lucide-react";
import { toast } from "sonner";
import type { LeadSupplier, SupplierReply } from "@/types";

const SUPPLIER_STATUSES = [
  "Email Not Sent","Email Sent","Awaiting Reply","Follow-up Required","Quote Received",
  "Quote Rejected","Quote Accepted","Supplier Declined","No Response","Booking Confirmed",
];
const STATUS_COLOR: Record<string, string> = {
  "Email Not Sent":     "bg-slate-200 text-slate-700 dark:bg-neutral-800 dark:text-neutral-300",
  "Email Sent":         "bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300",
  "Awaiting Reply":     "bg-amber-100 text-amber-800 dark:text-amber-300 dark:bg-amber-950/60 dark:text-amber-300",
  "Follow-up Required": "bg-orange-100 text-orange-800 dark:bg-orange-950/60 dark:text-orange-300",
  "Quote Received":     "bg-emerald-100 text-emerald-800 dark:text-emerald-300 dark:bg-emerald-950/60 dark:text-emerald-300",
  "Quote Rejected":     "bg-red-100 text-red-800 dark:text-red-300 dark:bg-red-950/60 dark:text-red-300",
  "Quote Accepted":     "bg-[#E5E5E5] text-[#0A0A0A] dark:bg-neutral-800 dark:text-neutral-100",
  "Supplier Declined":  "bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300",
  "No Response":        "bg-slate-300 text-slate-800 dark:bg-neutral-700 dark:text-neutral-200",
  "Booking Confirmed":  "bg-[#0A0A0A] text-white dark:bg-white dark:text-neutral-900",
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
  respond_by: string;
}

const EMPTY_FORM: TrackerForm = {
  status: "Email Sent", quote_received: false, quote_amount: "", currency: "INR", hotel_offered: "",
  flight_included: false, transfer_included: false, activities_included: "", valid_until: "", remarks: "", respond_by: "",
};

interface TrackerMessage { id: string; from: "supplier" | "team"; body: string; at: string | null; seen: boolean | null }

/** The conversation with one portal supplier about one enquiry. */
function MessagesDialog({ row, onClose, onChanged }: { row: LeadSupplier | null; onClose: () => void; onChanged: () => void }) {
  const [items, setItems] = useState<TrackerMessage[]>([]);
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!row) { setItems([]); setText(""); return; }
    let live = true;
    api.get<TrackerMessage[]>(`/lead-suppliers/${row.id}/messages`).then((r) => { if (live) setItems(r.data); }).catch((e) => toast.error(formatApiError(e)));
    return () => { live = false; };
  }, [row]);

  const send = async () => {
    if (!row || !text.trim()) return;
    setBusy(true);
    try {
      const { data } = await api.post<{ messages: TrackerMessage[]; notified: boolean }>(`/lead-suppliers/${row.id}/messages`, { body: text.trim() });
      setItems(data.messages); setText(""); onChanged();
      toast.success(data.notified ? "Sent. The supplier has been e-mailed a link." : "Saved. The e-mail to the supplier could not be sent.");
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  return (
    <Dialog open={!!row} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto" data-testid="messages-dialog">
        <DialogHeader><DialogTitle>Messages · {row?.supplier_name}</DialogTitle></DialogHeader>
        <p className="text-xs text-muted-foreground">The supplier reads and answers these in the portal. Do not put customer names or contact details here.</p>
        <ul className="space-y-2">
          {items.length === 0 && <li className="text-sm text-muted-foreground">No messages yet.</li>}
          {items.map((m) => (
            <li key={m.id} className={m.from === "team" ? "ml-8" : "mr-8"}>
              <div className={`rounded-lg p-3 text-sm ${m.from === "team" ? "bg-secondary" : "bg-sky-50 dark:bg-sky-950/30"}`}>
                <div className="mb-0.5 flex justify-between gap-2 text-xs text-muted-foreground">
                  <span className="font-semibold">{m.from === "team" ? "Us" : row?.supplier_name}</span>
                  <span>{m.at ? new Date(m.at).toLocaleString("en-IN", { dateStyle: "medium", timeStyle: "short" }) : ""}{m.from === "team" && (m.seen ? " · seen" : " · not seen yet")}</span>
                </div>
                <div className="whitespace-pre-wrap break-words">{m.body}</div>
              </div>
            </li>
          ))}
        </ul>
        <Textarea rows={3} value={text} maxLength={2000} onChange={(e) => setText(e.target.value)} placeholder="Write to the supplier" data-testid="msg-text" aria-label="Message to the supplier" />
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Close</Button>
          <Button onClick={send} disabled={busy || !text.trim()} data-testid="msg-send">{busy ? "Sending…" : "Send"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function SupplierTracker({ leadId, leadPhone, replies = [], onUsed }: { leadId: string; leadPhone?: string; replies?: SupplierReply[]; onUsed?: () => void }) {
  const { can } = useCurrentUser();
  const canManage = can("suppliers.manage");
  const [chat, setChat] = useState<LeadSupplier | null>(null);
  const [rows, setRows] = useState<LeadSupplier[]>([]);
  const lm = useLoadMore(rows, 10);
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
      respond_by: (r.respond_by || "").slice(0, 10),
    });
  };

  const save = async () => {
    if (!editing) return;
    if (form.quote_received && !form.quote_amount) { toast.error("Quote Amount is required"); return; }
    try {
      const payload = { ...form, quote_amount: form.quote_amount ? Number(form.quote_amount) : null, respond_by: form.respond_by || null };
      await api.patch(`/lead-suppliers/${editing.id}`, payload);
      toast.success("Response updated");
      setEditing(null); load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const followup = async (id: string) => {
    try { await api.post(`/lead-suppliers/${id}/followup`); toast.success("Follow-up logged"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const remind = async (r: LeadSupplier) => {
    if (!window.confirm(`E-mail ${r.supplier_name} a reminder to send their quote?`)) return;
    try { await api.post(`/lead-suppliers/${r.id}/portal-remind`); toast.success("Reminder sent"); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const chooseQuote = async (r: LeadSupplier) => {
    const cur = r.currency || "INR";
    const amt = Number(r.quote_amount || 0);
    let inr: number | undefined;
    if (cur !== "INR") {
      const v = window.prompt(`${r.supplier_name} quoted ${cur} ${amt.toLocaleString("en-IN")}.\nEnter the amount in INR to use as the supplier quote:`);
      if (v === null) return;
      inr = Number(v.replace(/,/g, ""));
      if (!(inr > 0)) { toast.error("Enter a valid INR amount"); return; }
    } else if (!window.confirm(`Use ${r.supplier_name}'s quote of INR ${amt.toLocaleString("en-IN")} as this lead's supplier quote?`)) return;
    try {
      await api.post(`/lead-suppliers/${r.id}/use-quote`, inr ? { amount_inr: inr } : {});
      toast.success("Supplier quote set on the lead");
      load(); onUsed?.();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const replyState = (r: LeadSupplier): "bounce" | "replied" | null => {
    const email = (r.supplier_email || "").toLowerCase();
    const mine = replies.filter((x) =>
      (r.supplier_id && x.supplier?.id === r.supplier_id) ||
      (email && ((x.supplier?.email || "").toLowerCase() === email || (x.from_email || "").toLowerCase() === email)));
    if (mine.some((x) => x.status === "bounce")) return "bounce";
    if (mine.some((x) => x.status !== "ignored" && x.status !== "auto_reply")) return "replied";
    return null;
  };

  const quoted = rows.filter((r) => r.quote_received && r.quote_amount);
  const lowest = (cur: string) => Math.min(...quoted.filter((x) => (x.currency || "INR") === cur).map((x) => Number(x.quote_amount)));

  if (rows.length === 0) return null;
  const waPhone = (leadPhone || "").replace(/\D/g, "");

  return (
    <Card className="p-6 rounded-lg lg:col-span-3" data-testid="supplier-tracker">
      <h3 className="font-display text-xl font-bold mb-1">Supplier Response Tracker</h3>
      <p className="text-xs text-muted-foreground mb-4">Sequence # helps identify suppliers on phone / email replies quickly.</p>
      {quoted.length > 0 && (
        <div className="mb-5 overflow-x-auto rounded-md border border-border" data-testid="quote-compare">
          <div className="border-b border-border bg-secondary/50 px-3 py-2 text-sm font-semibold">Quotes received ({quoted.length})</div>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left">
                <th className="px-3 py-2 label-caps">Supplier</th>
                <th className="px-3 py-2 label-caps text-right">Total</th>
                <th className="px-3 py-2 label-caps">Hotel</th>
                <th className="px-3 py-2 label-caps">Includes</th>
                <th className="px-3 py-2 label-caps">Valid until</th>
                <th className="px-3 py-2 label-caps">Files</th>
                {canManage && <th className="px-3 py-2"><span className="sr-only">Use</span></th>}
              </tr>
            </thead>
            <tbody>
              {quoted.map((q) => {
                const cheapest = quoted.filter((x) => (x.currency || "INR") === (q.currency || "INR")).length > 1 && q.quote_amount === lowest(q.currency || "INR");
                const used = q.status === "Quote Accepted" || q.status === "Booking Confirmed";
                return (
                  <tr key={q.id} className="border-b border-border/50 align-top" data-testid={`compare-${q.sequence}`}>
                    <td className="px-3 py-2"><span className="font-mono font-bold">#{q.sequence}</span> {q.supplier_name}</td>
                    <td className="px-3 py-2 text-right font-semibold whitespace-nowrap">
                      {q.currency || "INR"} {(q.quote_amount || 0).toLocaleString("en-IN")}
                      {cheapest && <div className="text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Lowest</div>}
                    </td>
                    <td className="px-3 py-2">{q.hotel_offered || "—"}</td>
                    <td className="px-3 py-2 text-xs">
                      <div>Flights: {q.flight_included ? "yes" : "no"} · Transfers: {q.transfer_included ? "yes" : "no"}</div>
                      {q.activities_included && <div className="text-muted-foreground">{q.activities_included}</div>}
                    </td>
                    <td className="px-3 py-2 whitespace-nowrap">{q.valid_until ? new Date(q.valid_until).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "—"}</td>
                    <td className="px-3 py-2">
                      {(q.quote_files ?? []).length === 0 ? "—" : (
                        <div className="flex flex-col gap-1">
                          {(q.quote_files ?? []).map((f) => (
                            <a key={f.id} href={`/api/supplier-replies/attachments/${f.id}/download`} download className="inline-flex max-w-[180px] items-center gap-1 text-xs underline underline-offset-2" title={f.filename}>
                              <Paperclip size={11} aria-hidden /><span className="truncate">{f.filename}</span>
                            </a>
                          ))}
                        </div>
                      )}
                    </td>
                    {canManage && (
                      <td className="px-3 py-2 text-right">
                        {used ? <span className="text-xs font-semibold text-emerald-700 dark:text-emerald-400">In use</span>
                          : <Button size="sm" variant="outline" onClick={() => chooseQuote(q)} data-testid={`use-quote-${q.sequence}`}>Use this quote</Button>}
                      </td>
                    )}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
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
            {lm.visible.map((r) => {
              const overdue = (r.hours_since_email ?? 0) > 24 && !r.reply_received;
              return (
                <tr key={r.id} className="border-b border-border/50" data-testid={`tracker-${r.sequence}`}>
                  <td className="py-3 px-2 font-mono font-bold text-base">#{r.sequence}</td>
                  <td className="py-3 px-2">
                    <div className="font-semibold">{r.supplier_name}</div>
                    <div className="text-xs text-muted-foreground">{r.supplier_email}</div>
                    {r.portal && (
                      <div className="mt-1 flex flex-wrap items-center gap-1">
                        <span className="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 dark:bg-sky-950/60 dark:text-sky-300">Portal</span>
                        {r.respond_by && !r.reply_received && <span className="text-[10px] text-muted-foreground">reply by {new Date(r.respond_by).toLocaleDateString("en-IN", { day: "numeric", month: "short" })}</span>}
                        {(r.portal_reminders ?? 0) > 0 && <span className="text-[10px] text-muted-foreground">· {r.portal_reminders} reminder{r.portal_reminders === 1 ? "" : "s"}</span>}
                      </div>
                    )}
                    {(() => {
                      const st = replyState(r);
                      if (!st) return null;
                      return (
                        <a
                          href="#supplier-replies"
                          onClick={(e) => { e.preventDefault(); document.getElementById("supplier-replies")?.scrollIntoView({ behavior: "smooth", block: "start" }); }}
                          className={`mt-1 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide hover:underline ${st === "bounce" ? "bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300" : "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"}`}
                          title="Jump to this supplier's replies" data-testid={`tracker-reply-${r.sequence}`}
                        >
                          <Mail size={10} aria-hidden /> {st === "bounce" ? "Bounced" : "Replied"}
                        </a>
                      );
                    })()}
                  </td>
                  <td className="py-3 px-2">
                    <span className={`text-xs font-semibold px-2 py-1 rounded ${STATUS_COLOR[r.status] || "bg-slate-100 dark:bg-neutral-800"}`}>
                      {r.status}
                    </span>
                    {overdue && <div className="text-[10px] text-red-600 dark:text-red-400 font-bold mt-1">🔴 {r.hours_since_email}h overdue</div>}
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
                    {r.portal && canManage && (
                      <Button size="sm" variant="outline" onClick={() => setChat(r)} title="Messages with the supplier" data-testid={`msgs-${r.sequence}`}>
                        <MessagesSquare size={12} />{(r.messages ?? 0) > 0 && <span className="ml-1 text-[10px] font-bold">{r.messages}</span>}
                      </Button>
                    )}
                    {r.portal && canManage && !r.reply_received && (
                      <Button size="sm" variant="outline" onClick={() => remind(r)} title="E-mail a reminder to quote" data-testid={`remind-${r.sequence}`}>Remind</Button>
                    )}
                    <a href={`mailto:${r.supplier_email}`}><Button size="sm" variant="outline" title="Email"><Mail size={12} /></Button></a>
                    <a href={`tel:${r.supplier_email}`}><Button size="sm" variant="outline" title="Call"><Phone size={12} /></Button></a>
                    {waPhone && <a href={`https://wa.me/${waPhone}`} target="_blank" rel="noreferrer"><Button size="sm" variant="outline" title="WhatsApp customer"><MessageCircle size={12} /></Button></a>}
                    <Button size="sm" onClick={() => openEdit(r)} data-testid={`edit-${r.sequence}`}>
                      <FileText size={12} /> Update
                    </Button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <LoadMore lm={lm} className="mt-3" />

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
            {editing?.portal && (
              <div><Label className="label-caps">Supplier must reply by</Label><Input type="date" className="mt-1.5" value={form.respond_by} onChange={(e) => setForm({ ...form, respond_by: e.target.value })} data-testid="upd-respond-by" /></div>
            )}
            <div><Label className="label-caps">Operations Remarks</Label><Input className="mt-1.5" value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} /></div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditing(null)}>Cancel</Button>
            <Button onClick={save} data-testid="upd-save">Save Response</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <MessagesDialog row={chat} onClose={() => { setChat(null); void load(); }} onChanged={() => void load()} />
    </Card>
  );
}
