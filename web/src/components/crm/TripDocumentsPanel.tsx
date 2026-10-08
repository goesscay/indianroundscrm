"use client";

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { AlertTriangle, Check, Download, FileText, Loader2, Trash2, Upload, UserPlus, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import { fmtDate } from "./shared";
import { ProgressBar } from "./tripShared";

interface Traveller { id: string; name: string; relation: string | null; gender: string | null; dob: string | null; nationality: string | null; is_child: boolean; selected: boolean; passport_no: string | null; passport_expiry: string | null; passport_validity: "ok" | "short" | "expired" | "unknown" | null }
interface Slot {
  id: string; traveller_id: string | null; doc_type: string; label: string; mandatory: boolean; tracks_expiry: boolean; status: "pending" | "received" | "verified" | "rejected" | "na";
  expiry_date: string | null; notes: string | null; verified_by: string | null; verified_at: string | null; files: { id: string; name: string; size: number; mime: string }[];
}
interface Docs { international: boolean; trip_end: string | null; travellers: Traveller[]; slots: Slot[]; summary: { required: number; received: number; verified: number; complete: boolean; all_verified: boolean; missing: number; travellers: number } }

const STATUS: Record<Slot["status"], { label: string; cls: string }> = {
  pending: { label: "Missing", cls: "bg-secondary text-muted-foreground" },
  received: { label: "Received", cls: "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300" },
  verified: { label: "Verified", cls: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300" },
  rejected: { label: "Send again", cls: "bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300" },
  na: { label: "Not needed", cls: "bg-secondary text-muted-foreground" },
};
const kb = (n: number) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

/** Who is travelling and the documents each person (and the trip) must give. Shown under the trip plan once the trip is confirmed. */
export default function TripDocumentsPanel({ dealId, onChanged }: { dealId: string; onChanged?: () => void }) {
  const { can } = useCurrentUser();
  const canUpload = can("trip_docs.upload");
  const canOpen = can("trip_docs.download");
  const canManage = can("trip_docs.manage");
  const [d, setD] = useState<Docs | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);
  const [nt, setNt] = useState({ name: "", relation: "", dob: "", gender: "", nationality: "India", passport_no: "", passport_expiry: "" });
  const fileRef = useRef<HTMLInputElement>(null);
  const target = useRef<string | null>(null);

  const load = useCallback(async () => {
    try { const { data } = await api.get<Docs>(`/deals/${dealId}/trip/documents`); setD(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [dealId]);
  useEffect(() => { void load(); }, [load]);

  const run = async (key: string, fn: () => Promise<Docs>) => {
    setBusy(key);
    try { setD(await fn()); onChanged?.(); } catch (e) { toast.error(formatApiError(e)); } finally { setBusy(null); }
  };

  const toggle = (t: Traveller) => {
    if (!d || !canUpload) return;
    const ids = d.travellers.filter((x) => (x.id === t.id ? !x.selected : x.selected)).map((x) => x.id);
    void run(`trav-${t.id}`, async () => (await api.put<Docs>(`/deals/${dealId}/trip/travellers`, { traveller_ids: ids })).data);
  };
  const pickFile = (slotId: string) => { target.current = slotId; fileRef.current?.click(); };
  const onFile = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0]; const id = target.current; e.target.value = "";
    if (!f || !id) return;
    const fd = new FormData(); fd.append("file", f);
    void run(`up-${id}`, async () => (await api.post<Docs>(`/trip-documents/${id}/files`, fd)).data);
  };
  const setStatus = (s: Slot, status: string) => run(`st-${s.id}`, async () => (await api.patch<Docs>(`/trip-documents/${s.id}`, { status })).data);
  const addTraveller = (e: FormEvent) => {
    e.preventDefault();
    if (!nt.name.trim()) return;
    const body: Record<string, string> = {};
    Object.entries(nt).forEach(([k, v]) => { if (v.trim()) body[k] = v.trim(); });
    void run("add", async () => { const r = (await api.post<Docs>(`/deals/${dealId}/trip/travellers`, body)).data; setNt({ name: "", relation: "", dob: "", gender: "", nationality: "India", passport_no: "", passport_expiry: "" }); setAdding(false); return r; });
  };

  const selected = useMemo(() => d?.travellers.filter((t) => t.selected) ?? [], [d]);
  if (error) return <Card className="mt-4 rounded-lg p-5 text-sm text-destructive">{error}</Card>;
  if (!d) return <Card className="mt-4 rounded-lg p-5 text-sm text-muted-foreground"><Loader2 size={14} className="mr-2 inline animate-spin" />Loading documents…</Card>;

  const s = d.summary;
  const warnings = selected.filter((t) => t.passport_validity === "short" || t.passport_validity === "expired");
  const row = (slot: Slot) => (
    <li key={slot.id} className="p-2.5 text-sm" data-testid={`doc-slot-${slot.id}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <span className="font-medium">{slot.label}</span>{slot.mandatory && <span className="ml-1 text-destructive" title="Required">*</span>}
          {slot.notes && <div className="text-xs italic text-muted-foreground">“{slot.notes}”</div>}
          {slot.status === "verified" && slot.verified_by && <div className="text-xs text-muted-foreground">Verified by {slot.verified_by}</div>}
        </div>
        <span className={cn("rounded px-2 py-0.5 text-xs font-semibold", STATUS[slot.status].cls)}>{STATUS[slot.status].label}</span>
      </div>
      {slot.files.length > 0 && (
        <ul className="mt-1.5 space-y-1">
          {slot.files.map((f) => (
            <li key={f.id} className="flex items-center gap-2 text-xs">
              <FileText size={12} className="shrink-0 text-muted-foreground" />
              {canOpen ? <a className="truncate underline" href={`/api/trip-document-files/${f.id}/download`} target="_blank" rel="noreferrer">{f.name}</a> : <span className="truncate">{f.name}</span>}
              <span className="text-muted-foreground">{kb(f.size)}</span>
              {canManage && <button type="button" aria-label={`Delete ${f.name}`} className="text-muted-foreground hover:text-destructive" onClick={() => window.confirm(`Delete ${f.name}?`) && run(`del-${f.id}`, async () => (await api.delete<Docs>(`/trip-document-files/${f.id}`)).data)}><Trash2 size={12} /></button>}
            </li>
          ))}
        </ul>
      )}
      <div className="mt-1.5 flex flex-wrap items-center gap-2">
        {canUpload && slot.status !== "na" && (
          <Button type="button" size="sm" variant="outline" className="h-7" disabled={busy === `up-${slot.id}`} onClick={() => pickFile(slot.id)} data-testid={`doc-upload-${slot.id}`}>
            {busy === `up-${slot.id}` ? <Loader2 size={12} className="mr-1 animate-spin" /> : <Upload size={12} className="mr-1" />}{slot.files.length ? "Add another" : "Upload"}
          </Button>
        )}
        {canManage && slot.files.length > 0 && slot.status !== "verified" && <Button type="button" size="sm" className="h-7" onClick={() => setStatus(slot, "verified")} data-testid={`doc-verify-${slot.id}`}><Check size={12} className="mr-1" />Verify</Button>}
        {canManage && slot.files.length > 0 && slot.status !== "rejected" && <Button type="button" size="sm" variant="ghost" className="h-7" onClick={() => setStatus(slot, "rejected")}><X size={12} className="mr-1" />Ask again</Button>}
        {canManage && slot.status !== "na" && !slot.files.length && <Button type="button" size="sm" variant="ghost" className="h-7" onClick={() => setStatus(slot, "na")}>Not needed</Button>}
        {canManage && slot.status === "na" && <Button type="button" size="sm" variant="ghost" className="h-7" onClick={() => setStatus(slot, "pending")}>Needed after all</Button>}
        {slot.tracks_expiry && slot.status !== "na" && (
          <label className="flex items-center gap-1 text-xs text-muted-foreground">Expires
            <Input type="date" className="h-7 w-36 px-2 text-xs" defaultValue={slot.expiry_date ?? ""} disabled={!canManage}
                   onBlur={(e) => e.target.value !== (slot.expiry_date ?? "") && run(`ex-${slot.id}`, async () => (await api.patch<Docs>(`/trip-documents/${slot.id}`, { expiry_date: e.target.value || null })).data)} />
          </label>
        )}
      </div>
    </li>
  );

  return (
    <Card className="mt-4 rounded-lg p-5" data-testid="trip-documents">
      <input ref={fileRef} type="file" accept="application/pdf,image/jpeg,image/png,image/webp" className="hidden" onChange={onFile} data-testid="doc-file-input" />
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="flex items-center gap-2 font-display text-lg font-bold"><FileText size={16} className="text-muted-foreground" /> Customer documents</h3>
        {canOpen && selected.length > 0 && <a href={`/api/deals/${dealId}/trip/manifest`}><Button variant="outline" size="sm" data-testid="doc-manifest"><Download size={13} className="mr-1" />Traveller manifest (CSV)</Button></a>}
      </div>

      {s.travellers > 0 ? (
        <div className="mb-3" data-testid="doc-summary">
          <div className="mb-1 flex items-center justify-between text-sm"><span className="font-semibold">{s.received} of {s.required} required documents received</span><span className="text-xs text-muted-foreground">{s.verified} verified{s.missing > 0 ? ` · ${s.missing} missing` : ""}</span></div>
          <ProgressBar percent={s.required ? Math.floor((s.received / s.required) * 100) : 0} />
        </div>
      ) : <p className="mb-3 text-sm text-muted-foreground">Choose who is travelling below. The documents each person must give appear here.</p>}

      {warnings.map((t) => (
        <div key={t.id} className="mb-2 flex items-start gap-2 rounded-md border border-red-300 bg-red-50 p-2 text-xs text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" role="alert" data-testid={`passport-warning-${t.id}`}>
          <AlertTriangle size={13} className="mt-0.5 shrink-0" />
          <span><b>{t.name}</b>: passport {t.passport_validity === "expired" ? "expires before the trip ends" : "is valid for less than 6 months after the return"} ({t.passport_expiry ? fmtDate(t.passport_expiry) : "—"}{d.trip_end ? `, trip ends ${fmtDate(d.trip_end)}` : ""}). Many countries refuse entry. Ask for a renewal.</span>
        </div>
      ))}

      <div className="mb-4">
        <div className="label-caps mb-1">Who is travelling</div>
        <div className="flex flex-wrap gap-2">
          {d.travellers.map((t) => (
            <label key={t.id} className={cn("flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-1.5 text-sm", t.selected ? "border-foreground" : "border-border text-muted-foreground", !canUpload && "cursor-default")}>
              <input type="checkbox" className="accent-black dark:accent-white" checked={t.selected} disabled={!canUpload || !!busy?.startsWith("trav-")} onChange={() => toggle(t)} data-testid={`trav-${t.id}`} />
              {t.name}{t.is_child && <span className="rounded bg-secondary px-1 text-[10px] uppercase">Child</span>}
              {d.international && t.passport_validity === "ok" && <span className="text-xs text-emerald-700 dark:text-emerald-400">passport ok</span>}
              {d.international && t.passport_validity === "unknown" && <span className="text-xs text-amber-700 dark:text-amber-300">no passport date</span>}
            </label>
          ))}
          {canUpload && !adding && <Button type="button" variant="outline" size="sm" onClick={() => setAdding(true)} data-testid="trav-add-btn"><UserPlus size={13} className="mr-1" />Add traveller</Button>}
        </div>
        {adding && (
          <form onSubmit={addTraveller} className="mt-2 grid gap-2 rounded-md border border-border p-3 sm:grid-cols-3" data-testid="trav-add-form">
            <div className="sm:col-span-2"><Label className="label-caps">Name as on ID *</Label><Input className="mt-1 h-8" value={nt.name} onChange={(e) => setNt({ ...nt, name: e.target.value })} autoFocus /></div>
            <div><Label className="label-caps">Relation</Label><Input className="mt-1 h-8" value={nt.relation} onChange={(e) => setNt({ ...nt, relation: e.target.value })} placeholder="Spouse, child…" /></div>
            <div><Label className="label-caps">Date of birth</Label><Input type="date" className="mt-1 h-8" value={nt.dob} onChange={(e) => setNt({ ...nt, dob: e.target.value })} /></div>
            <div><Label className="label-caps">Gender</Label><Input className="mt-1 h-8" value={nt.gender} onChange={(e) => setNt({ ...nt, gender: e.target.value })} /></div>
            <div><Label className="label-caps">Nationality</Label><Input className="mt-1 h-8" value={nt.nationality} onChange={(e) => setNt({ ...nt, nationality: e.target.value })} /></div>
            {canManage && <><div><Label className="label-caps">Passport number</Label><Input className="mt-1 h-8" value={nt.passport_no} onChange={(e) => setNt({ ...nt, passport_no: e.target.value })} /></div>
              <div><Label className="label-caps">Passport expiry</Label><Input type="date" className="mt-1 h-8" value={nt.passport_expiry} onChange={(e) => setNt({ ...nt, passport_expiry: e.target.value })} /></div></>}
            <div className="flex items-end gap-2 sm:col-span-3"><Button type="submit" size="sm" disabled={busy === "add"}>Add to trip</Button><Button type="button" size="sm" variant="ghost" onClick={() => setAdding(false)}>Cancel</Button></div>
          </form>
        )}
      </div>

      <div className="space-y-4">
        {selected.map((t) => (
          <section key={t.id} data-testid={`doc-traveller-${t.id}`}>
            <h4 className="mb-1 text-sm font-semibold">{t.name}{t.is_child ? " (child)" : ""}</h4>
            <ul className="divide-y divide-border rounded-md border border-border">{d.slots.filter((x) => x.traveller_id === t.id).map(row)}</ul>
          </section>
        ))}
        {d.slots.some((x) => x.traveller_id === null) && (
          <section>
            <h4 className="mb-1 text-sm font-semibold">For the whole trip</h4>
            <ul className="divide-y divide-border rounded-md border border-border">{d.slots.filter((x) => x.traveller_id === null).map(row)}</ul>
          </section>
        )}
      </div>
    </Card>
  );
}
