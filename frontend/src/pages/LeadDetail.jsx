import { useEffect, useState, useMemo } from "react";
import { useParams, useNavigate } from "react-router-dom";
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
import LeadNotes from "@/components/app/LeadNotes";
import FollowupCountdown from "@/components/app/FollowupCountdown";
import ContactTimeline, { logContact } from "@/components/app/ContactTimeline";
import { toast } from "sonner";
import { useAuth } from "@/context/AuthContext";
import { Mail, MessageCircle, Sparkles, ArrowLeft, Phone, Copy, MessageSquare, UserCog } from "lucide-react";

const INR = (n) => "₹" + (Number(n) || 0).toLocaleString("en-IN");

export default function LeadDetail() {
  const { leadId } = useParams();
  const nav = useNavigate();
  const { user } = useAuth();
  const [lead, setLead] = useState(null);
  const [history, setHistory] = useState([]);
  const [mailOpen, setMailOpen] = useState(false);
  const [suppliers, setSuppliers] = useState([]);
  const [selected, setSelected] = useState({});
  const [cc, setCc] = useState("");
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");
  const [mailPreviewHtml, setMailPreviewHtml] = useState("");
  const [timelineKey, setTimelineKey] = useState(0);
  const [reassignOpen, setReassignOpen] = useState(false);
  const [consultants, setConsultants] = useState([]);
  const [assignTo, setAssignTo] = useState("");
  const [reassignReason, setReassignReason] = useState("");
  const [aiOpen, setAiOpen] = useState(false);
  const [aiContent, setAiContent] = useState("");
  const [aiBusy, setAiBusy] = useState(false);

  const load = async () => {
    try {
      const { data } = await api.get(`/leads/${leadId}`);
      setLead(data);
      const h = await api.get(`/supplier-mail/${leadId}`);
      setHistory(h.data);
    } catch (e) { toast.error(formatApiError(e)); }
  };

  useEffect(() => { load(); /* eslint-disable-next-line */ }, [leadId]);

  // Refresh mail preview when intro body changes
  useEffect(() => {
    if (!mailOpen || !lead) return;
    const t = setTimeout(async () => {
      try {
        const p = await api.get(`/supplier-mail/preview/${lead.lead_id}`, { params: { intro: body } });
        setMailPreviewHtml(p.data.html || "");
      } catch { /* ignore */ }
    }, 400);
    return () => clearTimeout(t);
  }, [body, mailOpen, lead, leadId]);

  const canOps = user.role === "operations" || user.role === "admin";

  const update = async (patch) => {
    try {
      const { data } = await api.patch(`/leads/${leadId}`, patch);
      setLead(data);
      toast.success("Updated");
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const openMail = async () => {
    const { data } = await api.get("/suppliers");
    setSuppliers(data);
    setSubject(`Travel Enquiry - ${lead.lead_id} - ${lead.destination}`);
    setBody("");
    setSelected({});
    try {
      const p = await api.get(`/supplier-mail/preview/${lead.lead_id}`);
      setMailPreviewHtml(p.data.html || "");
    } catch { /* ignore */ }
    setMailOpen(true);
  };

  const send = async () => {
    const ids = Object.keys(selected).filter(k => selected[k]);
    if (ids.length === 0) { toast.error("Select at least one supplier"); return; }
    try {
      const { data } = await api.post("/supplier-mail/send", {
        lead_id: lead.lead_id,
        supplier_ids: ids,
        cc: cc.split(",").map(s => s.trim()).filter(Boolean),
        bcc: [],
        subject, body,
      });
      const r = data.send_result || {};
      if (r.status === "sent") toast.success(`Email sent via Resend (${(r.message_id || "").slice(0, 8)}…)`);
      else if (r.status === "failed") toast.error(`Resend failed: ${r.error || "unknown"}`);
      else toast.info("Email simulated — configure RESEND_API_KEY in backend .env to send for real.");
      setMailOpen(false);
      load();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const runAI = async (type) => {
    setAiBusy(true); setAiContent(""); setAiOpen(true);
    try {
      const endpoint = type === "itinerary" ? "/ai/itinerary" : "/ai/quote-recommend";
      const payload = {
        destination: lead.destination,
        days: 5, budget: lead.budget, package_type: lead.package_type || "Family",
        adults: lead.adults, children: lead.children,
        hotel_category: lead.hotel_category || "4 Star",
        meal_plan: lead.meal_plan || "AP",
      };
      const { data } = await api.post(endpoint, payload);
      setAiContent(data.content);
    } catch (e) { setAiContent("Error: " + formatApiError(e)); }
    finally { setAiBusy(false); }
  };

  if (!lead) return <div className="text-muted-foreground">Loading…</div>;

  const canReassign = user.role === "admin" || user.role === "operations";

  const openReassign = async () => {
    try {
      const { data } = await api.get("/users");
      setConsultants(data.filter(u => u.role === "sales" || u.role === "admin"));
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

  const track = (kind, target) => {
    logContact(lead.lead_id, kind, target).then(() => setTimelineKey(k => k + 1));
  };

  const cleanPhone = (lead.phone || "").replace(/\D/g, "");
  const waPhone = cleanPhone.length === 10 ? `91${cleanPhone}` : cleanPhone;
  const whatsappLink = `https://wa.me/${waPhone}`;

  const copy = (val, label) => {
    if (!val) return;
    navigator.clipboard.writeText(val).then(() => toast.success(`${label} copied`));
  };

  return (
    <div className="space-y-6" data-testid="lead-detail-page">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => nav(-1)} className="mb-2 -ml-3" data-testid="back-btn">
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
          {canOps && (
            <Button onClick={openMail} className="h-11" style={{ background: "#106EBE" }} data-testid="send-mail-btn">
              <Mail size={16} /> Supplier Mail
            </Button>
          )}
          <a href={whatsappLink} target="_blank" rel="noreferrer">
            <Button variant="outline" className="h-11" data-testid="whatsapp-btn">
              <MessageCircle size={16} /> WhatsApp
            </Button>
          </a>
          <Button variant="outline" className="h-11" onClick={() => runAI("itinerary")} data-testid="ai-itinerary-btn">
            <Sparkles size={16} /> AI Itinerary
          </Button>
          <Button variant="outline" className="h-11" onClick={() => runAI("quote")} data-testid="ai-quote-btn">
            <Sparkles size={16} /> AI Quote
          </Button>
        </div>
      </div>

      <Card className="p-4 rounded-lg border-l-4 border-l-[#106EBE]" data-testid="contact-panel">
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
            {lead.email && <a href={`mailto:${lead.email}?subject=Indian Rounds - ${lead.lead_id}`} onClick={() => track("email", lead.email)}><Button size="sm" variant="outline" title="Email" data-testid="qa-email"><Mail size={14} /> Email</Button></a>}
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
              <div className="mt-1.5 h-10 flex items-center px-3 border border-border rounded-md font-semibold"
                   style={{ color: (lead.profit || 0) >= 0 ? "#0F766E" : "#B91C1C" }}
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
            {history.map((h) => (
              <div key={h.id} className="border-l-2 border-[#106EBE] pl-3 py-1">
                <div className="text-sm font-semibold">{h.subject}</div>
                <div className="label-caps mt-0.5">{(h.sent_at || "").slice(0, 16).replace("T", " ")}</div>
                <div className="text-xs text-muted-foreground mt-1">
                  To: {h.to_suppliers.map(s => s.name).join(", ")}
                </div>
                <div className="text-[10px] mt-1">
                  <span className={`px-1.5 py-0.5 rounded font-semibold ${
                    h.status === "sent" ? "bg-emerald-100 text-emerald-800" :
                    h.status === "failed" ? "bg-red-100 text-red-800" :
                    "bg-amber-100 text-amber-800"
                  }`}>
                    {h.status === "sent" ? `SENT via ${h.provider || "resend"}` :
                     h.status === "failed" ? "FAILED" : "SIMULATED"}
                  </span>
                  {h.message_id && <span className="ml-2 text-muted-foreground font-mono">{h.message_id.slice(0, 12)}…</span>}
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <SupplierTracker leadId={lead.lead_id} leadPhone={lead.phone} />

      <LeadNotes leadId={lead.lead_id} />

      <ContactTimeline leadId={lead.lead_id} refreshKey={timelineKey} />

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
            <Button onClick={doReassign} style={{ background: "#106EBE" }} data-testid="reassign-save">Reassign</Button>
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
                    <Checkbox checked={!!selected[s.id]} onCheckedChange={(v) => setSelected(p => ({ ...p, [s.id]: v }))} />
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
              <Label className="label-caps">Intro (optional — replaces the default line)</Label>
              <Textarea rows={3} className="mt-1.5" value={body} onChange={(e) => setBody(e.target.value)} data-testid="mail-body"
                placeholder="Leave blank to use default: 'Please share your best quotation for the travel requirement detailed below.'" />
              <div className="text-[11px] text-muted-foreground mt-1">Trip / accommodation / guest tables are auto-generated from lead data in the branded HTML template.</div>
            </div>
            <div className="border border-border rounded-md">
              <div className="px-3 py-2 border-b border-border bg-secondary/40 flex items-center justify-between">
                <span className="text-sm font-semibold">📧 Email preview — this is exactly what suppliers receive</span>
                <span className="label-caps">live · updates as you type</span>
              </div>
              <div className="p-2 bg-slate-50">
                <iframe
                  title="mail-preview"
                  className="w-full h-[600px] border border-border rounded-md bg-white"
                  srcDoc={mailPreviewHtml}
                  data-testid="mail-preview-iframe"
                />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setMailOpen(false)}>Cancel</Button>
            <Button onClick={send} style={{ background: "#106EBE" }} data-testid="mail-send">Send Enquiry</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* AI Dialog */}
      <Dialog open={aiOpen} onOpenChange={setAiOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto" data-testid="ai-dialog">
          <DialogHeader><DialogTitle>AI Assistant</DialogTitle></DialogHeader>
          {aiBusy && <div className="text-sm text-muted-foreground">Thinking with Claude Sonnet 4.5…</div>}
          {!aiBusy && aiContent && (
            <div className="whitespace-pre-wrap text-sm leading-relaxed">{aiContent}</div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}

function KV({ label, value, full }) {
  return (
    <div className={full ? "col-span-2 md:col-span-3" : ""}>
      <div className="label-caps">{label}</div>
      <div className="font-medium mt-0.5">{value}</div>
    </div>
  );
}

function defaultEmailBody(lead) {
  return `INDIAN ROUNDS
TRAVEL ENQUIRY

Dear Team,

Greetings from Indian Rounds!

Please share your best quotation for the travel requirement below.

TRIP DETAILS
Lead ID       : ${lead.lead_id}
Destination   : ${lead.destination}
Travel Date   : ${(lead.travel_date || "").slice(0, 10) || "TBD"}
Package Type  : ${lead.package_type || "N/A"}

ACCOMMODATION
Hotel Category: ${lead.hotel_category || "N/A"}
Meal Plan     : ${lead.meal_plan || "N/A"}
Rooms         : ${lead.rooms}
Room Type     : ${lead.room_type || "N/A"}

GUEST DETAILS
Adults        : ${lead.adults}
Children      : ${lead.children}
Child Age     : ${lead.child_ages || "Nil"}

SPECIAL REQUESTS
${lead.special_requests || "Nil"}

We look forward to receiving your best rates.

Regards,
Indian Rounds`;
}
