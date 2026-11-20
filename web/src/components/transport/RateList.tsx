"use client";

import { Fragment, useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Bus, Car, ChevronDown, Copy, Download, FileSpreadsheet, Loader2, MoreHorizontal, Pencil, Plus, Search, Ship, Trash2, Truck, Upload } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
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
const routeType = (t: LineType) => t === "bus" || t === "ferry";

interface Form {
  type: LineType; destination: string; country: string; dest_currency: string; name: string; from_name: string; to_name: string; way: "one_way" | "return"; price: string; currency: string; charged: Charged;
  supplier: string; season_name: string; valid_from: string; valid_to: string; seats: string; hours_per_day: string; km_included: string; extra_hour: string; note: string;
}
const blank = (type: LineType = "cab", destination = ""): Form => ({ type, destination, country: "India", dest_currency: "INR", name: "", from_name: "", to_name: "", way: "one_way", price: "", currency: "", charged: defaultCharged(type),
  supplier: "", season_name: "", valid_from: "", valid_to: "", seats: "", hours_per_day: "", km_included: "", extra_hour: "", note: "" });

function Datalists({ look }: { look: LineLookups | null }) {
  return (
    <>
      <datalist id="tl-destinations">{(look?.destinations ?? []).map((d) => <option key={d.name} value={d.name}>{d.country}</option>)}</datalist>
      <datalist id="tl-places">{(look?.places ?? []).map((p) => <option key={p.name} value={p.name}>{`Used on ${p.rates} rate${p.rates === 1 ? "" : "s"}`}</option>)}</datalist>
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
    setF(item ? { type: item.type, destination: item.destination?.name ?? "", country: item.destination?.country ?? "India", dest_currency: item.destination?.currency ?? "INR", name: item.name, from_name: item.from_name ?? "", to_name: item.to_name ?? "",
      way: item.way ?? "one_way", price: item.price == null ? "" : String(item.price), currency: item.currency, charged: item.charged, supplier: item.supplier ?? "", season_name: item.season_name ?? "", valid_from: item.valid_from ?? "", valid_to: item.valid_to ?? "",
      seats: item.seats == null ? "" : String(item.seats), hours_per_day: item.hours_per_day == null ? "" : String(item.hours_per_day), km_included: item.km_included == null ? "" : String(item.km_included),
      extra_hour: item.extra_hour == null ? "" : String(item.extra_hour), note: item.note ?? "" } : blank(preset.type, preset.destination));
    if (item && (item.supplier || item.season_name || item.valid_from || item.seats || item.hours_per_day || item.km_included || item.extra_hour || item.note)) setMore(true);
  }, [open, item, preset.type, preset.destination]);
  const dest = useDestination(look, f.destination);
  const isNew = f.destination.trim() !== "" && !dest?.known && !item;
  const curr = f.currency || dest?.currency || f.dest_currency || "INR";
  const save = async (e: FormEvent, again: boolean) => {
    e.preventDefault(); setBusy(true);
    const body = {
      type: f.type, destination: f.destination.trim(), country: isNew ? f.country : undefined, dest_currency: isNew ? f.dest_currency : undefined, name: f.name, price: Number(f.price), charged: f.charged,
      currency: f.currency || undefined, supplier: f.supplier || null, from_name: routeType(f.type) ? f.from_name || null : null, to_name: routeType(f.type) ? f.to_name || null : null, way: routeType(f.type) ? f.way : null,
      season_name: f.season_name || null, valid_from: f.valid_from || null, valid_to: f.valid_to || null, seats: num(f.seats), hours_per_day: num(f.hours_per_day), km_included: num(f.km_included), extra_hour: num(f.extra_hour), note: f.note || null,
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
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">{item ? "Edit transport rate" : "Add transport rate"}</DialogTitle><DialogDescription>Only the destination, the service name and the price are needed. Everything else is optional.</DialogDescription></DialogHeader>
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
          <div><Label className="label-caps">Service name</Label><Input className="mt-1.5" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required placeholder={routeType(f.type) ? "Volvo, semi-sleeper" : "Ertiga, 6 seats: local sightseeing"} data-testid="rate-name" /></div>
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
          <button type="button" onClick={() => setMore((m) => !m)} className="flex items-center gap-1 text-sm underline" data-testid="rate-more"><ChevronDown size={14} className={cn(more && "rotate-180")} /> {more ? "Hide details" : "More details: supplier, season dates, seats, hours (optional)"}</button>
          {more && (
            <div className="space-y-3 rounded-md border p-3" data-testid="rate-more-fields">
              <div className="grid grid-cols-2 gap-3">
                <div className="col-span-2"><Label className="text-xs">Supplier</Label><Input className="mt-1 h-9" list="tl-suppliers" value={f.supplier} onChange={(e) => setF({ ...f, supplier: e.target.value })} placeholder="Manali Taxi Service" data-testid="rate-supplier" /></div>
                <div><Label className="text-xs">Season name</Label><Input className="mt-1 h-9" value={f.season_name} onChange={(e) => setF({ ...f, season_name: e.target.value })} placeholder="Off season" /></div>
                <div />
                <div><Label className="text-xs">Valid from</Label><Input className="mt-1 h-9" type="date" value={f.valid_from} onChange={(e) => setF({ ...f, valid_from: e.target.value })} data-testid="rate-valid-from" /></div>
                <div><Label className="text-xs">Valid to</Label><Input className="mt-1 h-9" type="date" value={f.valid_to} onChange={(e) => setF({ ...f, valid_to: e.target.value })} data-testid="rate-valid-to" /></div>
                <div><Label className="text-xs">Seats</Label><Input className="mt-1 h-9" type="number" min={1} value={f.seats} onChange={(e) => setF({ ...f, seats: e.target.value })} data-testid="rate-seats" /></div>
                <div><Label className="text-xs">Hours per day</Label><Input className="mt-1 h-9" type="number" min={0} step="any" value={f.hours_per_day} onChange={(e) => setF({ ...f, hours_per_day: e.target.value })} /></div>
                <div><Label className="text-xs">Km included</Label><Input className="mt-1 h-9" type="number" min={0} value={f.km_included} onChange={(e) => setF({ ...f, km_included: e.target.value })} /></div>
                <div><Label className="text-xs">Extra hour</Label><Input className="mt-1 h-9" type="number" min={0} step="any" value={f.extra_hour} onChange={(e) => setF({ ...f, extra_hour: e.target.value })} /></div>
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
  const add = (e: FormEvent) => { e.preventDefault(); void apply(() => api.post<TransportLineRow>(`/transport/lines/${line.id}/seasons`, { name: f.name, valid_from: f.valid_from || null, valid_to: f.valid_to || null, price: Number(f.price) })).then(() => setF({ name: "", valid_from: "", valid_to: "", price: "" })); };
  return (
    <Dialog open={!!line} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-w-xl" data-testid="season-dialog">
        <DialogHeader><DialogTitle className="font-display text-xl font-black">Seasons</DialogTitle><DialogDescription>{line.name}. {line.destination?.name} · {chargedLabel(line.charged)}. The line keeps one row; each season has its own dates and price.</DialogDescription></DialogHeader>
        <table className="w-full text-sm"><thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="py-2">Season</th><th>From</th><th>To</th><th className="text-right">Price</th><th /></tr></thead>
          <tbody>
            <tr className="border-b"><td className="py-2">{line.season_name ?? "All year"} <span className="text-xs text-muted-foreground">(main price)</span></td><td>{line.valid_from ? fmtDate(line.valid_from) : "–"}</td><td>{line.valid_to ? fmtDate(line.valid_to) : "–"}</td><td className="text-right tabular-nums">{money(line.price, line.currency)}</td><td /></tr>
            {rows.map((s) => (
              <tr key={s.id} className="border-b last:border-0" data-testid={`season-row-${s.id}`}><td className="py-2">{s.name}</td><td>{s.valid_from ? fmtDate(s.valid_from) : <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">Dates needed</span>}</td><td>{s.valid_to ? fmtDate(s.valid_to) : "–"}</td>
                <td className="text-right tabular-nums">{money(s.price, line.currency)}</td><td className="text-right"><button type="button" aria-label="Delete season" className="rounded p-1.5 hover:bg-accent" onClick={() => apply(() => api.delete<TransportLineRow>(`/transport/line-seasons/${s.id}`))}><Trash2 size={13} /></button></td></tr>
            ))}
          </tbody></table>
        <form onSubmit={add} className="grid grid-cols-[1.2fr_1fr_1fr_90px_auto] items-end gap-2" data-testid="season-form">
          <div><Label className="text-xs">Season</Label><Input className="mt-1 h-9" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required placeholder="Peak" data-testid="season-name" /></div>
          <div><Label className="text-xs">From</Label><Input className="mt-1 h-9" type="date" value={f.valid_from} onChange={(e) => setF({ ...f, valid_from: e.target.value })} /></div>
          <div><Label className="text-xs">To</Label><Input className="mt-1 h-9" type="date" value={f.valid_to} onChange={(e) => setF({ ...f, valid_to: e.target.value })} /></div>
          <div><Label className="text-xs">Price</Label><Input className="mt-1 h-9" type="number" min={0} step="any" value={f.price} onChange={(e) => setF({ ...f, price: e.target.value })} required data-testid="season-price" /></div>
          <Button type="submit" size="sm" disabled={busy} data-testid="season-add"><Plus size={14} /> Add season</Button>
        </form>
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
              <table className="w-full text-sm"><thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="p-2">Check</th><th className="p-2">Type</th><th className="p-2">Service</th><th className="p-2">Charged</th><th className="p-2 text-right">Price</th><th className="p-2">Note</th></tr></thead>
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
  // the quick add line
  const [qa, setQa] = useState({ type: "cab" as LineType, destination: "", country: "India", currency: "INR", name: "", price: "", charged: "per_day" as Charged });
  const [qaBusy, setQaBusy] = useState(false);

  const load = useCallback(() => {
    api.get<TransportLineRow[]>("/transport/lines").then((r) => setRows(r.data)).catch((e) => toast.error(formatApiError(e)));
    api.get<LineLookups>("/transport/lines/lookups").then((r) => setLook(r.data)).catch(() => setLook(null));
  }, []);
  useEffect(() => { load(); }, [load]);

  const counts = useMemo(() => {
    const r = rows ?? [];
    return { all: r.length, cab: r.filter((x) => x.type === "cab").length, van: r.filter((x) => x.type === "van").length, bus: r.filter((x) => x.type === "bus").length, ferry: r.filter((x) => x.type === "ferry").length, needs_price: r.filter((x) => x.needs_price).length };
  }, [rows]);
  const shown = useMemo(() => (rows ?? []).filter((r) => {
    const t = q.trim().toLowerCase();
    return (filter === "all" || (filter === "needs_price" ? r.needs_price : r.type === filter))
      && (!t || [r.name, r.destination?.name, r.supplier, r.from_name, r.to_name].some((x) => (x ?? "").toLowerCase().includes(t)));
  }), [rows, filter, q]);
  const groups = useMemo(() => {
    const m = new Map<string, { dest: TransportLineRow["destination"]; items: TransportLineRow[] }>();
    shown.forEach((r) => { const k = r.destination?.name ?? "–"; if (!m.has(k)) m.set(k, { dest: r.destination, items: [] }); m.get(k)!.items.push(r); });
    return Array.from(m.values());
  }, [shown]);

  const qaDest = look?.destinations.find((d) => d.name.toLowerCase() === qa.destination.trim().toLowerCase()) ?? null;
  const qaNew = qa.destination.trim() !== "" && !qaDest?.known;
  const quickAdd = async (e: FormEvent) => {
    e.preventDefault(); setQaBusy(true);
    try {
      await api.post("/transport/lines", { type: qa.type, destination: qa.destination.trim(), country: qaNew ? qa.country : undefined, dest_currency: qaNew ? qa.currency : undefined, name: qa.name, price: Number(qa.price), charged: qa.charged });
      toast.success("Rate added"); setQa({ ...qa, name: "", price: "" }); load();
    } catch (err) { toast.error(formatApiError(err)); } finally { setQaBusy(false); }
  };
  const del = async (r: TransportLineRow) => { if (!window.confirm(`Delete "${r.name}"?`)) return; try { await api.delete(`/transport/lines/${r.id}`); load(); } catch (e) { toast.error(formatApiError(e)); } };
  const dup = async (r: TransportLineRow) => { try { await api.post(`/transport/lines/${r.id}/duplicate`); toast.success("Duplicated. Edit the copy."); load(); } catch (e) { toast.error(formatApiError(e)); } };
  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

  const empty = rows !== null && rows.length === 0;
  return (
    <div className="space-y-4" data-testid="rates-page">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div><div className="label-caps">Operations</div><h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Transport</h1></div>
        {manage && <div className="flex flex-wrap gap-2"><Button variant="outline" onClick={() => setImporting(true)} data-testid="rates-import-btn"><Upload size={15} /> Import from sheet</Button><Button onClick={() => setDlg({ open: true, item: null })} data-testid="rates-add-btn">+ Add rate</Button></div>}
      </div>
      <TransportTabs active="rates" />
      <Datalists look={look} />

      {empty && (
        <div data-testid="rates-first-time" className="space-y-3">
          <h2 className="font-display text-lg font-black">No rates yet. Pick the easiest way to start.</h2>
          <div className="grid gap-3 sm:grid-cols-3">
            <Card className="cursor-pointer rounded-lg p-4 hover:shadow-md" onClick={() => setImporting(true)}><div className="flex items-center gap-2 font-medium"><FileSpreadsheet size={16} /> Import a rate sheet</div><p className="mt-1 text-sm text-muted-foreground">Upload the supplier&apos;s Excel. We read it and you confirm.</p></Card>
            <Card className="cursor-pointer rounded-lg p-4 hover:shadow-md" onClick={() => setDlg({ open: true, item: null })}><div className="flex items-center gap-2 font-medium"><Plus size={16} /> Add the first rate</div><p className="mt-1 text-sm text-muted-foreground">Four fields: type, destination, name, price.</p></Card>
            <Card className="cursor-pointer rounded-lg p-4 hover:shadow-md" onClick={async () => { try { const r = await api.get("/transport/lines/import-template", { responseType: "blob" }); const a = document.createElement("a"); a.href = URL.createObjectURL(r.data as Blob); a.download = "transport-rates-template.csv"; a.click(); } catch (e) { toast.error(formatApiError(e)); } }}><div className="flex items-center gap-2 font-medium"><Download size={16} /> Use a template</div><p className="mt-1 text-sm text-muted-foreground">Cab day rates, bus seats, airport transfers: download, fill, upload.</p></Card>
          </div>
        </div>
      )}

      {manage && !empty && (
        <form onSubmit={quickAdd} className="rounded-lg border bg-muted/30 p-3" data-testid="quick-add">
          <div className="mb-2 text-xs font-medium text-muted-foreground">Quick add: fill one line and press Add</div>
          <div className="flex flex-wrap items-end gap-2">
            <div className="w-[130px]"><Select value={qa.type} onValueChange={(v) => setQa({ ...qa, type: v as LineType, charged: defaultCharged(v as LineType) })}><SelectTrigger className="h-9" data-testid="qa-type"><SelectValue /></SelectTrigger><SelectContent>{TYPES.map((t) => <SelectItem key={t.v} value={t.v}>{t.label}</SelectItem>)}</SelectContent></Select></div>
            <Input className="h-9 w-[150px]" list="tl-destinations" placeholder="Destination" value={qa.destination} onChange={(e) => setQa({ ...qa, destination: e.target.value })} required data-testid="qa-destination" />
            <Input className="h-9 min-w-[200px] flex-1" placeholder="Service, e.g. Ertiga local sightseeing" value={qa.name} onChange={(e) => setQa({ ...qa, name: e.target.value })} required data-testid="qa-name" />
            <Input className="h-9 w-[110px]" type="number" min={0} step="any" placeholder="Price" value={qa.price} onChange={(e) => setQa({ ...qa, price: e.target.value })} required data-testid="qa-price" />
            <div className="w-[120px]"><Select value={qa.charged} onValueChange={(v) => setQa({ ...qa, charged: v as Charged })}><SelectTrigger className="h-9" data-testid="qa-charged"><SelectValue /></SelectTrigger><SelectContent>{CHARGED.map((c) => <SelectItem key={c.v} value={c.v}>{c.label}</SelectItem>)}</SelectContent></Select></div>
            <Button type="submit" disabled={qaBusy} data-testid="qa-add">{qaBusy ? <Loader2 size={14} className="animate-spin" /> : null} Add</Button>
          </div>
          {qaNew && (
            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground" data-testid="qa-new-destination">
              {qaDest ? `${qaDest.name} is in Hotels already.` : "A new destination."} Confirm its country and currency once:
              <Input className="h-8 w-[130px]" value={qa.country} onChange={(e) => setQa({ ...qa, country: e.target.value })} aria-label="Country" data-testid="qa-country" />
              <Input className="h-8 w-[70px]" maxLength={3} value={qa.currency} onChange={(e) => setQa({ ...qa, currency: e.target.value.toUpperCase() })} aria-label="Currency" data-testid="qa-currency" />
            </div>
          )}
          {qaDest?.known && <div className="mt-2 text-xs text-muted-foreground">{qaDest.name}, {qaDest.country} · currency {qaDest.currency}</div>}
        </form>
      )}

      {!empty && (
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative min-w-[220px] max-w-[320px] flex-1"><Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" /><Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search service, place or supplier" className="h-9 pl-9" data-testid="rates-search" /></div>
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
              {manage && <th className="w-8 p-3" />}<th className="p-3">Type</th><th className="p-3">Service</th><th className="p-3">Charged</th><th className="p-3 text-right">Price</th><th className="p-3">Season</th><th className="p-3">Supplier</th>{manage && <th className="w-10 p-3" />}
            </tr></thead>
            <tbody>
              {groups.map((g) => (
                <Fragment key={g.dest?.name ?? "none"}>
                  <tr className="bg-secondary/50"><td colSpan={manage ? 8 : 6} className="px-3 py-2 text-sm font-semibold" data-testid={`group-${g.dest?.name}`}>{g.dest?.name}, {g.dest?.country} <span className="ml-2 text-xs font-normal text-muted-foreground">Currency {g.dest?.currency}</span></td></tr>
                  {g.items.map((r) => {
                    const T = TYPES.find((t) => t.v === r.type)!;
                    return (
                      <tr key={r.id} className="border-b border-border/60 last:border-0" data-testid={`rate-row-${r.id}`}>
                        {manage && <td className="p-3"><input type="checkbox" checked={selected.includes(r.id)} onChange={() => toggle(r.id)} aria-label={`Select ${r.name}`} className="accent-black dark:accent-white" data-testid={`rate-select-${r.id}`} /></td>}
                        <td className="p-3"><span className="inline-flex items-center gap-1 rounded bg-secondary px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"><T.icon size={11} />{typeLabel(r.type)}</span></td>
                        <td className="p-3 font-medium">{r.name}<div className="text-xs font-normal text-muted-foreground">{[r.seats ? `${r.seats} seats` : null, r.from_name && r.to_name ? `${r.from_name} → ${r.to_name}${r.way === "return" ? ", return" : r.way === "one_way" ? ", one way" : ""}` : null, r.note].filter(Boolean).join(" · ")}</div></td>
                        <td className="p-3">{chargedLabel(r.charged)}</td>
                        <td className="p-3 text-right tabular-nums">{r.needs_price ? <span className="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">Needs a price</span> : money(r.price, r.currency)}{r.seasons.length > 0 && <div className="text-[11px] text-muted-foreground">+ {r.seasons.length} season{r.seasons.length === 1 ? "" : "s"}</div>}</td>
                        <td className="p-3 text-xs">{r.season_name ?? "All year"}{r.valid_from && r.valid_to ? <div className="text-muted-foreground">{fmtDate(r.valid_from)} – {fmtDate(r.valid_to)}</div> : null}</td>
                        <td className="p-3 text-xs">{r.supplier ?? <span className="text-muted-foreground">–</span>}</td>
                        {manage && (
                          <td className="relative p-3 text-right">
                            <button type="button" aria-label="Actions" onClick={() => setMenu(menu === r.id ? null : r.id)} className="rounded p-1 hover:bg-accent" data-testid={`rate-menu-${r.id}`}><MoreHorizontal size={16} /></button>
                            {menu === r.id && (
                              <div className="absolute right-2 top-10 z-10 w-40 rounded-md border border-border bg-background p-1 text-left shadow-md">
                                <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent" onClick={() => { setMenu(null); setDlg({ open: true, item: r }); }} data-testid={`rate-edit-${r.id}`}><Pencil size={13} /> Edit</button>
                                <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent" onClick={() => { setMenu(null); void dup(r); }} data-testid={`rate-dup-${r.id}`}><Copy size={13} /> Duplicate</button>
                                <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent" onClick={() => { setMenu(null); setSeasons(r); }} data-testid={`rate-season-${r.id}`}><Plus size={13} /> Add season</button>
                                <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm text-red-600 hover:bg-accent" onClick={() => { setMenu(null); void del(r); }}><Trash2 size={13} /> Delete</button>
                              </div>
                            )}
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
      {manage && <RateDialog open={dlg.open} item={dlg.item} look={look} preset={{ type: qa.type, destination: qa.destination }} onClose={() => setDlg({ open: false, item: null })} onSaved={load} />}
      {manage && <SeasonDialog line={seasons ? (rows?.find((x) => x.id === seasons.id) ?? seasons) : null} onClose={() => setSeasons(null)} onChanged={load} />}
      {manage && <BulkDialog ids={bulk} rows={rows ?? []} onClose={() => setBulk([])} onDone={() => { setSelected([]); load(); }} />}
      {manage && <ImportDialog open={importing} onClose={() => setImporting(false)} onDone={load} />}
    </div>
  );
}
