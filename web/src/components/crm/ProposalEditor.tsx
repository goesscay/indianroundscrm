"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { ArrowLeft, BookmarkPlus, ImageIcon, Loader2, Plus, Search, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { INR } from "@/components/crm/shared";
import type { CustomerBrief, Customer, Hotel, MealPlan, MediaItem, ProposalComputed, ProposalData, ProposalDay, ProposalStay, Quote, QuoteTemplateBrief } from "@/types";

const MEAL_LABEL: Record<MealPlan, string> = { EP: "Room only", CP: "Breakfast", MAP: "Breakfast and dinner", AP: "All meals" };
const NONE = "none";

const lines = (v: string) => v.split("\n");

interface SeedResult { proposal: ProposalData; slots: { name: string; stays: ProposalStay[] }[] | null; title: string; customer_id: string | null; deal_id: string | null }
/** a template brings its hotel stays as empty slots (nights, meal plan, a star hint) for the salesperson to fill */
const withSlots = (d: SeedResult): ProposalData => (d.slots && d.slots.length ? { ...d.proposal, options: d.slots, selected_option: 0 } : d.proposal);

function Section({ title, hint, children, right }: { title: string; hint?: string; children: React.ReactNode; right?: React.ReactNode }) {
  return (
    <Card className="p-5">
      <div className="mb-4 flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="font-display text-lg font-black">{title}</h2>
          {hint && <p className="text-sm text-muted-foreground">{hint}</p>}
        </div>
        {right}
      </div>
      {children}
    </Card>
  );
}

function LinesBox({ label, value, onChange, rows = 4, testid }: { label: string; value: string[]; onChange: (v: string[]) => void; rows?: number; testid?: string }) {
  return (
    <div>
      <Label>{label} <span className="font-normal text-muted-foreground">(one per line)</span></Label>
      <Textarea value={value.join("\n")} onChange={(e) => onChange(lines(e.target.value))} rows={rows} className="mt-1" data-testid={testid} />
    </div>
  );
}

function PhotoPicker({ open, onOpenChange, destination, onPick }: { open: boolean; onOpenChange: (o: boolean) => void; destination: string; onPick: (m: MediaItem) => void }) {
  const [items, setItems] = useState<MediaItem[]>([]);
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    if (!open) return;
    let live = true;
    setLoading(true);
    api.get<MediaItem[]>("/media", { params: destination ? { destination } : {} })
      .then((r) => { if (live) setItems(r.data); })
      .catch(() => { if (live) setItems([]); })
      .finally(() => { if (live) setLoading(false); });
    return () => { live = false; };
  }, [open, destination]);
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">Choose a picture{destination ? ` · ${destination}` : ""}</DialogTitle></DialogHeader>
        {loading ? <div className="py-10 text-center text-sm text-muted-foreground"><Loader2 className="mx-auto animate-spin" /></div>
          : items.length === 0 ? <p className="py-8 text-center text-sm text-muted-foreground">No pictures for this destination yet. Operations can add them in the Photo Library.</p>
            : (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {items.map((m) => (
                  <button key={m.id} type="button" onClick={() => { onPick(m); onOpenChange(false); }} className="group overflow-hidden rounded-lg border text-left hover:ring-2 hover:ring-primary">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={m.thumb_url} alt={m.caption ?? ""} className="aspect-[4/3] w-full object-cover" loading="lazy" />
                    {m.caption && <div className="truncate px-2 py-1 text-xs text-muted-foreground">{m.caption}</div>}
                  </button>
                ))}
              </div>
            )}
      </DialogContent>
    </Dialog>
  );
}

function PhotoSlot({ id, cache, onChoose, onClear, label }: { id: number | string | null; cache: Record<string, MediaItem>; onChoose: () => void; onClear: () => void; label: string }) {
  const m = id != null ? cache[String(id)] : null;
  return (
    <div className="flex items-center gap-3">
      {m ? (
        <>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={m.thumb_url} alt="" className="h-14 w-20 rounded-md object-cover" />
          <Button type="button" variant="outline" size="sm" onClick={onChoose}>Change</Button>
          <button type="button" onClick={onClear} aria-label="Remove picture" className="rounded p-1 hover:bg-accent"><X size={14} /></button>
        </>
      ) : <Button type="button" variant="outline" size="sm" onClick={onChoose}><ImageIcon size={14} /> {label}</Button>}
    </div>
  );
}

export default function ProposalEditor() {
  const router = useRouter();
  const sp = useSearchParams();
  const { id } = useParams<{ id: string }>();
  const { can } = useCurrentUser();
  const editing = id !== "new";
  const manager = can("hotels.manage");

  const [ready, setReady] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [customer, setCustomer] = useState<CustomerBrief | null>(null);
  const [dealId, setDealId] = useState<string | null>(null);
  const [title, setTitle] = useState("");
  const [status, setStatus] = useState("draft");
  const [quoteNo, setQuoteNo] = useState("");
  const [p, setP] = useState<ProposalData | null>(null);
  const [computed, setComputed] = useState<ProposalComputed | null>(null);
  const [computing, setComputing] = useState(false);
  const [q, setQ] = useState("");
  const [results, setResults] = useState<Customer[]>([]);
  const [hotels, setHotels] = useState<Hotel[]>([]);
  const [details, setDetails] = useState<Record<string, Hotel>>({});
  const [media, setMedia] = useState<Record<string, MediaItem>>({});
  const [picker, setPicker] = useState<null | { kind: "cover" } | { kind: "day"; index: number }>(null);
  const [sizes, setSizes] = useState("2, 4, 6");
  const [templates, setTemplates] = useState<QuoteTemplateBrief[]>([]);
  const [templateId, setTemplateId] = useState<string | null>(sp.get("template"));
  const [applying, setApplying] = useState(false);

  const dest = p?.trip.destination ?? "";

  const loadMedia = useCallback(async (ids: (number | string | null)[]) => {
    const want = ids.filter((x): x is number | string => x != null);
    if (!want.length) return;
    try {
      const { data } = await api.get<MediaItem[]>("/media");
      setMedia((m) => ({ ...m, ...Object.fromEntries(data.map((x) => [x.id, x])) }));
    } catch { /* thumbnails stay blank */ }
  }, []);

  // load the proposal, or a starting point
  useEffect(() => {
    let live = true;
    (async () => {
      try {
        if (editing) {
          const { data } = await api.get<{ quote: Quote; proposal: ProposalData; computed: ProposalComputed }>(`/quote-proposals/${id}`);
          if (!live) return;
          setCustomer(data.quote.customer); setDealId(data.quote.deal_id); setTitle(data.quote.title); setStatus(data.quote.status); setQuoteNo(data.quote.quote_no);
          setP(data.proposal); setComputed(data.computed); setSizes(data.proposal.trip.group_sizes.join(", "));
          void loadMedia([data.proposal.cover_media_id, ...data.proposal.days.map((d) => d.media_id)]);
        } else {
          const params: Record<string, string> = {};
          if (sp.get("deal")) params.deal_id = sp.get("deal")!;
          if (sp.get("package")) params.package_id = sp.get("package")!;
          if (sp.get("template")) params.template_id = sp.get("template")!;
          const { data } = await api.get<SeedResult>("/quote-proposals/seed", { params });
          if (!live) return;
          void loadMedia([data.proposal.cover_media_id, ...data.proposal.days.map((d) => d.media_id)]);
          setP(withSlots(data)); setTitle(data.title); setDealId(data.deal_id); setSizes(data.proposal.trip.group_sizes.join(", "));
          const cid = data.customer_id ?? sp.get("customer");
          if (cid) {
            const { data: c } = await api.get<Customer>(`/customers/${cid}`);
            if (live) setCustomer({ id: c.id, customer_no: c.customer_no, name: c.name });
          }
        }
      } catch (e) { if (live) setError(formatApiError(e)); }
      finally { if (live) setReady(true); }
    })();
    return () => { live = false; };
  }, [id, editing, sp, loadMedia]);

  // ready-made templates to start from
  useEffect(() => {
    if (editing) return;
    api.get<QuoteTemplateBrief[]>("/quote-templates").then((r) => setTemplates(r.data)).catch(() => setTemplates([]));
  }, [editing]);

  const applyTemplate = async (tid: string | null) => {
    setApplying(true); setError("");
    try {
      const params: Record<string, string> = {};
      if (dealId) params.deal_id = dealId;
      if (sp.get("package")) params.package_id = sp.get("package")!;
      if (tid) params.template_id = tid;
      const { data } = await api.get<SeedResult>("/quote-proposals/seed", { params });
      const next = withSlots(data);
      setP((cur) => (cur ? { ...next, trip: { ...next.trip, start_date: cur.trip.start_date ?? next.trip.start_date, adults: cur.trip.adults, children_bed: cur.trip.children_bed, children_no_bed: cur.trip.children_no_bed } } : next));
      setTitle(data.title); setSizes(data.proposal.trip.group_sizes.join(", ")); setTemplateId(tid);
      void loadMedia([data.proposal.cover_media_id, ...data.proposal.days.map((d) => d.media_id)]);
    } catch (e) { setError(formatApiError(e)); }
    finally { setApplying(false); }
  };

  const saveAsTemplate = async () => {
    if (!p) return;
    const name = window.prompt("Name for this template (the itinerary, pictures, land package and text are kept; hotels are not)", title);
    if (!name?.trim()) return;
    try {
      await api.post("/quote-templates", { name: name.trim(), proposal: p });
      toast.success("Template saved. Sales can start from it now.");
    } catch (e) { toast.error(formatApiError(e)); }
  };

  // customer search
  useEffect(() => {
    if (customer || !q.trim()) { setResults([]); return; }
    const t = setTimeout(() => { api.get<Customer[]>("/customers", { params: { q: q.trim(), limit: 8 } }).then((r) => setResults(r.data)).catch(() => setResults([])); }, 250);
    return () => clearTimeout(t);
  }, [q, customer]);

  // hotels of the destination
  useEffect(() => {
    if (!dest.trim()) { setHotels([]); return; }
    const t = setTimeout(() => { api.get<Hotel[]>("/hotels", { params: { destination: dest.trim() } }).then((r) => setHotels(r.data)).catch(() => setHotels([])); }, 300);
    return () => clearTimeout(t);
  }, [dest]);

  const loadHotel = useCallback(async (hid: string) => {
    try {
      const { data } = await api.get<Hotel>(`/hotels/${hid}`);
      setDetails((d) => ({ ...d, [hid]: data }));
    } catch { /* the stay shows no rooms */ }
  }, []);
  const wantedHotels = p ? p.options.flatMap((o) => o.stays.map((s) => (s.hotel_id != null ? String(s.hotel_id) : ""))).filter(Boolean).join(",") : "";
  useEffect(() => {
    wantedHotels.split(",").filter(Boolean).forEach((h) => { if (!details[h]) void loadHotel(h); });
  }, [wantedHotels, details, loadHotel]);

  // live prices
  const seq = useRef(0);
  useEffect(() => {
    if (!p) return;
    const n = ++seq.current;
    setComputing(true);
    const t = setTimeout(() => {
      api.post<{ computed: ProposalComputed }>("/quote-proposals/compute", { proposal: p })
        .then((r) => { if (n === seq.current) setComputed(r.data.computed); })
        .catch(() => { /* keep the last prices */ })
        .finally(() => { if (n === seq.current) setComputing(false); });
    }, 600);
    return () => clearTimeout(t);
  }, [p]);

  const set = (fn: (d: ProposalData) => void) => setP((cur) => { if (!cur) return cur; const d = structuredClone(cur); fn(d); return d; });

  const save = async () => {
    if (!p) return;
    if (!customer) { setError("Choose the customer this quotation is for."); return; }
    setSaving(true); setError("");
    try {
      const body = { customer_id: customer.id, deal_id: dealId, title: title.trim() || `${p.trip.destination || "Trip"} proposal`, proposal: p };
      const { data } = editing ? await api.put<{ quote: Quote }>(`/quote-proposals/${id}`, body) : await api.post<{ quote: Quote }>("/quote-proposals", body);
      toast.success(editing ? "Quotation saved" : "Quotation created");
      router.push(`/quotes/${data.quote.id}`);
    } catch (e) { setError(formatApiError(e)); }
    finally { setSaving(false); }
  };

  if (!ready || !p) return <div className="py-20 text-center text-muted-foreground">{error ? error : <Loader2 className="mx-auto animate-spin" />}</div>;
  if (editing && status !== "draft") {
    return <div className="py-20 text-center text-muted-foreground">Only a draft can be edited. <Link href={`/quotes/${id}`} className="underline">Back to the quote</Link></div>;
  }

  const sel = computed?.options[p.selected_option];
  const photoLabel = (m: number | string | null) => (m != null && media[String(m)] ? "" : "Add picture");

  return (
    <div className="mx-auto max-w-5xl space-y-5 pb-24" data-testid="proposal-editor">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <Link href={editing ? `/quotes/${id}` : "/quotes"} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline"><ArrowLeft size={14} /> {editing ? quoteNo : "Quotes"}</Link>
          <div className="label-caps mt-2">Premium quotation</div>
          <h1 className="font-display text-3xl font-black tracking-tight">{editing ? "Edit quotation" : "New quotation"}</h1>
        </div>
        <div className="flex gap-2">
          {manager && <Button type="button" variant="outline" onClick={saveAsTemplate} data-testid="proposal-save-template"><BookmarkPlus size={16} /> Save as template</Button>}
          <Button onClick={save} disabled={saving} data-testid="proposal-save">{saving && <Loader2 size={16} className="animate-spin" />} Save quotation</Button>
        </div>
      </div>
      {error && <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}

      {!editing && templates.length > 0 && (
        <Section title="Start from a template" hint="Pick a ready-made itinerary with pictures, land package and text. You only add the customer, dates, travellers and hotels.">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="proposal-templates">
            {templates.map((t) => (
              <button key={t.id} type="button" disabled={applying} onClick={() => applyTemplate(t.id)} data-testid={`proposal-template-${t.id}`}
                className={`overflow-hidden rounded-lg border text-left transition hover:ring-2 hover:ring-primary ${templateId === t.id ? "ring-2 ring-primary" : ""}`}>
                {t.cover_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={t.cover_url} alt="" className="aspect-[16/9] w-full object-cover" loading="lazy" />
                ) : <div className="aspect-[16/9] w-full bg-muted" />}
                <div className="p-3">
                  <div className="font-display text-base font-black leading-snug">{t.name}</div>
                  {t.tagline && <div className="mt-1 text-xs text-muted-foreground">{t.tagline}</div>}
                  <div className="mt-2 text-xs text-muted-foreground">{t.nights} nights, {t.days} days · {t.options.join(", ")}</div>
                  {templateId === t.id && <div className="mt-1 text-xs font-bold text-primary">Selected</div>}
                </div>
              </button>
            ))}
          </div>
          {templateId && <button type="button" className="mt-3 text-sm text-muted-foreground underline" onClick={() => applyTemplate(null)}>Start from blank instead</button>}
        </Section>
      )}

      <Section title="Customer and trip">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label>Customer *</Label>
            {customer ? (
              <div className="mt-1 flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                <span><span className="font-medium">{customer.name}</span> <span className="font-mono text-xs text-muted-foreground">{customer.customer_no}</span></span>
                {!editing && <button type="button" onClick={() => { setCustomer(null); setDealId(null); }} aria-label="Change customer" className="rounded p-1 hover:bg-accent"><X size={14} /></button>}
              </div>
            ) : (
              <div className="relative mt-1">
                <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search customer by name, phone or number…" className="h-10 pl-9" />
                {results.length > 0 && (
                  <div className="absolute z-20 mt-1 w-full overflow-hidden rounded-md border bg-popover shadow-md">
                    {results.map((c) => (
                      <button key={c.id} type="button" onClick={() => { setCustomer({ id: c.id, customer_no: c.customer_no, name: c.name }); setQ(""); }} className="block w-full px-3 py-2 text-left text-sm hover:bg-accent">
                        {c.name} <span className="font-mono text-xs text-muted-foreground">{c.customer_no}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
          <div className="sm:col-span-2"><Label>Quotation title</Label><Input value={title} onChange={(e) => setTitle(e.target.value)} className="mt-1" placeholder="Manali season, 4 nights 5 days" /></div>
          <div><Label>Destination</Label><Input value={p.trip.destination} onChange={(e) => set((d) => { d.trip.destination = e.target.value; })} className="mt-1" data-testid="proposal-destination" /></div>
          <div><Label>Travel date</Label><Input type="date" value={p.trip.start_date ?? ""} onChange={(e) => set((d) => { d.trip.start_date = e.target.value || null; })} className="mt-1" /></div>
          <div><Label>Nights</Label><Input type="number" min={0} max={90} value={p.trip.nights} onChange={(e) => set((d) => { d.trip.nights = Number(e.target.value) || 0; })} className="mt-1" /></div>
          <div><Label>GST on the package (%)</Label><Input type="number" min={0} max={100} value={p.gst_rate} onChange={(e) => set((d) => { d.gst_rate = Number(e.target.value) || 0; })} className="mt-1" /></div>
          <div><Label>Adults</Label><Input type="number" min={1} value={p.trip.adults} onChange={(e) => set((d) => { d.trip.adults = Math.max(1, Number(e.target.value) || 1); })} className="mt-1" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><Label>Children with bed</Label><Input type="number" min={0} value={p.trip.children_bed} onChange={(e) => set((d) => { d.trip.children_bed = Number(e.target.value) || 0; })} className="mt-1" /></div>
            <div><Label>Children no bed</Label><Input type="number" min={0} value={p.trip.children_no_bed} onChange={(e) => set((d) => { d.trip.children_no_bed = Number(e.target.value) || 0; })} className="mt-1" /></div>
          </div>
          <div className="sm:col-span-2">
            <Label>Price per person for groups of <span className="font-normal text-muted-foreground">(comma separated, up to 6)</span></Label>
            <Input value={sizes} onChange={(e) => { setSizes(e.target.value); const n = e.target.value.split(",").map((x) => parseInt(x, 10)).filter((x) => x >= 1 && x <= 60); if (n.length) set((d) => { d.trip.group_sizes = n.slice(0, 6); }); }} className="mt-1" data-testid="proposal-sizes" />
          </div>
          <div className="sm:col-span-2"><Label>Cover picture</Label><div className="mt-1"><PhotoSlot id={p.cover_media_id} cache={media} label="Choose cover" onChoose={() => setPicker({ kind: "cover" })} onClear={() => set((d) => { d.cover_media_id = null; })} /></div></div>
        </div>
      </Section>

      <Section title="Itinerary" hint="Each day appears as a page with its own picture." right={<Button type="button" variant="outline" size="sm" onClick={() => set((d) => { d.days.push({ title: `Day ${d.days.length + 1}`, stay: "", bullets: [], media_id: null }); })}><Plus size={14} /> Add day</Button>}>
        {p.days.length === 0 && <p className="text-sm text-muted-foreground">No days yet.</p>}
        <div className="space-y-4">
          {p.days.map((d: ProposalDay, i) => (
            <div key={i} className="rounded-lg border p-3" data-testid={`proposal-day-${i}`}>
              <div className="flex items-center gap-2">
                <span className="rounded bg-muted px-2 py-1 text-xs font-bold">Day {i + 1}</span>
                <Input value={d.title} onChange={(e) => set((x) => { x.days[i].title = e.target.value; })} placeholder="Title" className="h-9" />
                <Input value={d.stay} onChange={(e) => set((x) => { x.days[i].stay = e.target.value; })} placeholder="Stay at" className="h-9 max-w-[180px]" />
                <button type="button" onClick={() => set((x) => { x.days.splice(i, 1); })} aria-label="Remove day" className="rounded p-2 text-muted-foreground hover:bg-accent"><Trash2 size={14} /></button>
              </div>
              <div className="mt-2 grid gap-3 sm:grid-cols-[1fr_auto]">
                <Textarea value={d.bullets.join("\n")} onChange={(e) => set((x) => { x.days[i].bullets = lines(e.target.value); })} rows={3} placeholder="What happens on this day (one point per line)" />
                <PhotoSlot id={d.media_id} cache={media} label={photoLabel(d.media_id) || "Add picture"} onChoose={() => setPicker({ kind: "day", index: i })} onClear={() => set((x) => { x.days[i].media_id = null; })} />
              </div>
            </div>
          ))}
        </div>
      </Section>

      <Section title="Hotel options" hint="Offer the customer a choice. Each option is priced for every group size." right={p.options.length < 4 ? <Button type="button" variant="outline" size="sm" onClick={() => set((d) => { d.options.push({ name: `Option ${d.options.length + 1}`, stays: [] }); })}><Plus size={14} /> Add option</Button> : null}>
        <div className="space-y-4">
          {p.options.map((o, oi) => (
            <div key={oi} className="rounded-lg border p-3" data-testid={`proposal-option-${oi}`}>
              <div className="flex flex-wrap items-center gap-2">
                <Input value={o.name} onChange={(e) => set((d) => { d.options[oi].name = e.target.value; })} className="h-9 max-w-[220px] font-medium" />
                <label className="flex items-center gap-1.5 text-sm"><input type="radio" name="selected-option" checked={p.selected_option === oi} onChange={() => set((d) => { d.selected_option = oi; })} /> Used for the quote total</label>
                {p.options.length > 1 && <button type="button" onClick={() => set((d) => { d.options.splice(oi, 1); d.selected_option = Math.min(d.selected_option, d.options.length - 1); })} aria-label="Remove option" className="ml-auto rounded p-2 text-muted-foreground hover:bg-accent"><Trash2 size={14} /></button>}
              </div>
              <div className="mt-3 space-y-2">
                {o.stays.map((s: ProposalStay, si) => {
                  const h = s.hotel_id != null ? details[String(s.hotel_id)] : undefined;
                  const room = h?.rooms?.find((r) => String(r.id) === String(s.room_id));
                  const meals = room?.meals ?? ["EP", "CP", "MAP", "AP"];
                  return (
                    <div key={si} className="grid gap-2 rounded-md bg-muted/40 p-2 sm:grid-cols-[1.4fr_1.2fr_1fr_90px_90px_auto]" data-testid={`proposal-stay-${oi}-${si}`}>
                      <Select value={s.hotel_id != null ? String(s.hotel_id) : NONE} onValueChange={(v) => set((d) => { const st = d.options[oi].stays[si]; st.hotel_id = v === NONE ? null : v; st.room_id = null; })}>
                        <SelectTrigger className="h-9"><SelectValue placeholder={s.stars ? `Choose a ${s.stars} star hotel` : "Hotel"} /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value={NONE}>{s.stars ? `Choose a ${s.stars} star hotel` : "Choose hotel"}</SelectItem>
                          {[...hotels].sort((a, b) => Number(b.stars === s.stars) - Number(a.stars === s.stars)).map((x) => <SelectItem key={x.id} value={String(x.id)}>{x.name}{x.stars ? ` · ${x.stars}★` : ""}</SelectItem>)}
                          {h && !hotels.some((x) => x.id === h.id) && <SelectItem value={String(h.id)}>{h.name}</SelectItem>}
                        </SelectContent>
                      </Select>
                      <Select value={s.room_id != null ? String(s.room_id) : NONE} onValueChange={(v) => set((d) => { d.options[oi].stays[si].room_id = v === NONE ? null : v; })}>
                        <SelectTrigger className="h-9"><SelectValue placeholder="Room" /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value={NONE}>Choose room</SelectItem>
                          {(h?.rooms ?? []).map((r) => <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>)}
                        </SelectContent>
                      </Select>
                      <Select value={s.meal_plan} onValueChange={(v) => set((d) => { d.options[oi].stays[si].meal_plan = v as MealPlan; })}>
                        <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>{(["EP", "CP", "MAP", "AP"] as MealPlan[]).filter((m) => meals.includes(m) || m === s.meal_plan).map((m) => <SelectItem key={m} value={m}>{m} · {MEAL_LABEL[m]}</SelectItem>)}</SelectContent>
                      </Select>
                      <Input type="number" min={0} value={s.start_offset} onChange={(e) => set((d) => { d.options[oi].stays[si].start_offset = Number(e.target.value) || 0; })} className="h-9" title="Check in, nights after the travel date" aria-label="Check in after (nights)" />
                      <Input type="number" min={1} value={s.nights} onChange={(e) => set((d) => { d.options[oi].stays[si].nights = Math.max(1, Number(e.target.value) || 1); })} className="h-9" title="Nights" aria-label="Nights" />
                      <button type="button" onClick={() => set((d) => { d.options[oi].stays.splice(si, 1); })} aria-label="Remove stay" className="rounded p-2 text-muted-foreground hover:bg-accent"><Trash2 size={14} /></button>
                    </div>
                  );
                })}
                {o.stays.length > 0 && <p className="text-xs text-muted-foreground">Columns: hotel, room, meal plan, check in (nights after travel date), nights.</p>}
                <Button type="button" variant="outline" size="sm" onClick={() => set((d) => { const used = d.options[oi].stays.reduce((a, x) => a + x.nights, 0); d.options[oi].stays.push({ hotel_id: null, room_id: null, meal_plan: "CP", start_offset: used, nights: Math.max(1, d.trip.nights - used) }); })} data-testid={`proposal-add-stay-${oi}`}><Plus size={14} /> Add hotel stay</Button>
              </div>
            </div>
          ))}
        </div>
      </Section>

      <Section title="Land package" hint="Cost per person for transfers and sightseeing, by group size. A group uses the first row that covers it. Our margin is added on top." right={<Button type="button" variant="outline" size="sm" onClick={() => set((d) => { d.land.rows.push({ pax: (d.land.rows.at(-1)?.pax ?? 0) + 2, cost_pp: 0, child_cost_pp: null }); })}><Plus size={14} /> Add row</Button>}>
        <div className="space-y-3">
          <div><Label>Description on the quotation</Label><Input value={p.land.label} onChange={(e) => set((d) => { d.land.label = e.target.value; })} className="mt-1" placeholder="Delhi–Manali–Delhi Volvo and sightseeing" /></div>
          {p.land.rows.map((r, i) => (
            <div key={i} className="grid grid-cols-[1fr_1fr_1fr_auto] items-end gap-2">
              <div><Label className="text-xs">Up to (persons)</Label><Input type="number" min={1} value={r.pax} onChange={(e) => set((d) => { d.land.rows[i].pax = Number(e.target.value) || 1; })} className="mt-1 h-9" /></div>
              <div><Label className="text-xs">Cost per adult</Label><Input type="number" min={0} value={r.cost_pp} onChange={(e) => set((d) => { d.land.rows[i].cost_pp = Number(e.target.value) || 0; })} className="mt-1 h-9" /></div>
              <div><Label className="text-xs">Cost per child (optional)</Label><Input type="number" min={0} value={r.child_cost_pp ?? ""} onChange={(e) => set((d) => { d.land.rows[i].child_cost_pp = e.target.value === "" ? null : Number(e.target.value) || 0; })} className="mt-1 h-9" /></div>
              <button type="button" onClick={() => set((d) => { d.land.rows.splice(i, 1); })} aria-label="Remove row" className="rounded p-2 text-muted-foreground hover:bg-accent"><Trash2 size={14} /></button>
            </div>
          ))}
        </div>
      </Section>

      <Section title="Package price" hint="Worked out from the hotel rates and the land package. Prices are per person and exclude GST." right={computing ? <Loader2 size={16} className="animate-spin text-muted-foreground" /> : null}>
        {computed && computed.warnings.length > 0 && (
          <ul className="mb-3 list-disc space-y-1 rounded-md border border-amber-500/40 bg-amber-500/10 py-2 pl-7 pr-3 text-sm" data-testid="proposal-warnings">{computed.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
        )}
        <div className="overflow-x-auto">
          <table className="w-full text-sm" data-testid="proposal-prices">
            <thead>
              <tr className="border-b text-left text-xs uppercase text-muted-foreground">
                <th className="py-2 pr-3">Option</th>
                {p.trip.group_sizes.map((g) => <th key={g} className="px-3 py-2 text-right">{g} persons</th>)}
                <th className="px-3 py-2 text-right">This group ({(sel?.actual.persons ?? 0)})</th>
              </tr>
            </thead>
            <tbody>
              {(computed?.options ?? []).map((o, i) => (
                <tr key={i} className="border-b last:border-0">
                  <td className="py-2 pr-3 font-medium">{o.name}{p.selected_option === i && <span className="ml-2 rounded bg-primary/10 px-1.5 py-0.5 text-xs text-primary">quote</span>}</td>
                  {o.tiers.map((t) => <td key={t.pax} className="px-3 py-2 text-right tabular-nums">{INR(t.per_person)}</td>)}
                  <td className="px-3 py-2 text-right font-semibold tabular-nums">{INR(o.actual.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {manager && sel && (
          <p className="mt-3 text-sm text-muted-foreground" data-testid="proposal-margin">Operations view: cost {INR(sel.actual.cost)}, margin {INR(sel.actual.margin_amount)} for this group on the quoted option.</p>
        )}
      </Section>

      <Section title="Inclusions, exclusions and policies" hint="Filled from the standard text in Quotation Content. Change anything for this quotation.">
        <div className="grid gap-4 md:grid-cols-2">
          <LinesBox label="Inclusions" value={p.content.inclusions} onChange={(v) => set((d) => { d.content.inclusions = v; })} rows={6} />
          <LinesBox label="Exclusions" value={p.content.exclusions} onChange={(v) => set((d) => { d.content.exclusions = v; })} rows={6} />
          <LinesBox label="Important notes" value={p.content.notes} onChange={(v) => set((d) => { d.content.notes = v; })} rows={5} />
          <LinesBox label="Terms and conditions" value={p.content.terms} onChange={(v) => set((d) => { d.content.terms = v; })} rows={5} />
          <div className="md:col-span-2">
            <Label>Cancellation charges</Label>
            <div className="mt-1 space-y-2">
              {p.content.cancellation.tiers.map((t, i) => (
                <div key={i} className="flex items-center gap-2">
                  <Input value={t.label} onChange={(e) => set((d) => { d.content.cancellation.tiers[i].label = e.target.value; })} className="h-9" />
                  <Input type="number" min={0} max={100} value={t.percent} onChange={(e) => set((d) => { d.content.cancellation.tiers[i].percent = Number(e.target.value) || 0; })} className="h-9 w-24" aria-label="Percent" />
                  <span className="text-sm text-muted-foreground">%</span>
                  <button type="button" onClick={() => set((d) => { d.content.cancellation.tiers.splice(i, 1); })} aria-label="Remove tier" className="rounded p-2 text-muted-foreground hover:bg-accent"><Trash2 size={14} /></button>
                </div>
              ))}
              <Button type="button" variant="outline" size="sm" onClick={() => set((d) => { d.content.cancellation.tiers.push({ label: "", percent: 0 }); })}><Plus size={14} /> Add tier</Button>
            </div>
          </div>
          <LinesBox label="Refund and cancellation notes" value={p.content.cancellation.items} onChange={(v) => set((d) => { d.content.cancellation.items = v; })} rows={3} />
          <LinesBox label="Closing message" value={p.content.closing} onChange={(v) => set((d) => { d.content.closing = v; })} rows={3} />
        </div>
      </Section>

      <div className="fixed inset-x-0 bottom-0 z-30 border-t bg-background/95 px-4 py-3 backdrop-blur">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-3">
          <div className="text-sm">
            {sel ? <><span className="text-muted-foreground">{sel.name}, this group:</span> <span className="font-display text-lg font-black">{INR(sel.actual.total)}</span> <span className="text-muted-foreground">+ {p.gst_rate}% GST</span></> : null}
          </div>
          <Button onClick={save} disabled={saving}>{saving && <Loader2 size={16} className="animate-spin" />} Save quotation</Button>
        </div>
      </div>

      <PhotoPicker open={picker !== null} onOpenChange={(o) => !o && setPicker(null)} destination={dest}
        onPick={(m) => {
          setMedia((c) => ({ ...c, [m.id]: m }));
          if (picker?.kind === "cover") set((d) => { d.cover_media_id = m.id; });
          else if (picker?.kind === "day") { const i = picker.index; set((d) => { d.days[i].media_id = m.id; }); }
        }} />
    </div>
  );
}
