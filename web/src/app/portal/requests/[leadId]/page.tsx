"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { ArrowLeft, Download, FileText, Loader2, Paperclip, X } from "lucide-react";
import { toast } from "sonner";
import { api, apiErrorStatus, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import PortalShell from "@/components/portal/PortalShell";
import { StateBadge, dateText, formatBytes, money, timeText, type PortalQuote, type PortalRequest } from "@/components/portal/shared";

const CURRENCIES = ["INR", "USD", "EUR", "GBP", "AED", "SGD", "THB", "LKR", "NPR", "IDR", "VND", "MYR"];
const SELECT = "mt-1.5 h-10 w-full rounded-md border border-input bg-background px-3 text-sm";

const yesNo = (v: boolean | null) => (v === null ? "" : v ? "Yes" : "No");

function Row({ k, v }: { k: string; v: React.ReactNode }) {
  if (v === null || v === undefined || v === "") return null;
  return <div className="flex justify-between gap-4 py-1.5 text-sm"><dt className="shrink-0 text-neutral-500">{k}</dt><dd className="text-right font-medium">{v}</dd></div>;
}

function Requirement({ r }: { r: PortalRequest["requirement"] }) {
  const guests = [r.adults != null ? `${r.adults} adult${Number(r.adults) === 1 ? "" : "s"}` : null, r.children ? `${r.children} child${Number(r.children) === 1 ? "" : "ren"}${r.child_ages ? ` (ages ${r.child_ages})` : ""}` : null].filter(Boolean).join(", ");
  return (
    <section className="rounded-xl bg-white p-5 shadow-sm" data-testid="portal-requirement">
      <h2 className="text-base font-bold">The requirement</h2>
      <dl className="mt-2 divide-y divide-neutral-100">
        <Row k="Destination" v={r.destination} />
        <Row k="Travel date" v={dateText(r.travel_date)} />
        <Row k="Trip end date" v={dateText(r.trip_end_date)} />
        <Row k="Duration" v={r.duration} />
        <Row k="Package type" v={r.package_type} />
        {r.stay_cities.length > 0 && <Row k="Stay" v={r.stay_cities.map((c) => `${c.city}${c.nights ? ` ${c.nights}N` : ""}`).join(" · ")} />}
        <Row k="Hotel category" v={r.hotel_category} />
        <Row k="Meal plan" v={r.meal_plan} />
        <Row k="Rooms" v={[r.rooms, r.room_type].filter((x) => x !== null && x !== "").join(" · ")} />
        <Row k="Guests" v={guests} />
        <Row k="Passports available" v={r.passport ? `${r.passport}${r.passport_reason ? ` (${r.passport_reason})` : ""}` : null} />
      </dl>
      {r.special_requests && <div className="mt-3 rounded-md bg-neutral-50 p-3 text-sm"><div className="text-xs font-semibold uppercase tracking-wide text-neutral-500">Special requests</div><div className="mt-1 whitespace-pre-wrap">{r.special_requests}</div></div>}
    </section>
  );
}

function QuoteCard({ q }: { q: PortalQuote }) {
  return (
    <li className="rounded-lg border border-neutral-200 p-3 text-sm" data-testid={`portal-quote-v${q.version}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-semibold">Version {q.version}{q.status === "superseded" ? " (replaced)" : ""}</span>
        <span className="text-xs text-neutral-500">{timeText(q.at)}</span>
      </div>
      {q.status === "declined" ? (
        <div className="mt-1 text-neutral-700">You told us you cannot quote: {q.decline_reason}</div>
      ) : (
        <dl className="mt-1 divide-y divide-neutral-100">
          <Row k="Total" v={money(q.amount, q.currency)} />
          <Row k="Hotel" v={q.hotel_offered} />
          <Row k="Flights included" v={yesNo(q.flight_included)} />
          <Row k="Transfers included" v={yesNo(q.transfer_included)} />
          <Row k="Activities" v={q.activities_included} />
          <Row k="Valid until" v={dateText(q.valid_until)} />
          <Row k="Remarks" v={q.remarks} />
        </dl>
      )}
      {q.files.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {q.files.map((f) => (
            <a key={f.id} href={`/api/portal/files/${f.id}`} download className="inline-flex max-w-full items-center gap-1.5 rounded-md border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-50">
              <FileText size={13} aria-hidden /><span className="truncate">{f.filename}</span><span className="text-neutral-500">{formatBytes(f.size)}</span><Download size={12} aria-hidden />
            </a>
          ))}
        </div>
      )}
    </li>
  );
}

function QuoteForm({ leadId, last, revising, onSaved }: { leadId: string; last: PortalQuote | null; revising: boolean; onSaved: (r: PortalRequest) => void }) {
  const [amount, setAmount] = useState(last?.amount != null ? String(last.amount) : "");
  const [currency, setCurrency] = useState(last?.currency || "INR");
  const [hotel, setHotel] = useState(last?.hotel_offered || "");
  const [flight, setFlight] = useState(last?.flight_included == null ? "" : last.flight_included ? "yes" : "no");
  const [transfer, setTransfer] = useState(last?.transfer_included == null ? "" : last.transfer_included ? "yes" : "no");
  const [activities, setActivities] = useState(last?.activities_included || "");
  const [validUntil, setValidUntil] = useState(last?.valid_until || "");
  const [remarks, setRemarks] = useState(last?.remarks || "");
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState("");
  const input = useRef<HTMLInputElement>(null);

  const addFiles = (list: FileList | null) => {
    if (!list) return;
    const next = [...files, ...Array.from(list)].slice(0, 5);
    setFiles(next);
    if (input.current) input.current.value = "";
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError("");
    if (!amount && files.length === 0) { setError("Enter the total price, or attach your quotation."); return; }
    setBusy(true);
    try {
      const fd = new FormData();
      if (amount) { fd.append("amount", amount); fd.append("currency", currency); }
      if (hotel) fd.append("hotel_offered", hotel);
      if (flight) fd.append("flight_included", flight === "yes" ? "1" : "0");
      if (transfer) fd.append("transfer_included", transfer === "yes" ? "1" : "0");
      if (activities) fd.append("activities_included", activities);
      if (validUntil) fd.append("valid_until", validUntil);
      if (remarks) fd.append("remarks", remarks);
      files.forEach((f) => fd.append("files[]", f));
      const { data } = await api.post<{ request: PortalRequest }>(`/portal/requests/${encodeURIComponent(leadId)}/quote`, fd);
      toast.success("Thank you, your quote has been sent to us.");
      setFiles([]);
      onSaved(data.request);
    } catch (err) { setError(formatApiError(err)); }
    finally { setBusy(false); }
  };

  const decline = async () => {
    setBusy(true); setError("");
    try {
      const { data } = await api.post<{ request: PortalRequest }>(`/portal/requests/${encodeURIComponent(leadId)}/decline`, { reason });
      toast.success("Thank you for letting us know.");
      setDeclining(false); setReason("");
      onSaved(data.request);
    } catch (err) { setError(formatApiError(err)); }
    finally { setBusy(false); }
  };

  return (
    <form onSubmit={submit} className="space-y-4 rounded-xl bg-white p-5 shadow-sm" data-testid="portal-quote-form">
      <div>
        <h2 className="text-base font-bold">{revising ? "Send a revised quote" : "Your quote"}</h2>
        <p className="mt-1 text-sm text-neutral-600">Type the details, attach your own quotation, or do both. Anything you leave empty is simply not shown to us.</p>
      </div>

      <div className="grid grid-cols-3 gap-3">
        <div className="col-span-2">
          <Label htmlFor="q-amount">Total price</Label>
          <Input id="q-amount" type="number" inputMode="decimal" min="0" step="any" className="mt-1.5" value={amount} onChange={(e) => setAmount(e.target.value)} data-testid="q-amount" />
        </div>
        <div>
          <Label htmlFor="q-currency">Currency</Label>
          <select id="q-currency" className={SELECT} value={currency} onChange={(e) => setCurrency(e.target.value)} data-testid="q-currency">
            {CURRENCIES.map((c) => <option key={c}>{c}</option>)}
          </select>
        </div>
      </div>

      <div>
        <Label htmlFor="q-hotel">Hotel offered</Label>
        <Input id="q-hotel" className="mt-1.5" placeholder="Name and star category" value={hotel} onChange={(e) => setHotel(e.target.value)} data-testid="q-hotel" />
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label htmlFor="q-flight">Flights included</Label>
          <select id="q-flight" className={SELECT} value={flight} onChange={(e) => setFlight(e.target.value)}><option value="">Not specified</option><option value="yes">Yes</option><option value="no">No</option></select>
        </div>
        <div>
          <Label htmlFor="q-transfer">Transfers included</Label>
          <select id="q-transfer" className={SELECT} value={transfer} onChange={(e) => setTransfer(e.target.value)}><option value="">Not specified</option><option value="yes">Yes</option><option value="no">No</option></select>
        </div>
      </div>

      <div>
        <Label htmlFor="q-activities">Activities and sightseeing included</Label>
        <Input id="q-activities" className="mt-1.5" value={activities} onChange={(e) => setActivities(e.target.value)} />
      </div>

      <div>
        <Label htmlFor="q-valid">Quote valid until</Label>
        <Input id="q-valid" type="date" className="mt-1.5" min={new Date().toISOString().slice(0, 10)} value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
      </div>

      <div>
        <Label htmlFor="q-remarks">Remarks and conditions</Label>
        <Textarea id="q-remarks" className="mt-1.5" rows={4} value={remarks} onChange={(e) => setRemarks(e.target.value)} data-testid="q-remarks" />
      </div>

      <div>
        <Label>Your quotation file</Label>
        <p className="text-xs text-neutral-500">PDF, Word, Excel, image or zip. Up to 5 files, 15 MB each.</p>
        <input ref={input} type="file" multiple className="hidden" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.gif,.webp,.zip" onChange={(e) => addFiles(e.target.files)} data-testid="q-file-input" />
        <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => input.current?.click()} disabled={files.length >= 5}><Paperclip size={14} /> Add file</Button>
        {files.length > 0 && (
          <ul className="mt-2 space-y-1">
            {files.map((f, i) => (
              <li key={`${f.name}-${i}`} className="flex items-center justify-between gap-2 rounded-md bg-neutral-50 px-2 py-1.5 text-sm">
                <span className="truncate">{f.name} <span className="text-xs text-neutral-500">{formatBytes(f.size)}</span></span>
                <button type="button" aria-label={`Remove ${f.name}`} onClick={() => setFiles(files.filter((_, j) => j !== i))}><X size={14} /></button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {error && <div className="rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert" data-testid="q-error">{error}</div>}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <Button type="submit" disabled={busy} data-testid="q-submit">{busy ? <><Loader2 size={14} className="animate-spin" /> Sending…</> : revising ? "Send revised quote" : "Send quote"}</Button>
        <button type="button" className="text-sm text-neutral-600 underline underline-offset-2" onClick={() => setDeclining((d) => !d)} data-testid="q-decline-toggle">We cannot quote this</button>
      </div>

      {declining && (
        <div className="space-y-2 rounded-lg border border-neutral-200 p-3" data-testid="q-decline-box">
          <Label htmlFor="q-reason">Why can you not quote?</Label>
          <Textarea id="q-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="For example: no availability on those dates" />
          <Button type="button" variant="outline" size="sm" disabled={busy || reason.trim().length < 3} onClick={decline} data-testid="q-decline">Tell Indian Rounds</Button>
        </div>
      )}
    </form>
  );
}

function RequestDetail({ leadId }: { leadId: string }) {
  const [req, setReq] = useState<PortalRequest | null>(null);
  const [error, setError] = useState("");
  const [missing, setMissing] = useState(false);

  const load = useCallback(async () => {
    try { const { data } = await api.get<PortalRequest>(`/portal/requests/${encodeURIComponent(leadId)}`); setReq(data); }
    catch (e) { if (apiErrorStatus(e) === 404) setMissing(true); else setError(formatApiError(e)); }
  }, [leadId]);
  useEffect(() => { void load(); }, [load]);

  const back = <Link href="/portal" className="inline-flex items-center gap-1 text-sm text-neutral-600 hover:text-neutral-900"><ArrowLeft size={14} /> All enquiries</Link>;

  if (missing) return <div className="space-y-4">{back}<div className="rounded-xl bg-white p-6 text-center shadow-sm" role="alert"><h1 className="text-lg font-bold">We could not find this enquiry</h1><p className="mt-2 text-sm text-neutral-600">It may be meant for another e-mail address. Go back to your list of enquiries.</p></div></div>;
  if (error) return <div className="space-y-4">{back}<div className="rounded-xl bg-white p-6 text-sm text-red-700 shadow-sm" role="alert">{error}</div></div>;
  if (!req) return <div className="p-8 text-center text-sm text-neutral-500"><Loader2 className="mx-auto mb-2 animate-spin" size={18} />Loading…</div>;

  const history = req.quotes;
  const last = history.find((q) => q.status === "submitted") ?? null;
  const closed = req.state === "closed";

  return (
    <div className="space-y-4" data-testid="portal-request">
      {back}
      <div className="flex flex-wrap items-center gap-2">
        <h1 className="text-2xl font-bold">{req.requirement.destination || "Trip enquiry"}</h1>
        <span className="font-mono text-sm text-neutral-500">{req.lead_id}</span>
        <StateBadge state={req.state} />
      </div>
      <Requirement r={req.requirement} />
      {closed ? (
        <div className="rounded-xl bg-white p-5 text-sm shadow-sm" data-testid="portal-closed">This enquiry is closed. Please write to us if you need to change your quote.</div>
      ) : (
        <QuoteForm key={`${history.length}-${last?.id ?? "new"}`} leadId={req.lead_id} last={last} revising={history.length > 0} onSaved={setReq} />
      )}
      {history.length > 0 && (
        <section className="rounded-xl bg-white p-5 shadow-sm">
          <h2 className="text-base font-bold">What you sent us</h2>
          <ul className="mt-3 space-y-3">{history.map((q) => <QuoteCard key={q.id} q={q} />)}</ul>
        </section>
      )}
    </div>
  );
}

export default function PortalRequestPage() {
  const { leadId } = useParams<{ leadId: string }>();
  return <PortalShell>{() => <RequestDetail leadId={decodeURIComponent(leadId)} />}</PortalShell>;
}
