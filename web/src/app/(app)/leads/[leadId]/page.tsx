"use client";

import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import { useRouter, useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Checkbox } from "@/components/ui/checkbox";
import StatusPill, { LEAD_STATUSES } from "@/components/app/StatusPill";
import SupplierTracker from "@/components/app/SupplierTracker";
import SupplierReplies, { useLeadReplies } from "@/components/app/SupplierReplies";
import LeadNotes from "@/components/app/LeadNotes";
import FollowupCountdown from "@/components/app/FollowupCountdown";
import LeadActivities from "@/components/activities/LeadActivities";
import ContactTimeline, { logContact } from "@/components/app/ContactTimeline";
import { toast } from "sonner";
import { useCurrentUser } from "@/context/AuthContext";
import type { Lead, LeadUpdate, Supplier, SupplierMailHistory, SupplierMailPreview, SupplierMailSendResult, User } from "@/types";
import { Mail, MessageCircle, ArrowLeft, Phone, Copy, MessageSquare, UserCog, ArrowRightLeft } from "lucide-react";
import Link from "next/link";
import ConvertLeadDialog from "@/components/crm/ConvertLeadDialog";

const INR = (n: number | string | null | undefined) => "₹" + (Number(n) || 0).toLocaleString("en-IN");

export default function LeadDetail() {
  const { leadId } = useParams<{ leadId: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const [lead, setLead] = useState<Lead | null>(null);
  const supplierReplies = useLeadReplies(leadId);
  const [history, setHistory] = useState<SupplierMailHistory[]>([]);
  const historyLm = useLoadMore(history, 10);
  const [mailOpen, setMailOpen] = useState(false);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [cc, setCc] = useState("");
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");
  const [mailPreviewText, setMailPreviewText] = useState("");
  const [timelineKey, setTimelineKey] = useState(0);
  const [reassignOpen, setReassignOpen] = useState(false);
  const [consultants, setConsultants] = useState<User[]>([]);
  const [assignTo, setAssignTo] = useState("");
  const [reassignReason, setReassignReason] = useState("");
  const [nrsqOpen, setNrsqOpen] = useState(false);
  const [convertOpen, setConvertOpen] = useState(false);
  const [nrsqForm, setNrsqForm] = useState<{ package_selected: string; selling_amount: string | number }>({ package_selected: "", selling_amount: "" });

  const load = async () => {
    try {
      const { data } = await api.get<Lead>(`/leads/${leadId}`);
      setLead(data);
      const h = await api.get<SupplierMailHistory[]>(`/supplier-mail/${leadId}`);
      setHistory(h.data);
    } catch (e) { toast.error(formatApiError(e)); }
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, [leadId]);

  if (!lead) return <div className="text-muted-foreground">Loading…</div>;

  const canOps = can("rsq.manage");
  const canQuote = can("rsq.request", "rsq.manage");

  const update = async (patch: LeadUpdate) => {
    try {
      const { data } = await api.patch<Lead>(`/leads/${leadId}`, patch);
      setLead(data);
      toast.success("Updated");
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const openMail = async () => {
    // Ops/Admin only path — sales uses createRsqRequest() instead
    if (lead.quote_type !== "rsq") {
      try { await api.patch(`/leads/${lead.lead_id}`, { quote_type: "rsq" }); } catch { /* ignore */ }
    }
    const { data } = await api.get<Supplier[]>("/suppliers");
    setSuppliers(data);
    setSubject(`Trip Enquiry - ${lead.lead_id} - ${lead.destination}`);
    setSelected({});
    try {
      const p = await api.get<SupplierMailPreview & { text?: string }>(`/supplier-mail/preview/${lead.lead_id}`);
      const txt = p.data.text || "";
      setMailPreviewText(txt);
      setBody(txt);
      if (p.data.subject) setSubject(p.data.subject);
    } catch { /* ignore */ }
    setMailOpen(true);
  };

  const createRsqRequest = async () => {
    // Sales path — creates RSQ request and notifies Ops, no mail dialog
    try {
      const { data } = await api.post<{ already_exists?: boolean }>("/rsq-requests", { lead_id: lead.lead_id, note: "" });
      if (data?.already_exists) {
        toast.info("RSQ already raised — Operations Team is on it");
      } else {
        toast.success("RSQ raised — Operations Team notified ✅");
      }
      load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const copyMail = async () => {
    try {
      await navigator.clipboard.writeText(`Subject: ${subject}\n\n${body}`);
      toast.success("Email copied to clipboard");
    } catch { toast.error("Copy failed"); }
  };

  const resetMailBody = () => {
    setBody(mailPreviewText);
    toast.info("Reset to default template");
  };

  const saveNrsq = async () => {
    if (!nrsqForm.selling_amount) { toast.error("Selling amount required"); return; }
    try {
      await api.patch(`/leads/${lead.lead_id}`, {
        quote_type: "nrsq",
        package_selected: nrsqForm.package_selected,
        selling_amount: Number(nrsqForm.selling_amount),
        quote_sent_to_customer: true,
        quote_sent_date: new Date().toISOString(),
        lead_status: "Quote Sent to Customer",
      });
      toast.success("NRSQ quote generated & lead marked 'Quote Sent to Customer'");
      setNrsqOpen(false);
      load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const send = async () => {
    const ids = Object.keys(selected).filter(k => selected[k]);
    if (ids.length === 0) { toast.error("Select at least one supplier"); return; }
    try {
      const { data } = await api.post<{ send_result?: SupplierMailSendResult & { status?: string; message_id?: string; error?: string } }>("/supplier-mail/send", {
        lead_id: lead.lead_id,
        supplier_ids: ids,
        cc: cc.split(",").map(s => s.trim()).filter(Boolean),
        bcc: [],
        subject, body,
      });
      const r = data.send_result || {};
      if (r.status === "sent") toast.success("Email sent. Replies will show up on this lead.");
      else if (r.status === "failed") toast.error(`Email failed: ${r.error || "unknown error"}`);
      else toast.info("Email simulated — the Microsoft 365 mailbox isn't connected yet, so nothing was sent.");
      setMailOpen(false);
      load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const canReassign = can("leads.reassign");

  const openReassign = async () => {
    try {
      const { data } = await api.get<User[]>("/users");
      setConsultants(data.filter(u => u.active !== false && (!!u.consultant_code || u.role === "sales" || u.role === "admin")));
    } catch { /* ignore */ }
    setAssignTo(""); setReassignReason("");
    setReassignOpen(true);
  };

  const doReassign = async () => {
    if (!assignTo) { toast.error("Choose a consultant"); return; }
    try {
      await api.post(`/leads/${lead.lead_id}/reassign`, { consultant_id: assignTo, reason: reassignReason });
      toast.success("Lead reassigned");
      setReassignOpen(false);
      load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const track = (kind: string, target: string) => {
    logContact(lead.lead_id, kind, target).then(() => setTimelineKey(k => k + 1));
  };

  const cleanPhone = (lead.phone || "").replace(/\D/g, "");
  const waPhone = cleanPhone.length === 10 ? `91${cleanPhone}` : cleanPhone;
  const whatsappLink = `https://wa.me/${waPhone}`;

  const copy = (val: string | undefined, label: string) => {
    if (!val) return;
    navigator.clipboard.writeText(val).then(() => toast.success(`${label} copied`));
  };

  return (
    <div className="space-y-6" data-testid="lead-detail-page">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.back()} className="mb-2 -ml-3" data-testid="back-btn">
            <ArrowLeft size={16} /> Back
          </Button>
          <div className="label-caps">Lead detail</div>
          <div className="flex items-center gap-3 mt-1">
            <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight">{lead.lead_id}</h1>
            <StatusPill status={lead.lead_status} />
          </div>
          <p className="text-sm text-muted-foreground mt-2">
            {lead.customer_name} · {lead.destination} · Travel {(lead.travel_date || "").slice(0, 10)}
          </p>
        </div>
          <div className="flex gap-2 flex-wrap">
            {canQuote && !lead.quote_type && (
              <>
                <Button onClick={() => setNrsqOpen(true)} className="h-11 bg-[#E5E5E5] text-[#0A0A0A] hover:bg-[#E5E5E5] dark:bg-neutral-800 dark:text-neutral-100 dark:hover:bg-neutral-700" data-testid="nrsq-btn" title="Generate quote instantly from predefined packages — no supplier email">
                  ⚡ NRSQ
                </Button>
                <Button onClick={canOps ? openMail : createRsqRequest} className="h-11" data-testid="rsq-btn" title={canOps ? "Compose supplier email now" : "Request Ops team to fetch supplier quotes"}>
                  <Mail size={16} /> RSQ
                </Button>
              </>
            )}
            {canQuote && lead.quote_type === "nrsq" && (
              <Button onClick={() => setNrsqOpen(true)} variant="outline" className="h-11" data-testid="nrsq-edit-btn">
                ⚡ Edit NRSQ Quote
              </Button>
            )}
            {canOps && lead.quote_type === "rsq" && (
              <Button onClick={openMail} className="h-11" data-testid="send-mail-btn">
                <Mail size={16} /> Supplier Mail
              </Button>
            )}
            {!canOps && lead.quote_type === "rsq" && lead.rsq_status === "new" && (
              <span className="inline-flex items-center gap-1.5 h-11 px-3 rounded-md border border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40 text-amber-800 dark:text-amber-300 text-xs font-semibold" data-testid="rsq-waiting-ops">
                <span className="inline-block w-2 h-2 rounded-full bg-amber-500 animate-pulse" /> Awaiting Ops to send mail
              </span>
            )}
            {lead.rsq_status === "pending_reply" && (
              <span className="inline-flex items-center gap-1.5 h-11 px-3 rounded-md border border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40 text-red-800 dark:text-red-300 text-xs font-semibold" data-testid="rsq-pending-reply">
                <span className="inline-block w-2 h-2 rounded-full bg-red-500 animate-ping" /> Awaiting supplier reply
              </span>
            )}
            {can("leads.convert") && !lead.deal_id && (
              <Button onClick={() => setConvertOpen(true)} variant="outline" className="h-11" data-testid="convert-btn"
                      title="Create the customer and the deal for this trip">
                <ArrowRightLeft size={16} /> {lead.customer_id ? "Add deal" : "Convert"}
              </Button>
            )}
            <a href={whatsappLink} target="_blank" rel="noreferrer" onClick={() => track("whatsapp", lead.phone)}>
              <Button variant="outline" className="h-11" data-testid="whatsapp-btn">
                <MessageCircle size={16} /> WhatsApp
              </Button>
            </a>
          </div>
      </div>

      {(lead.customer_id || lead.deal_id) && (
        <Card className="p-4 rounded-lg flex flex-wrap items-center gap-x-5 gap-y-2 text-sm" data-testid="converted-banner">
          <span className="inline-flex items-center gap-2 font-semibold"><ArrowRightLeft size={15} /> {lead.deal_id ? "Converted" : "Linked to a customer"}</span>
          {lead.customer_id && <Link href={`/customers/${lead.customer_id}`} className="underline underline-offset-2 hover:no-underline" data-testid="lead-customer-link">View customer</Link>}
          {lead.deal_id && <Link href={`/deals/${lead.deal_id}`} className="underline underline-offset-2 hover:no-underline" data-testid="lead-deal-link">View deal</Link>}
          {lead.converted_at && <span className="text-muted-foreground">on {(lead.converted_at || "").slice(0, 10)}</span>}
        </Card>
      )}

      <Card className="p-4 rounded-lg border-l-4 border-l-[#0A0A0A]" data-testid="contact-panel">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              <div className="font-display font-bold text-lg">{lead.customer_name}</div>
              <button onClick={() => copy(lead.phone, "Mobile")} className="text-sm font-mono text-muted-foreground hover:text-foreground flex items-center gap-1" data-testid="copy-phone">
                {lead.phone} <Copy size={11} />
              </button>
              {lead.email && (
                <button onClick={() => copy(lead.email, "Email")} className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1" data-testid="copy-email">
                  {lead.email} <Copy size={10} />
                </button>
              )}
            </div>
            <div className="label-caps mt-1">
              {lead.destination} · {lead.staff_name || "—"} · <StatusPill status={lead.lead_status} />
            </div>
          </div>
          <div className="flex gap-2 flex-wrap">
            <a href={`tel:+91${cleanPhone.slice(-10)}`} onClick={() => track("call", lead.phone)}><Button size="sm" variant="outline" title="Call" data-testid="qa-call"><Phone size={14} /> Call</Button></a>
            <a href={whatsappLink} target="_blank" rel="noreferrer" onClick={() => track("whatsapp", lead.phone)}><Button size="sm" style={{ background: "#25D366", color: "white" }} title="WhatsApp" data-testid="qa-whatsapp"><MessageCircle size={14} /> WhatsApp</Button></a>
            <a href={`sms:+91${cleanPhone.slice(-10)}`} onClick={() => track("sms", lead.phone)}><Button size="sm" variant="outline" title="SMS" data-testid="qa-sms"><MessageSquare size={14} /> SMS</Button></a>
            {lead.email && <a href={`mailto:${lead.email}?subject=Indian Rounds - ${lead.lead_id}`} onClick={() => track("email", lead.email ?? "")}><Button size="sm" variant="outline" title="Email" data-testid="qa-email"><Mail size={14} /> Email</Button></a>}
            {canReassign && <Button size="sm" variant="outline" onClick={openReassign} title="Reassign" data-testid="qa-reassign"><UserCog size={14} /> Reassign</Button>}
          </div>
        </div>
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="p-6 rounded-lg lg:col-span-2">
          <h3 className="font-display text-xl font-bold mb-4">Trip details</h3>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
            <KV label="Customer" value={lead.customer_name} />
            <KV label="Phone" value={lead.phone} />
            <KV label="Email" value={lead.email || "—"} />
            <KV label="Destination" value={lead.destination} />
            <KV label="Travel Date" value={(lead.travel_date || "").slice(0, 10) || "—"} />
            <KV label="Trip End Date" value={(lead.trip_end_date || "").slice(0, 10) || "—"} />
            <KV label="Duration" value={lead.duration_label || (lead.duration_days ? `${lead.duration_days}D` : "—")} />
            <KV label="Stay Cities" value={lead.stay_summary || "—"} full />
            <KV label="Passport" value={
              lead.passport_available === true ? "Yes" :
              lead.passport_available === false ? `No · ${lead.passport_reason || "—"}` : "—"
            } />
            <KV label="Package Type" value={lead.package_type || "—"} />
            <KV label="Hotel Category" value={lead.hotel_category || "—"} />
            <KV label="Meal Plan" value={lead.meal_plan || "—"} />
            <KV label="Rooms / Type" value={`${lead.rooms} · ${lead.room_type || "—"}`} />
            <KV label="Guests" value={`${lead.adults} adults, ${lead.children} children`} />
            <KV label="Departure From" value={lead.departure_from || "—"} />
            <KV label="Budget" value={lead.budget || "—"} />
            <KV label="Special Requests" value={lead.special_requests || "—"} full />
            <KV label="Staff" value={lead.staff_name} />
            <KV label="Lead Source" value={lead.lead_source || "—"} />
            <KV label="Last Updated" value={(lead.updated_at || "").slice(0, 16).replace("T", " ")} />
          </div>
        </Card>

        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Pipeline</h3>
          <div className="space-y-4">
            <div>
              <Label className="label-caps">Lead Status</Label>
              <Select value={lead.lead_status} onValueChange={(v) => update({ lead_status: v })}>
                <SelectTrigger className="mt-1.5 h-10" data-testid="update-status"><SelectValue /></SelectTrigger>
                <SelectContent>{LEAD_STATUSES.map(s => <SelectItem key={s} value={s}>{s}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <div>
              <Label className="label-caps">Booking Status</Label>
              <Select value={lead.booking_status || "none"} onValueChange={(v) => update({ booking_status: v === "none" ? "" : v })}>
                <SelectTrigger className="mt-1.5 h-10" data-testid="update-booking"><SelectValue placeholder="—" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">—</SelectItem>
                  <SelectItem value="In Progress">In Progress</SelectItem>
                  <SelectItem value="Booked">Booked</SelectItem>
                  <SelectItem value="Cancelled">Cancelled</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label className="label-caps">Follow-up Date & Time</Label>
              <Input type="datetime-local" defaultValue={(lead.follow_up_date || "").slice(0, 16)}
                     onBlur={(e) => e.target.value && update({ follow_up_date: new Date(e.target.value).toISOString() })}
                     className="mt-1.5" data-testid="update-followup" />
              {lead.follow_up_date && <FollowupCountdown iso={lead.follow_up_date} />}
            </div>
          </div>
        </Card>

        <Card className="p-6 rounded-lg lg:col-span-2">
          <h3 className="font-display text-xl font-bold mb-4">Quote & Pricing {canOps ? "" : "(Ops-only)"}</h3>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div>
              <Label className="label-caps">Supplier Quote ₹</Label>
              <Input type="number" disabled={!canOps} defaultValue={lead.supplier_quote_amount || 0}
                onBlur={(e) => update({ supplier_quote_amount: Number(e.target.value) })}
                className="mt-1.5" data-testid="upd-supplier-amt" />
            </div>
            <div>
              <Label className="label-caps">Selling ₹</Label>
              <Input type="number" defaultValue={lead.selling_amount || 0}
                onBlur={(e) => update({ selling_amount: Number(e.target.value) })}
                className="mt-1.5" data-testid="upd-selling-amt" />
            </div>
            <div>
              <Label className="label-caps">Profit</Label>
              <div className={`mt-1.5 h-10 flex items-center px-3 border border-border rounded-md font-semibold ${(lead.profit || 0) >= 0 ? "text-[#0F766E] dark:text-emerald-400" : "text-[#B91C1C] dark:text-red-400"}`}
                   data-testid="profit-display">
                {INR(lead.profit)}
              </div>
            </div>
            <div>
              <Label className="label-caps">Mail Status</Label>
              <div className="mt-1.5 h-10 flex items-center px-3 border border-border rounded-md text-sm">
                {lead.mail_status} {lead.supplier_mail_date ? `· ${lead.quote_pending_days}d ago` : ""}
              </div>
            </div>
            <div>
              <Label className="label-caps">Payment Status</Label>
              <Select value={lead.payment_status || "none"} onValueChange={(v) => update({ payment_status: v === "none" ? "" : v })}>
                <SelectTrigger className="mt-1.5 h-10" data-testid="upd-payment"><SelectValue placeholder="—" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">—</SelectItem>
                  <SelectItem value="Pending">Pending</SelectItem>
                  <SelectItem value="Partial">Partial</SelectItem>
                  <SelectItem value="Paid">Paid</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label className="label-caps">Remarks</Label>
              <Input defaultValue={lead.remarks || ""}
                onBlur={(e) => update({ remarks: e.target.value })}
                className="mt-1.5" data-testid="upd-remarks" />
            </div>
          </div>
        </Card>

        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Supplier mail history</h3>          {history.length === 0 && <div className="text-sm text-muted-foreground">No emails sent yet.</div>}
          <div className="space-y-3">
            {historyLm.visible.map((h) => (
              <div key={h.id} className="border-l-2 border-[#0A0A0A] dark:border-white/70 pl-3 py-1">
                <div className="text-sm font-semibold">{h.subject}</div>
                <div className="label-caps mt-0.5">{(h.sent_at || "").slice(0, 16).replace("T", " ")}</div>
                <div className="text-xs text-muted-foreground mt-1">
                  To: {(h.to_suppliers ?? []).map((s) => (typeof s === "string" ? s : (s as { name?: string }).name)).join(", ")}
                </div>
                <div className="text-[10px] mt-1">
                  <span className={`px-1.5 py-0.5 rounded font-semibold ${
                    h.status === "sent" ? "bg-emerald-100 text-emerald-800 dark:text-emerald-300 dark:bg-emerald-950/60 dark:text-emerald-300" :
                    h.status === "failed" ? "bg-red-100 text-red-800 dark:text-red-300 dark:bg-red-950/60 dark:text-red-300" :
                    "bg-amber-100 text-amber-800 dark:text-amber-300 dark:bg-amber-950/60 dark:text-amber-300"
                  }`}>
                    {h.status === "sent" ? "SENT" :
                     h.status === "failed" ? "FAILED" : "SIMULATED"}
                  </span>
                  {h.message_id && <span className="ml-2 text-muted-foreground font-mono">{h.message_id.slice(0, 12)}…</span>}
                </div>
              </div>
            ))}
          </div>
          <LoadMore lm={historyLm} className="mt-3" />
        </Card>
      </div>

      {lead.quote_type === "rsq" && <SupplierTracker leadId={lead.lead_id} leadPhone={lead.phone} replies={supplierReplies.replies} />}

      <SupplierReplies
        leadId={lead.lead_id}
        replies={supplierReplies.replies}
        patchLocal={supplierReplies.patchLocal}
        bump={supplierReplies.bump}
        reload={supplierReplies.reload}
        show={supplierReplies.replies.length > 0 || can("rsq.request", "rsq.manage")}
      />

      {!lead.quote_type && (
        <Card className="p-6 rounded-lg lg:col-span-3 border-2 border-dashed" data-testid="quote-choice-card">
          <div className="text-center py-4">
            <div className="label-caps">Quotation Method</div>
            <h3 className="font-display text-xl font-bold mt-1">Choose how to generate this customer&apos;s quote</h3>
            <p className="text-sm text-muted-foreground mt-2 max-w-2xl mx-auto">
              For pre-packaged trips with fixed pricing, use <b>NRSQ</b>. For customized itineraries or live supplier pricing, use <b>RSQ</b>.
            </p>
            <div className="flex justify-center gap-3 mt-4 flex-wrap">
              <Button onClick={() => setNrsqOpen(true)} className="h-11 px-6 bg-[#E5E5E5] text-[#0A0A0A] hover:bg-[#E5E5E5] dark:bg-neutral-800 dark:text-neutral-100 dark:hover:bg-neutral-700" data-testid="nrsq-cta">
                ⚡ NRSQ — Instant Quote
              </Button>
              <Button onClick={canOps ? openMail : createRsqRequest} className="h-11 px-6" data-testid="rsq-cta">
                <Mail size={16} /> {canOps ? "RSQ — Compose Supplier Mail" : "RSQ — Request Ops Team"}
              </Button>
            </div>
          </div>
        </Card>
      )}

      {lead.quote_type === "nrsq" && (
        <Card className="p-6 rounded-lg lg:col-span-3" data-testid="nrsq-summary">
          <div className="flex items-center justify-between flex-wrap gap-2">
            <div>
              <div className="label-caps flex items-center gap-2">
                <span className="px-2 py-0.5 bg-[#E5E5E5] text-[#0A0A0A] dark:bg-neutral-800 dark:text-neutral-100 rounded font-bold">NRSQ</span>
                No Requirement Supplier Quotation
              </div>
              <h3 className="font-display text-xl font-bold mt-1">
                {lead.package_selected || "Custom package"} · ₹{(lead.selling_amount || 0).toLocaleString("en-IN")}
              </h3>
              <div className="text-sm text-muted-foreground mt-1">
                Quote generated from internal pricing — no supplier email sent.
                {lead.quote_sent_date && ` · Sent on ${lead.quote_sent_date.slice(0, 10)}`}
              </div>
            </div>
            <Button variant="outline" onClick={() => { setNrsqForm({ package_selected: lead.package_selected || "", selling_amount: lead.selling_amount || "" }); setNrsqOpen(true); }} data-testid="nrsq-edit">
              Edit Quote
            </Button>
          </div>
        </Card>
      )}

      {can("activities.view") && <LeadActivities leadId={lead.lead_id} customerName={lead.customer_name} />}

      <LeadNotes leadId={lead.lead_id} />

      <ContactTimeline leadId={lead.lead_id} refreshKey={timelineKey} />

      {/* NRSQ Dialog */}
      <Dialog open={nrsqOpen} onOpenChange={setNrsqOpen}>
        <DialogContent data-testid="nrsq-dialog">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <span className="px-2 py-0.5 bg-[#E5E5E5] text-[#0A0A0A] dark:bg-neutral-800 dark:text-neutral-100 rounded text-xs font-bold">NRSQ</span>
              Generate Instant Quote
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="text-xs text-muted-foreground p-3 rounded-md bg-secondary/50">
              Use predefined packages / internal pricing. No supplier email will be sent.
              Lead status auto-updates to &quot;Quote Sent to Customer&quot;.
            </div>
            <div>
              <Label className="label-caps">Package Name</Label>
              <Input className="mt-1.5" value={nrsqForm.package_selected}
                     onChange={(e) => setNrsqForm({ ...nrsqForm, package_selected: e.target.value })}
                     placeholder="e.g. Bali Honeymoon 5N/6D · Villa · AP"
                     data-testid="nrsq-package" />
            </div>
            <div>
              <Label className="label-caps">Selling Amount (₹) *</Label>
              <Input type="number" className="mt-1.5" value={nrsqForm.selling_amount}
                     onChange={(e) => setNrsqForm({ ...nrsqForm, selling_amount: e.target.value })}
                     placeholder="e.g. 95000"
                     data-testid="nrsq-amount" />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setNrsqOpen(false)}>Cancel</Button>
            <Button onClick={saveNrsq} className="bg-[#E5E5E5] text-[#0A0A0A] hover:bg-[#E5E5E5] dark:bg-neutral-800 dark:text-neutral-100 dark:hover:bg-neutral-700" data-testid="nrsq-save">
              Generate Quote & Send
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reassign Dialog */}
      <Dialog open={reassignOpen} onOpenChange={setReassignOpen}>
        <DialogContent data-testid="reassign-dialog">
          <DialogHeader><DialogTitle>Reassign {lead.lead_id}</DialogTitle></DialogHeader>
          <div className="space-y-3">
            <div className="text-sm text-muted-foreground">
              Currently owned by <b>{lead.staff_name || "—"}</b> ({lead.staff_code || "—"}).
            </div>
            <div>
              <Label className="label-caps">Assign to</Label>
              <Select value={assignTo || undefined} onValueChange={setAssignTo}>
                <SelectTrigger className="mt-1.5" data-testid="reassign-select"><SelectValue placeholder="Choose consultant" /></SelectTrigger>
                <SelectContent>
                  {consultants.map(c =>
                    <SelectItem key={c.id} value={c.id}>{c.name} · {c.consultant_code || "??"}</SelectItem>
                  )}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label className="label-caps">Reason (optional)</Label>
              <Input className="mt-1.5" value={reassignReason} onChange={(e) => setReassignReason(e.target.value)}
                     placeholder="e.g. Karuva on leave, escalation..." data-testid="reassign-reason" />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setReassignOpen(false)}>Cancel</Button>
            <Button onClick={doReassign} data-testid="reassign-save">Reassign</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Mail Dialog */}
      <Dialog open={mailOpen} onOpenChange={setMailOpen}>
        <DialogContent className="max-w-3xl max-h-[92vh] overflow-y-auto" data-testid="mail-dialog">
          <DialogHeader><DialogTitle>Send Supplier Enquiry — {lead.lead_id}</DialogTitle></DialogHeader>
          <div className="space-y-4">
            <div>
              <Label className="label-caps">Select suppliers</Label>
              <div className="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2 max-h-40 overflow-y-auto border border-border rounded-md p-3">
                {suppliers.length === 0 && <div className="text-sm text-muted-foreground">No suppliers. Add some in Suppliers page.</div>}
                {suppliers.map(s => (
                  <label key={s.id} className="flex items-start gap-2 cursor-pointer text-sm" data-testid={`supplier-${s.id}`}>
                    <Checkbox checked={!!selected[s.id]} onCheckedChange={(v) => setSelected(p => ({ ...p, [s.id]: v === true }))} />
                    <div>
                      <div className="font-medium">{s.name}</div>
                      <div className="text-xs text-muted-foreground">{s.email} · {s.destination}</div>
                    </div>
                  </label>
                ))}
              </div>
            </div>
            <div>
              <Label className="label-caps">CC (comma-separated)</Label>
              <Input className="mt-1.5" value={cc} onChange={(e) => setCc(e.target.value)} data-testid="mail-cc" />
            </div>
            <div>
              <Label className="label-caps">Subject</Label>
              <Input className="mt-1.5" value={subject} onChange={(e) => setSubject(e.target.value)} data-testid="mail-subject" />
            </div>
            <div>
              <div className="flex items-center justify-between mb-1.5">
                <Label className="label-caps">Email body — plain text · fully editable</Label>
                <div className="flex gap-2">
                  <Button type="button" size="sm" variant="outline" onClick={resetMailBody} data-testid="mail-reset">
                    Reset to template
                  </Button>
                  <Button type="button" size="sm" variant="outline" onClick={copyMail} data-testid="mail-copy">
                    <Copy size={14} /> Copy
                  </Button>
                </div>
              </div>
              <Textarea rows={22}
                        className="font-mono text-[13px] leading-relaxed whitespace-pre"
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        data-testid="mail-body"
                        placeholder="Loading template…" />
              <div className="text-[11px] text-muted-foreground mt-1">
                Auto-populated from lead data. Edit freely before sending. Blank fields default to N/A.
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setMailOpen(false)}>Cancel</Button>
            <Button onClick={send} data-testid="mail-send">Send Enquiry</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <ConvertLeadDialog leadId={leadId} open={convertOpen} onOpenChange={setConvertOpen} onConverted={() => void load()} />
    </div>
  );
}

function KV({ label, value, full }: { label: string; value?: string | number | null; full?: boolean }) {
  return (
    <div className={full ? "col-span-2 md:col-span-3" : ""}>
      <div className="label-caps">{label}</div>
      <div className="font-medium mt-0.5">{value}</div>
    </div>
  );
}
