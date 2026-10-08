"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import { CheckCircle2, Loader2, ShieldCheck, Upload } from "lucide-react";
import { api, apiErrorStatus, formatApiError } from "@/lib/api";

interface Slot { id: string; traveller_id: string | null; label: string; mandatory: boolean; status: "pending" | "received" | "verified" | "rejected" | "na"; files: number; note: string | null; can_upload: boolean }
interface Page {
  company: { name: string; phone: string; email: string }; customer_name: string | null;
  trip: { name: string; destination: string | null; travel_date: string | null }; expires_at: string;
  travellers: { id: string; name: string; is_child: boolean }[]; slots: Slot[];
}

const dateText = (d: string | null) => (d ? new Date(d).toLocaleDateString(undefined, { day: "numeric", month: "long", year: "numeric" }) : "");

/** The page a customer opens from the link we send: upload the documents we asked for. No login. */
export default function CustomerUpload() {
  const { token } = useParams<{ token: string }>();
  const [p, setP] = useState<Page | null>(null);
  const [error, setError] = useState("");
  const [gone, setGone] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState("");
  const fileRef = useRef<HTMLInputElement>(null);
  const target = useRef<string | null>(null);

  const load = useCallback(async () => {
    try { const { data } = await api.get<Page>(`/public/trip-upload/${token}`); setP(data); setError(""); }
    catch (e) { if (apiErrorStatus(e) === 410) setGone(true); else setError(formatApiError(e)); }
  }, [token]);
  useEffect(() => { void load(); }, [load]);

  const pick = (id: string) => { target.current = id; fileRef.current?.click(); };
  const onFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0]; const id = target.current; e.target.value = "";
    if (!f || !id) return;
    if (f.size > 10 * 1024 * 1024) { setMsg("That file is larger than 10 MB. Please send a smaller one."); return; }
    setBusy(id); setMsg("");
    try {
      const fd = new FormData(); fd.append("file", f);
      const { data } = await api.post<Page>(`/public/trip-upload/${token}/slots/${id}/files`, fd);
      setP(data); setMsg("Thank you, we have received it.");
    } catch (err) { setMsg(formatApiError(err)); }
    finally { setBusy(null); }
  };

  const row = (s: Slot) => (
    <li key={s.id} className="flex items-center justify-between gap-3 p-3" data-testid={`up-slot-${s.id}`}>
      <div className="min-w-0">
        <div className="text-sm font-medium">{s.label}{s.mandatory && <span className="text-red-600"> *</span>}</div>
        {s.status === "rejected" && <div className="text-xs text-red-700">{s.note}</div>}
        {s.status === "received" && <div className="flex items-center gap-1 text-xs text-emerald-700"><CheckCircle2 size={12} /> Received{s.files > 1 ? ` (${s.files} files)` : ""}</div>}
        {s.status === "verified" && <div className="flex items-center gap-1 text-xs text-emerald-700"><ShieldCheck size={12} /> Checked, thank you</div>}
        {s.status === "na" && <div className="text-xs text-neutral-500">Not needed</div>}
      </div>
      {s.can_upload && (
        <button type="button" onClick={() => pick(s.id)} disabled={busy === s.id}
                className="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-neutral-300 px-3 py-2 text-sm font-medium hover:bg-neutral-50 disabled:opacity-60" data-testid={`up-btn-${s.id}`}>
          {busy === s.id ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />}{s.status === "pending" || s.status === "rejected" ? "Upload" : "Add another"}
        </button>
      )}
    </li>
  );

  return (
    <div className="min-h-[100dvh] bg-neutral-100 px-4 py-6 text-neutral-900 sm:py-10">
      <input ref={fileRef} type="file" accept="application/pdf,image/jpeg,image/png,image/webp" className="hidden" onChange={onFile} data-testid="up-file-input" />
      <div className="mx-auto max-w-xl">
        <div className="mb-5 flex items-center gap-3">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/icons/icon-192.png" alt="" className="h-11 w-11 rounded-xl" />
          <div className="text-lg font-bold">{p?.company.name ?? "Indian Rounds"}</div>
        </div>

        {gone && <div className="rounded-xl bg-white p-6 text-center shadow-sm" role="alert" data-testid="up-gone"><h1 className="text-lg font-bold">This link is no longer valid</h1><p className="mt-2 text-sm text-neutral-600">It may have expired or been replaced. Please ask us for a new one.</p></div>}
        {error && <div className="rounded-xl bg-white p-6 text-sm text-red-700 shadow-sm" role="alert">{error}</div>}
        {!p && !gone && !error && <div className="p-8 text-center text-sm text-neutral-500"><Loader2 size={18} className="mx-auto mb-2 animate-spin" />Loading…</div>}

        {p && (
          <div className="space-y-4" data-testid="up-page">
            <div className="rounded-xl bg-white p-5 shadow-sm">
              <h1 className="text-xl font-bold">Hello{p.customer_name ? `, ${p.customer_name.split(" ")[0]}` : ""}</h1>
              <p className="mt-1 text-sm text-neutral-600">Please upload the documents below for your <b>{p.trip.destination || p.trip.name}</b> trip{p.trip.travel_date ? ` on ${dateText(p.trip.travel_date)}` : ""}. Photos or scans are fine (PDF, JPG or PNG, up to 10 MB each).</p>
              <p className="mt-2 flex items-start gap-1.5 text-xs text-neutral-500"><ShieldCheck size={13} className="mt-0.5 shrink-0" />Your documents are stored securely and used only for this trip. This link is private to you.</p>
            </div>
            {msg && <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800" role="status" data-testid="up-msg">{msg}</div>}
            {p.travellers.map((t) => (
              <section key={t.id} className="rounded-xl bg-white shadow-sm">
                <h2 className="border-b border-neutral-200 px-3 py-2.5 text-sm font-semibold">{t.name}{t.is_child ? " (child)" : ""}</h2>
                <ul className="divide-y divide-neutral-200">{p.slots.filter((s) => s.traveller_id === t.id).map(row)}</ul>
              </section>
            ))}
            {p.slots.some((s) => s.traveller_id === null) && (
              <section className="rounded-xl bg-white shadow-sm">
                <h2 className="border-b border-neutral-200 px-3 py-2.5 text-sm font-semibold">For the whole trip</h2>
                <ul className="divide-y divide-neutral-200">{p.slots.filter((s) => s.traveller_id === null).map(row)}</ul>
              </section>
            )}
            <p className="px-1 text-center text-xs text-neutral-500">Questions? {p.company.phone && <>Call {p.company.phone}</>}{p.company.phone && p.company.email ? " or " : ""}{p.company.email && <>write to {p.company.email}</>}.</p>
          </div>
        )}
      </div>
    </div>
  );
}
