"use client";

import { Fragment, useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Bus, Car, ChevronDown, Copy, Download, FileSpreadsheet, Loader2, MapPin, MoreHorizontal, Pencil, Plus, Search, Ship, Trash2, Truck, Upload, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { fmtDate } from "@/components/crm/shared";
import { cn } from "@/lib/utils";
import TransportTabs from "@/components/transport/TransportTabs";
import type { Charged, LineImportResult, LineLookups, LineType, TransportLineRow, TransportLineSeason } from "@/types";

const TYPES: { v: LineType; label: string; filter: string; icon: typeof Car }[] = [
  { v: "cab", label: "Cab", filter: "Cabs", icon: Car }, { v: "van", label: "Tempo or van", filter: "Vans", icon: Truck },
  { v: "bus", label: "Bus", filter: "Buses", icon: Bus }, { v: "ferry", label: "Ferry", filter: "Ferries", icon: Ship },
];
const CHARGED: { v: Charged; label: string }[] = [{ v: "per_day", label: "Per day" }, { v: "per_trip", label: "Per trip" }, { v: "per_person", label: "Per person" }];
const chargedLabel = (c: string) => CHARGED.find((x) => x.v === c)?.label ?? c;
const typeLabel = (t: string) => TYPES.find((x) => x.v === t)?.label ?? t;
const money = (n: number | null | undefined, cur: string) => (n == null ? "–" : new Intl.NumberFormat(cur === "INR" ? "en-IN" : "en-US", { style: "currency", currency: cur, maximumFractionDigits: 2 }).format(n));
const num = (v: string) => (v.trim() === "" ? null : Number(v));
const defaultCharged = (t: LineType): Charged => (t === "bus" || t === "ferry" ? "per_person" : "per_day");
const DEST_KEY = "transport.rates.destination";
const routeType = (t: LineType) => t === "bus" || t === "ferry";

interface Form {
  type: LineType; destination: string; country: string; dest_currency: string; car_name: string; name: string; from_name: string; to_name: string; way: "one_way" | "return"; price: string; currency: string; charged: Charged;
  supplier: string; season_name: string; valid_from: string; valid_to: string; seats: string; note: string;
}
const SEASONS = ["On Season", "Off Season", "Mid Season"];
const carLabel = (t: LineType) => (t === "bus" ? "Bus name" : t === "ferry" ? "Boat name" : "Car name");
const blank = (type: LineType = "cab", destination = ""): Form => ({ type, destination, country: "India", dest_currency: "INR", car_name: "", name: "", from_name: "", to_name: "", way: "one_way", price: "", currency: "", charged: defaultCharged(type),
  supplier: "", season_name: "", valid_from: "", valid_to: "", seats: "", note: "" });

function Datalists({ look }: { look: LineLookups | null }) {
  return (
    <>
      <datalist id="tl-destinations">{(look?.destinations ?? []).map((d) => <option key={d.name} value={d.name}>{d.country}</option>)}</datalist>
      <datalist id="tl-places">{(look?.places ?? []).map((p) => <option key={p.name} value={p.name}>{`Used on ${p.rates} rate${p.rates === 1 ? "" : "s"}`}</option>)}</datalist>
      <datalist id="tl-car-names">{(look?.car_names ?? []).map((c) => <option key={c.name} value={c.name} />)}</datalist>
      <datalist id="tl-suppliers">{(look?.suppliers ?? []).map((s) => <option key={s.name} value={s.name} />)}</datalist>
    </>
  );
}

/** What the destination typed so far means: a known one (country and currency come with it), one only in Hotels so far, or brand new. */
function useDestination(look: LineLookups | null, name: string) {
  return useMemo(() => look?.destinations.find((d) => d.name.toLowerCase() === name.trim().toLowerCase()) ?? null, [look, name]);
}

function RateDialog({ open, item, look, preset, onClose, onSaved }: { open: boolean; item: TransportLineRow | null; look: LineLookups | null; preset: { type: LineType; destination: string }; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState<Form>(blank());
  const [more, setMore] = useState(false);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!open) return;
    setMore(false);
    setF(item ? { type: item.type, destination: item.destination?.name ?? "", country: item.destination?.country ?? "India", dest_currency: item.destination?.currency ?? "INR", car_name: item.car_name ?? "", name: item.name, from_name: item.from_name ?? "", to_name: item.to_name ?? "",
      way: item.way ?? "one_way", price: item.price == null ? "" : String(item.price), currency: item.currency, charged: item.charged, supplier: item.supplier ?? "", season_name: item.season_name ?? "", valid_from: item.valid_from ?? "", valid_to: item.valid_to ?? "",
      seats: item.seats == null ? "" : String(item.seats), note: item.note ?? "" } : blank(preset.type, preset.destination));
    if (item && (item.supplier || item.season_name || item.valid_from || item.seats || item.note)) setMore(true);
  }, [open, item, preset.type, preset.destination]);
  const dest = useDestination(look, f.destination);
  const isNew = f.destination.trim() !== "" && !dest?.known && !item;
  const curr = f.currency || dest?.currency || f.dest_currency || "INR";
  const save = async (e: FormEvent, again: boolean) => {
    e.preventDefault(); setBusy(true);
    const body = {
      type: f.type, destination: f.destination.trim(), country: isNew ? f.country : undefined, dest_currency: isNew ? f.dest_currency : undefined, car_name: f.car_name || null, name: f.name, price: Number(f.price), charged: f.charged,
      currency: f.currency || undefined, supplier: f.supplier || null, from_name: routeType(f.type) ? f.from_name || null : null, to_name: routeType(f.type) ? f.to_name || null : null, way: routeType(f.type) ? f.way : null,
      season_name: f.season_name || null, valid_from: f.valid_from || null, valid_to: f.valid_to || null, seats: num(f.seats), note: f.note || null,
    };
    try {
      if (item) await api.patch(`/transport/lines/${item.id}`, body); else await api.post("/transport/lines", body);
      toast.success("Rate saved"); onSaved();
      if (again && !item) setF({ ...blank(f.type, f.destination), country: f.country, dest_currency: f.dest_currency }); else onClose();
    } catch (err) { toast.error(formatApiError(err)); } finally { setBusy(false); }
  };
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[92vh] max-w-xl overflow-y-auto" data-testid="rate-dialog">
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">{item ? "Edit transport" : "Add transport"}</DialogTitle><DialogDescription>Only the destination, the route service and the price are needed. Everything else is optional.</DialogDescription></DialogHeader>
        <form onSubmit={(e) => save(e, false)} className="space-y-3">
          <div>
            <Label className="label-caps">1. What is it?</Label>
            <div className="mt-1.5 grid grid-cols-4 gap-2" data-testid="rate-types">
              {TYPES.map((t) => <button key={t.v} type="button" aria-pressed={f.type === t.v} onClick={() => setF({ ...f, type: t.v, charged: item ? f.charged : defaultCharged(t.v) })}
                className={cn("flex flex-col items-center gap-1 rounded-md border px-2 py-2 text-xs", f.type === t.v ? "border-foreground bg-foreground text-background" : "border-border text-muted-foreground hover:bg-accent")} data-testid={`rate-type-${t.v}`}><t.icon size={16} />{t.label}</button>)}
            </div>
          </div>
          <div>
            <Label className="label-caps">2. Where? (destination)</Label>
            <Input className="mt-1.5" list="tl-destinations" value={f.destination} onChange={(e) => setF({ ...f, destination: e.target.value })} required placeholder="Manali" data-testid="rate-destination" />
            {dest?.known && <p className="mt-1 text-xs text-emerald-700 dark:text-emerald-400">{dest.name}, {dest.country} · currency {dest.currency}</p>}
            {isNew && (
              <div className="mt-2 grid grid-cols-2 gap-2 rounded-md border border-dashed p-2" data-testid="rate-new-destination">
                <p className="col-span-2 text-xs text-muted-foreground">{dest ? `${dest.name} is in Hotels already.` : "A new destination."} Confirm its country and currency once; later rates use them.</p>
                <div><Label className="text-xs">Country</Label><Input className="mt-1 h-9" value={f.country} onChange={(e) => setF({ ...f, country: e.target.value })} data-testid="rate-country" /></div>
                <div><Label className="text-xs">Currency</Label><Input className="mt-1 h-9" value={f.dest_currency} maxLength={3} onChange={(e) => setF({ ...f, dest_currency: e.target.value.toUpperCase() })} data-testid="rate-dest-currency" /></div>
              </div>
            )}
          </div>
          {routeType(f.type) && (
            <div className="grid grid-cols-[1fr_1fr_130px] gap-2">
              <div><Label className="label-caps">From</Label><Input className="mt-1.5" list="tl-places" value={f.from_name} onChange={(e) => setF({ ...f, from_name: e.target.value })} placeholder="Delhi" /></div>
              <div><Label className="label-caps">To</Label><Input className="mt-1.5" list="tl-places" value={f.to_name} onChange={(e) => setF({ ...f, to_name: e.target.value })} placeholder="Manali" /></div>
              <div><Label className="label-caps">Way</Label>
                <Select value={f.way} onValueChange={(v) => setF({ ...f, way: v as "one_way" | "return" })}><SelectTrigger className="mt-1.5"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="one_way">One way</SelectItem><SelectItem value="return">Return</SelectItem></SelectContent></Select></div>
            </div>
          )}
          <div><Label className="label-caps">{carLabel(f.type)}</Label><Input className="mt-1.5" list="tl-car-names" value={f.car_name} onChange={(e) => setF({ ...f, car_name: e.target.value })} maxLength={120} placeholder={routeType(f.type) ? "Volvo B11R" : "Ertiga"} data-testid="rate-car-name" /></div>
          <div><Label className="label-caps">Route service</Label><Input className="mt-1.5" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required placeholder={routeType(f.type) ? "Delhi to Manali, semi-sleeper" : "Local sightseeing"} data-testid="rate-name" /></div>
          <div className="grid grid-cols-[1fr_90px] gap-3">
            <div><Label className="label-caps">3. Price</Label><Input className="mt-1.5" type="number" min={0} step="any" value={f.price} onChange={(e) => setF({ ...f, price: e.target.value })} required placeholder="5500" data-testid="rate-price" /></div>
            <div><Label className="label-caps">Currency</Label><Input className="mt-1.5" value={f.currency || (dest?.currency ?? f.dest_currency)} maxLength={3} onChange={(e) => setF({ ...f, currency: e.target.value.toUpperCase() })} data-testid="rate-currency" /></div>
          </div>
          <p className="-mt-2 text-xs text-muted-foreground">Currency is {curr} (from the destination). Change it only if this rate differs.</p>
          <div>
            <Label className="label-caps">Charged</Label>
            <div className="mt-1.5 flex gap-2" data-testid="rate-charged">
              {CHARGED.map((c) => <button key={c.v} type="button" aria-pressed={f.charged === c.v} onClick={() => setF({ ...f, charged: c.v })} className={cn("rounded-md border px-3 py-1.5 text-sm", f.charged === c.v ? "border-foreground bg-foreground text-background" : "border-border text-muted-foreground hover:bg-accent")} data-testid={`rate-charged-${c.v}`}>{c.label}</button>)}
            </div>
          </div>
          <button type="button" onClick={() => setMore((m) => !m)} className="flex items-center gap-1 text-sm underline" data-testid="rate-more"><ChevronDown size={14} className={cn(more && "rotate-180")} /> {more ? "Hide details" : "More details: supplier, season, seats (optional)"}</button>
          {more && (
            <div className="space-y-3 rounded-md border p-3" data-testid="rate-more-fields">
              <div className="grid grid-cols-2 gap-3">
                <div className="col-span-2"><Label className="text-xs">Supplier</Label><Input className="mt-1 h-9" list="tl-suppliers" value={f.supplier} onChange={(e) => setF({ ...f, supplier: e.target.value })} placeholder="Manali Taxi Service" data-testid="rate-supplier" /></div>
                <div><Label className="text-xs">Season</Label>
                  <Select value={f.season_name || "none"} onValueChange={(v) => setF({ ...f, season_name: v === "none" ? "" : v })}><SelectTrigger className="mt-1 h-9" data-testid="rate-season"><SelectValue /></SelectTrigger>
                    <SelectContent><SelectItem value="none">All year</SelectItem>{[...SEASONS, ...(f.season_name && !SEASONS.includes(f.season_name) ? [f.season_name] : [])].map((x) => <SelectItem key={x} value={x}>{x}</SelectItem>)}</SelectContent></Select></div>
                <div />
                <div><Label className="text-xs">Valid from</Label><Input className="mt-1 h-9" type="date" value={f.valid_from} onChange={(e) => setF({ ...f, valid_from: e.target.value })} data-testid="rate-valid-from" /></div>
                <div><Label className="text-xs">Valid to</Label><Input className="mt-1 h-9" type="date" value={f.valid_to} onChange={(e) => setF({ ...f, valid_to: e.target.value })} data-testid="rate-valid-to" /></div>
                <div><Label className="text-xs">Seats</Label><Input className="mt-1 h-9" type="number" min={1} value={f.seats} onChange={(e) => setF({ ...f, seats: e.target.value })} data-testid="rate-seats" /></div>
              </div>
              <div><Label className="text-xs">Note</Label><Textarea className="mt-1" rows={2} value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} placeholder="Toll and parking extra" /></div>
              <p className="text-xs text-muted-foreground">Leave both dates empty for a price that applies all year. More seasons of the same service are added from the ⋯ menu on the list.</p>
            </div>
          )}
          <DialogFooter className="gap-2 sm:gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={busy}>Close</Button>
            {!item && <Button type="button" variant="outline" disabled={busy} onClick={(e) => save(e as unknown as FormEvent, true)} data-testid="rate-save-another">Save and add another</Button>}
            <Button type="submit" disabled={busy} data-testid="rate-save">{busy ? "Saving…" : "Save"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** More seasons of one service: each with its own dates and price, still one line on the list. */
function SeasonDialog({ line, onClose, onChanged }: { line: TransportLineRow | null; onClose: () => void; onChanged: () => void }) {
  const [rows, setRows] = useState<TransportLineSeason[]>([]);
  const [f, setF] = useState({ name: "", valid_from: "", valid_to: "", price: "" });
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (line) { setRows(line.seasons); setF({ name: "", valid_from: "", valid_to: "", price: "" }); } }, [line]);
  if (!line) return null;
  const apply = async (fn: () => Promise<{ data: TransportLineRow }>) => { setBusy(true); try { const r = await fn(); setRows(r.data.seasons); onChanged(); } catch (e) { toast.error(formatApiError(e)); } finally { setBusy(false); } };
  const add = (e: FormEvent) => {
    e.preventDefault();
    if (!f.name) { toast.error("Choose the season"); return; }
    void apply(() => api.post<TransportLineRow>(`/transport/lines/${line.id}/seasons`, { name: f.name, valid_from: f.valid_from || null, valid_to: f.valid_to || null, price: Number(f.price) })).then(() => setF({ name: "", valid_from: "", valid_to: "", price: "" }));
  };
  const cols = "grid grid-cols-[minmax(0,1fr)_4.9rem_4.9rem_4.6rem_1.75rem] items-center gap-x-1.5 sm:grid-cols-[minmax(0,1.3fr)_8.5rem_8.5rem_6.5rem_2.25rem] sm:gap-x-2";   // one grid for the heading, the rows and the add row: nothing can overlap
  const dateTag = (v: string | null, needed: boolean) => (v ? fmtDate(v) : needed ? <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">Dates needed</span> : "–");
  return (
    <Dialog open={!!line} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[92vh] max-w-3xl overflow-y-auto" data-testid="season-dialog">
        <DialogHeader><DialogTitle className="font-display text-xl font-black">Seasons</DialogTitle><DialogDescription>{line.car_name ? `${line.car_name}, ` : ""}{line.name}. {line.destination?.name} · {chargedLabel(line.charged)}. The line keeps one row; each season has its own dates and price.</DialogDescription></DialogHeader>
        <div>
          <div className="text-xs sm:text-sm" data-testid="season-grid">
            <div className={cn(cols, "border-b py-2 text-[11px] uppercase tracking-wide text-muted-foreground")}><span>Season</span><span>From</span><span>To</span><span className="text-right">Price</span><span /></div>
            <div className={cn(cols, "border-b py-2")}>
              <span className="min-w-0 break-words">{line.season_name ?? "All year"} <span className="text-xs text-muted-foreground">(main price)</span></span>
              <span>{dateTag(line.valid_from, false)}</span><span>{dateTag(line.valid_to, false)}</span><span className="text-right tabular-nums">{money(line.price, line.currency)}</span><span />
            </div>
            {rows.map((s) => (
              <div key={s.id} className={cn(cols, "border-b py-2")} data-testid={`season-row-${s.id}`}>
                <span className="min-w-0 break-words">{s.name}</span>
                <span>{dateTag(s.valid_from, true)}</span><span>{dateTag(s.valid_to, false)}</span>
                <span className="text-right tabular-nums">{money(s.price, line.currency)}</span>
                <button type="button" aria-label="Delete season" className="justify-self-end rounded p-1.5 hover:bg-accent" onClick={() => apply(() => api.delete<TransportLineRow>(`/transport/line-seasons/${s.id}`))}><Trash2 size={14} /></button>
              </div>
            ))}
            <form onSubmit={add} className="grid grid-cols-[minmax(0,1fr)_6rem_2.25rem] items-center gap-2 pt-3 sm:grid-cols-[minmax(0,1.3fr)_8.5rem_8.5rem_6.5rem_2.25rem]" data-testid="season-form">
              <Select value={f.name || undefined} onValueChange={(v) => setF({ ...f, name: v })}><SelectTrigger className="order-1 h-9 sm:order-none" aria-label="Season" data-testid="season-name"><SelectValue placeholder="Season" /></SelectTrigger>
                <SelectContent>{SEASONS.map((x) => <SelectItem key={x} value={x}>{x}</SelectItem>)}</SelectContent></Select>
              <Input className="order-4 h-9 px-2 sm:order-none" type="date" aria-label="From" value={f.valid_from} onChange={(e) => setF({ ...f, valid_from: e.target.value })} />
              <Input className="order-5 col-span-2 h-9 px-2 sm:order-none sm:col-span-1" type="date" aria-label="To" value={f.valid_to} onChange={(e) => setF({ ...f, valid_to: e.target.value })} />
              <Input className="order-2 h-9 px-2 text-right sm:order-none" type="number" min={0} step="any" aria-label="Price" placeholder="Price" value={f.price} onChange={(e) => setF({ ...f, price: e.target.value })} required data-testid="season-price" />
              <Button type="submit" size="icon" className="order-3 h-9 w-9 sm:order-none" disabled={busy} aria-label="Add season" data-testid="season-add"><Plus size={16} /></Button>
            </form>
          </div>
        </div>
        <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

interface Change { id: string; name: string; destination: string | null; currency: string; old: number | null; new: number | null; seasons: { name: string; old: number; new: number }[] }

function BulkDialog({ ids, rows, onClose, onDone }: { ids: string[]; rows: TransportLineRow[]; onClose: () => void; onDone: () => void }) {
  const [direction, setDirection] = useState<"increase" | "decrease">("increase");
  const [percent, setPercent] = useState("10");
  const [changes, setChanges] = useState<Change[] | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (ids.length) { setChanges(null); setPercent("10"); setDirection("increase"); } }, [ids]);
  if (!ids.length) return null;
  const call = async (preview: boolean) => {
    setBusy(true);
    try {
      const { data } = await api.post<{ changes: Change[] }>("/transport/lines/bulk-price", { ids: ids.map(Number), direction, percent: Number(percent), preview });
      if (preview) setChanges(data.changes); else { toast.success(`Prices updated on ${data.changes.length} rate${data.changes.length === 1 ? "" : "s"}`); onDone(); onClose(); }
    } catch (e) { toast.error(formatApiError(e)); } finally { setBusy(false); }
  };
  return (
    <Dialog open={ids.length > 0} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto" data-testid="bulk-dialog">
        <DialogHeader><DialogTitle className="font-display text-xl font-black">Change prices</DialogTitle><DialogDescription>{rows.filter((r) => ids.includes(r.id)).length} rate{ids.length === 1 ? "" : "s"} selected. The old and new prices are shown before anything is saved.</DialogDescription></DialogHeader>
        <div className="flex items-end gap-2">
          <Select value={direction} onValueChange={(v) => { setDirection(v as "increase" | "decrease"); setChanges(null); }}><SelectTrigger className="w-[140px]" data-testid="bulk-direction"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="increase">Increase by</SelectItem><SelectItem value="decrease">Decrease by</SelectItem></SelectContent></Select>
          <Input className="w-24" type="number" min={0} step="any" value={percent} onChange={(e) => { setPercent(e.target.value); setChanges(null); }} data-testid="bulk-percent" /><span className="pb-2 text-sm">%</span>
          <Button type="button" variant="outline" disabled={busy || !Number(percent)} onClick={() => call(true)} data-testid="bulk-preview">Preview</Button>
        </div>
        {changes && (
          <div className="max-h-64 overflow-y-auto rounded-md border" data-testid="bulk-changes">
            <table className="w-full text-sm"><thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="p-2">Rate</th><th className="p-2 text-right">Old</th><th className="p-2 text-right">New</th></tr></thead>
              <tbody>{changes.map((c) => (
                <Fragment key={c.id}>
                  <tr className="border-b last:border-0"><td className="p-2">{c.name}<div className="text-xs text-muted-foreground">{c.destination}</div></td><td className="p-2 text-right tabular-nums">{money(c.old, c.currency)}</td><td className="p-2 text-right font-medium tabular-nums">{money(c.new, c.currency)}</td></tr>
                  {c.seasons.map((s, i) => <tr key={i} className="border-b text-xs text-muted-foreground last:border-0"><td className="p-2 pl-5">{s.name}</td><td className="p-2 text-right tabular-nums">{money(s.old, c.currency)}</td><td className="p-2 text-right tabular-nums">{money(s.new, c.currency)}</td></tr>)}
                </Fragment>
              ))}</tbody></table>
          </div>
        )}
        <DialogFooter className="gap-2 sm:gap-2"><Button variant="outline" onClick={onClose} disabled={busy}>Cancel</Button><Button disabled={busy || !changes} onClick={() => call(false)} data-testid="bulk-apply">Apply changes</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function ImportDialog({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [res, setRes] = useState<LineImportResult | null>(null);
  const [charged, setCharged] = useState<Record<string, Charged>>({});
  const [dests, setDests] = useState<Record<string, { country: string; currency: string }>>({});
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => { if (open) { setFile(null); setRes(null); setCharged({}); setDests({}); setBusy(false); } }, [open]);
  const template = async () => {
    try { const r = await api.get("/transport/lines/import-template", { responseType: "blob" }); const a = document.createElement("a"); a.href = URL.createObjectURL(r.data as Blob); a.download = "transport-rates-template.csv"; a.click(); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  const run = async (dry: boolean, nextCharged = charged, nextDests = dests) => {
    if (!file) return; setBusy(true);
    try {
      const fd = new FormData(); fd.append("file", file); fd.append("dry_run", dry ? "1" : "0"); fd.append("choices", JSON.stringify({ charged: nextCharged, destinations: nextDests }));
      const { data } = await api.post<LineImportResult>("/transport/lines/import", fd);
      if (dry) setRes(data);                                                                  // only a check has the rows to show
      else { toast.success(`Imported: ${data.new} new, ${data.updated} updated, ${data.skipped} skipped`); onDone(); onClose(); }
    } catch (e) { toast.error(formatApiError(e)); } finally { setBusy(false); }
  };
  const pill = (s: string) => cn("rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide", s === "Ready" ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300" : s === "Check" ? "bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200" : "bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300");
  const importable = res ? res.summary.ready + res.rows.filter((r) => r.status === "Check" && ((r.needs === "charged" && charged[String(r.row)]) || (r.needs === "destination" && dests[r.destination]?.country && dests[r.destination]?.currency))).length : 0;
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[92vh] max-w-3xl overflow-y-auto" data-testid="line-import-dialog">
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">Import a rate sheet</DialogTitle><DialogDescription>Upload the supplier&apos;s Excel or CSV. Check what was understood, then import. Nothing is saved until you confirm.</DialogDescription></DialogHeader>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <input ref={input} type="file" accept=".csv,.xlsx" className="hidden" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setRes(null); setCharged({}); setDests({}); }} data-testid="line-import-file" />
          <Button type="button" variant="outline" onClick={() => input.current?.click()}><FileSpreadsheet size={14} /> {file ? file.name : "Choose the file"}</Button>
          <Button type="button" variant="outline" size="sm" onClick={template} data-testid="line-template"><Download size={14} /> Download blank template</Button>
          {file && !res && <Button type="button" disabled={busy} onClick={() => run(true)} data-testid="line-import-check">{busy ? <Loader2 size={14} className="animate-spin" /> : null} Check the file</Button>}
        </div>
        {res && (
          <div className="space-y-2" data-testid="line-import-result">
            <p className="text-sm text-muted-foreground">{res.summary.ready} ready · {res.summary.check} need a choice · {res.summary.missing} will be skipped</p>
            {(res.new_destinations ?? []).length > 0 && (
              <div className="space-y-2 rounded-md border border-dashed p-2" data-testid="import-new-destinations">
                {res.new_destinations!.map((d) => (
                  <div key={d.name} className="flex flex-wrap items-center gap-2 text-sm"><span className="min-w-[110px] font-medium">New: {d.name}</span>
                    <Input className="h-8 w-[140px]" value={dests[d.name]?.country ?? d.country} onChange={(e) => setDests({ ...dests, [d.name]: { country: e.target.value, currency: dests[d.name]?.currency ?? d.currency } })} aria-label={`Country of ${d.name}`} />
                    <Input className="h-8 w-[70px]" maxLength={3} value={dests[d.name]?.currency ?? d.currency} onChange={(e) => setDests({ ...dests, [d.name]: { country: dests[d.name]?.country ?? d.country, currency: e.target.value.toUpperCase() } })} aria-label={`Currency of ${d.name}`} />
                    <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => run(true, charged, { ...dests, [d.name]: { country: dests[d.name]?.country ?? d.country, currency: dests[d.name]?.currency ?? d.currency } })}>Use this</Button></div>
                ))}
              </div>
            )}
            <div className="max-h-72 overflow-y-auto rounded-md border">
              <table className="w-full text-sm"><thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="p-2">Check</th><th className="p-2">Type</th><th className="p-2">Route service</th><th className="p-2">Charged</th><th className="p-2 text-right">Price</th><th className="p-2">Note</th></tr></thead>
                <tbody>{res.rows.map((r) => (
                  <tr key={r.row} className="border-b align-top last:border-0" data-testid={`import-row-${r.row}`}>
                    <td className="p-2"><span className={pill(r.status)}>{r.status}</span></td><td className="p-2 uppercase text-xs">{r.type}</td>
                    <td className="p-2">{r.service}<div className="text-xs text-muted-foreground">{r.destination}</div></td>
                    <td className="p-2 text-xs">{r.charged ? chargedLabel(r.charged) : r.needs === "charged" ? (
                      <Select value={charged[String(r.row)] ?? ""} onValueChange={(v) => { const n = { ...charged, [String(r.row)]: v as Charged }; setCharged(n); void run(true, n); }}><SelectTrigger className="h-8 w-[120px]" data-testid={`import-charged-${r.row}`}><SelectValue placeholder="Choose" /></SelectTrigger>
                        <SelectContent>{CHARGED.map((c) => <SelectItem key={c.v} value={c.v}>{c.label}</SelectItem>)}</SelectContent></Select>) : "–"}</td>
                    <td className="p-2 text-right tabular-nums">{r.price == null ? "–" : r.currency ? money(r.price, r.currency) : r.price.toLocaleString("en-IN")}</td><td className="p-2 text-xs text-muted-foreground">{r.note}</td>
                  </tr>))}</tbody></table>
            </div>
          </div>
        )}
        <DialogFooter className="gap-2 sm:gap-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button type="button" disabled={!res || busy || importable === 0} onClick={() => run(false)} data-testid="line-import-go">Import {importable || ""} rate{importable === 1 ? "" : "s"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function RateList() {
  const { can } = useCurrentUser();
  const manage = can("transport.manage");
  const [rows, setRows] = useState<TransportLineRow[] | null>(null);
  const [look, setLook] = useState<LineLookups | null>(null);
  const [filter, setFilter] = useState<"all" | LineType | "needs_price">("all");
  const [q, setQ] = useState("");
  const [dlg, setDlg] = useState<{ open: boolean; item: TransportLineRow | null }>({ open: false, item: null });
  const [seasons, setSeasons] = useState<TransportLineRow | null>(null);
  const [importing, setImporting] = useState(false);
  const [menu, setMenu] = useState<string | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [bulk, setBulk] = useState<string[]>([]);
  const [dest, setDest] = useState("");                              // the destination filter; remembered for the next visit
  const [destOpen, setDestOpen] = useState(false);
  const [destQ, setDestQ] = useState("");
  useEffect(() => { try { setDest(window.localStorage.getItem(DEST_KEY) ?? ""); } catch { /* private window: no memory */ } }, []);
  const pickDest = (d: string) => { setDest(d); setDestOpen(false); setDestQ(""); try { if (d) window.localStorage.setItem(DEST_KEY, d); else window.localStorage.removeItem(DEST_KEY); } catch { /* ignore */ } };

  const load = useCallback(() => {
    api.get<TransportLineRow[]>("/transport/lines").then((r) => setRows(r.data)).catch((e) => toast.error(formatApiError(e)));
    api.get<LineLookups>("/transport/lines/lookups").then((r) => setLook(r.data)).catch(() => setLook(null));
  }, []);
  useEffect(() => { load(); }, [load]);

  const matchesText = useCallback((r: TransportLineRow) => {
    const t = q.trim().toLowerCase();
    return !t || [r.name, r.car_name, r.destination?.name, r.supplier, r.from_name, r.to_name].some((x) => (x ?? "").toLowerCase().includes(t));
  }, [q]);
  const matchesType = useCallback((r: TransportLineRow) => filter === "all" || (filter === "needs_price" ? r.needs_price : r.type === filter), [filter]);
  // the destination list counts what the type chips and the search leave
  const destCounts = useMemo(() => {
    const m = new Map<string, number>();
    (rows ?? []).filter((r) => matchesType(r) && matchesText(r)).forEach((r) => { const k = r.destination?.name ?? ""; if (k) m.set(k, (m.get(k) ?? 0) + 1); });
    return m;
  }, [rows, matchesType, matchesText]);
  const destNames = useMemo(() => Array.from(new Set((rows ?? []).map((r) => r.destination?.name ?? "").filter(Boolean))).sort((a, b) => a.localeCompare(b)), [rows]);
  useEffect(() => { if (rows && dest && !destNames.includes(dest)) pickDest(""); }, [rows, dest, destNames]);          // a remembered destination that has no rates any more is dropped
  const counts = useMemo(() => {
    const r = rows ?? [];
    return { all: r.length, cab: r.filter((x) => x.type === "cab").length, van: r.filter((x) => x.type === "van").length, bus: r.filter((x) => x.type === "bus").length, ferry: r.filter((x) => x.type === "ferry").length, needs_price: r.filter((x) => x.needs_price).length };
  }, [rows]);
  const shown = useMemo(() => (rows ?? []).filter((r) => matchesType(r) && matchesText(r) && (!dest || r.destination?.name === dest)), [rows, matchesType, matchesText, dest]);
  const groups = useMemo(() => {
    const m = new Map<string, { dest: TransportLineRow["destination"]; items: TransportLineRow[] }>();
    shown.forEach((r) => { const k = r.destination?.name ?? "–"; if (!m.has(k)) m.set(k, { dest: r.destination, items: [] }); m.get(k)!.items.push(r); });
    return Array.from(m.values());
  }, [shown]);

  const del = async (r: TransportLineRow) => { if (!window.confirm(`Delete "${r.name}"?`)) return; try { await api.delete(`/transport/lines/${r.id}`); load(); } catch (e) { toast.error(formatApiError(e)); } };
  const dup = async (r: TransportLineRow) => { try { await api.post(`/transport/lines/${r.id}/duplicate`); toast.success("Duplicated. Edit the copy."); load(); } catch (e) { toast.error(formatApiError(e)); } };
  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

  const empty = rows !== null && rows.length === 0;
  return (
    <div className="space-y-4" data-testid="rates-page">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div><div className="label-caps">Operations</div><h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Transport</h1></div>
        {manage && <div className="flex flex-wrap gap-2"><Button variant="outline" onClick={() => setImporting(true)} data-testid="rates-import-btn"><Upload size={15} /> Import from sheet</Button><Button onClick={() => setDlg({ open: true, item: null })} data-testid="rates-add-btn">+ Add transport</Button></div>}
      </div>
      <TransportTabs active="rates" />
      <Datalists look={look} />

      {empty && (
        <div data-testid="rates-first-time" className="space-y-3">
          <h2 className="font-display text-lg font-black">No rates yet. Pick the easiest way to start.</h2>
          <div className="grid gap-3 sm:grid-cols-3">
            <Card className="cursor-pointer rounded-lg p-4 hover:shadow-md" onClick={() => setImporting(true)}><div className="flex items-center gap-2 font-medium"><FileSpreadsheet size={16} /> Import a rate sheet</div><p className="mt-1 text-sm text-muted-foreground">Upload the supplier&apos;s Excel. We read it and you confirm.</p></Card>
            <Card className="cursor-pointer rounded-lg p-4 hover:shadow-md" onClick={() => setDlg({ open: true, item: null })}><div className="flex items-center gap-2 font-medium"><Plus size={16} /> Add the first rate</div><p className="mt-1 text-sm text-muted-foreground">Four fields: type, destination, route service, price.</p></Card>
            <Card className="cursor-pointer rounded-lg p-4 hover:shadow-md" onClick={async () => { try { const r = await api.get("/transport/lines/import-template", { responseType: "blob" }); const a = document.createElement("a"); a.href = URL.createObjectURL(r.data as Blob); a.download = "transport-rates-template.csv"; a.click(); } catch (e) { toast.error(formatApiError(e)); } }}><div className="flex items-center gap-2 font-medium"><Download size={16} /> Use a template</div><p className="mt-1 text-sm text-muted-foreground">Cab day rates, bus seats, airport transfers: download, fill, upload.</p></Card>
          </div>
        </div>
      )}

      {!empty && (
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative min-w-[220px] max-w-[320px] flex-1"><Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" /><Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search car, route or supplier" className="h-9 pl-9" data-testid="rates-search" /></div>
          <Popover open={destOpen} onOpenChange={setDestOpen}>
            <div className={cn("inline-flex h-9 items-center rounded-md border text-sm", dest ? "border-foreground bg-foreground text-background" : "border-border text-muted-foreground hover:bg-accent")}>
              <PopoverTrigger asChild><button type="button" className="inline-flex h-full items-center gap-1.5 px-3" data-testid="filter-destination"><MapPin size={14} />{dest || "Destination"}{!dest && <ChevronDown size={13} />}</button></PopoverTrigger>
              {dest && <button type="button" aria-label="Clear destination" onClick={() => pickDest("")} className="h-full rounded-r-md px-2 hover:bg-white/20" data-testid="filter-destination-clear"><X size={14} /></button>}
            </div>
            <PopoverContent align="start" className="w-64 p-2" data-testid="destination-list">
              <Input autoFocus value={destQ} onChange={(e) => setDestQ(e.target.value)} placeholder="Type a destination" className="mb-2 h-9" data-testid="destination-search" />
              <div className="max-h-64 overflow-y-auto">
                {destNames.filter((n) => n.toLowerCase().includes(destQ.trim().toLowerCase())).map((n) => (
                  <button key={n} type="button" onClick={() => pickDest(n)} className={cn("flex w-full items-center justify-between rounded px-2 py-1.5 text-left text-sm hover:bg-accent", n === dest && "font-semibold")} data-testid={`destination-${n}`}>
                    <span>{n}</span><span className="text-xs text-muted-foreground">{destCounts.get(n) ?? 0}</span>
                  </button>
                ))}
                {destNames.filter((n) => n.toLowerCase().includes(destQ.trim().toLowerCase())).length === 0 && <p className="px-2 py-3 text-center text-xs text-muted-foreground">No destination matches.</p>}
              </div>
            </PopoverContent>
          </Popover>
          {([["all", `All (${counts.all})`], ...TYPES.map((t) => [t.v, `${t.filter} (${counts[t.v]})`]), ["needs_price", `Needs a price (${counts.needs_price})`]] as [string, string][]).map(([k, l]) => (
            <button key={k} type="button" onClick={() => setFilter(k as typeof filter)} aria-pressed={filter === k} className={cn("h-9 rounded-md border px-3 text-sm", filter === k ? "border-foreground bg-foreground text-background" : "border-border text-muted-foreground hover:bg-accent")} data-testid={`filter-${k}`}>{l}</button>
          ))}
        </div>
      )}

      {manage && selected.length > 0 && (
        <div className="flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 px-3 py-2 text-sm" data-testid="bulk-bar">
          <span>{selected.length} rate{selected.length === 1 ? "" : "s"} selected</span>
          <Button size="sm" variant="outline" onClick={() => setBulk(selected)} data-testid="bulk-open">Change prices</Button>
          <button type="button" className="text-xs underline" onClick={() => setSelected([])}>Clear</button>
        </div>
      )}

      {rows === null && <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>}
      {rows && !empty && shown.length === 0 && <Card className="rounded-lg p-10 text-center text-sm text-muted-foreground" data-testid="rates-none">No rates match these filters.</Card>}
      {groups.length > 0 && (
        <Card className="overflow-x-auto rounded-lg">
          <table className="w-full min-w-[860px] text-sm">
            <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
              {manage && <th className="w-8 p-3" />}<th className="p-3">Type</th><th className="p-3">Car name</th><th className="p-3">Route service</th><th className="p-3">Charged</th><th className="p-3 text-right">Price</th><th className="p-3">Season</th><th className="p-3">Supplier</th>{manage && <th className="w-12 p-0" />}
            </tr></thead>
            <tbody>
              {groups.map((g) => (
                <Fragment key={g.dest?.name ?? "none"}>
                  <tr className="bg-secondary/50"><td colSpan={manage ? 9 : 7} className="px-3 py-2 text-sm font-semibold" data-testid={`group-${g.dest?.name}`}>{g.dest?.name}, {g.dest?.country} <span className="ml-2 text-xs font-normal text-muted-foreground">Currency {g.dest?.currency}</span></td></tr>
                  {g.items.map((r) => {
                    const T = TYPES.find((t) => t.v === r.type)!;
                    return (
                      <tr key={r.id} className="border-b border-border/60 last:border-0" data-testid={`rate-row-${r.id}`}>
                        {manage && <td className="p-3"><input type="checkbox" checked={selected.includes(r.id)} onChange={() => toggle(r.id)} aria-label={`Select ${r.name}`} className="accent-black dark:accent-white" data-testid={`rate-select-${r.id}`} /></td>}
                        <td className="p-3"><span className="inline-flex items-center gap-1 rounded bg-secondary px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"><T.icon size={11} />{typeLabel(r.type)}</span></td>
                        <td className="p-3 font-medium" data-testid={`rate-car-${r.id}`}>{r.car_name ?? <span className="font-normal text-muted-foreground">–</span>}</td>
                        <td className="p-3 font-medium" data-testid={`rate-service-${r.id}`}>{r.name}<div className="text-xs font-normal text-muted-foreground">{[r.seats ? `${r.seats} seats` : null, r.from_name && r.to_name ? `${r.from_name} → ${r.to_name}${r.way === "return" ? ", return" : r.way === "one_way" ? ", one way" : ""}` : null, r.note].filter(Boolean).join(" · ")}</div></td>
                        <td className="p-3">{chargedLabel(r.charged)}</td>
                        <td className="p-3 text-right tabular-nums">{r.needs_price ? <span className="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">Needs a price</span> : money(r.price, r.currency)}{r.seasons.length > 0 && <div className="text-[11px] text-muted-foreground">+ {r.seasons.length} season{r.seasons.length === 1 ? "" : "s"}</div>}</td>
                        <td className="p-3 text-xs">{r.season_name ?? "All year"}{r.valid_from && r.valid_to ? <div className="text-muted-foreground">{fmtDate(r.valid_from)} – {fmtDate(r.valid_to)}</div> : null}</td>
                        <td className="p-3 text-xs">{r.supplier ?? <span className="text-muted-foreground">–</span>}</td>
                        {manage && (
                          <td className="w-12 p-0 text-center align-middle">
                            <Popover open={menu === r.id} onOpenChange={(o) => setMenu(o ? r.id : null)}>
                              <PopoverTrigger asChild><button type="button" aria-label="Actions" className="mx-auto flex h-9 w-9 items-center justify-center rounded hover:bg-accent" data-testid={`rate-menu-${r.id}`}><MoreHorizontal size={16} /></button></PopoverTrigger>
                              <PopoverContent align="end" collisionPadding={8} className="w-40 p-1 text-left">
                                <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent" onClick={() => { setMenu(null); setDlg({ open: true, item: r }); }} data-testid={`rate-edit-${r.id}`}><Pencil size={13} /> Edit</button>
                                <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent" onClick={() => { setMenu(null); void dup(r); }} data-testid={`rate-dup-${r.id}`}><Copy size={13} /> Duplicate</button>
                                <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent" onClick={() => { setMenu(null); setSeasons(r); }} data-testid={`rate-season-${r.id}`}><Plus size={13} /> Add season</button>
                                <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm text-red-600 hover:bg-accent" onClick={() => { setMenu(null); void del(r); }}><Trash2 size={13} /> Delete</button>
                              </PopoverContent>
                            </Popover>
                          </td>
                        )}
                      </tr>
                    );
                  })}
                </Fragment>
              ))}
            </tbody>
          </table>
        </Card>
      )}
      <p className="text-xs text-muted-foreground">Flights and trains are details-only lists in their own tabs. Rates here price road transport on a quotation.</p>
      {manage && <RateDialog open={dlg.open} item={dlg.item} look={look} preset={{ type: filter === "all" || filter === "needs_price" ? "cab" : filter, destination: dest }} onClose={() => setDlg({ open: false, item: null })} onSaved={load} />}
      {manage && <SeasonDialog line={seasons ? (rows?.find((x) => x.id === seasons.id) ?? seasons) : null} onClose={() => setSeasons(null)} onChanged={load} />}
      {manage && <BulkDialog ids={bulk} rows={rows ?? []} onClose={() => setBulk([])} onDone={() => { setSelected([]); load(); }} />}
      {manage && <ImportDialog open={importing} onClose={() => setImporting(false)} onDone={load} />}
    </div>
  );
}
