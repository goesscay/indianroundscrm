"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Download, Hotel as HotelIcon, Loader2, Search, Upload } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import { Stars } from "@/components/hotels/Stars";
import type { Hotel } from "@/types";

interface ImportResult { dry_run: boolean; hotels_new: number; rooms_seen: number; rates_new: number; rates_updated: number; errors: { row: number; error: string }[] }

function ImportDialog({ open, onOpenChange, onDone }: { open: boolean; onOpenChange: (o: boolean) => void; onDone: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [res, setRes] = useState<ImportResult | null>(null);
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => { if (open) { setFile(null); setRes(null); setBusy(false); } }, [open]);

  const template = async () => {
    try {
      const r = await api.get("/hotels/import-template", { responseType: "blob" });
      const a = document.createElement("a"); a.href = URL.createObjectURL(r.data as Blob); a.download = "hotel-rates-template.csv"; a.click();
    } catch (e) { toast.error(formatApiError(e)); }
  };
  const run = async (dry: boolean) => {
    if (!file) return;
    setBusy(true);
    try {
      const fd = new FormData(); fd.append("file", file); fd.append("dry_run", dry ? "1" : "0");
      const { data } = await api.post<ImportResult>("/hotels/import", fd);
      setRes(data);
      if (!dry) { toast.success(`Imported: ${data.hotels_new} new hotel${data.hotels_new === 1 ? "" : "s"}, ${data.rates_new} new and ${data.rates_updated} updated rates`); onDone(); onOpenChange(false); }
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
      <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto" data-testid="hotel-import-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">Import hotels and rates</DialogTitle>
          <DialogDescription>One row per hotel, room, meal plan and season. Hotels and rooms that are new are created; rows for rates that exist are updated.</DialogDescription>
        </DialogHeader>
        <div className="space-y-3 text-sm">
          <Button type="button" variant="outline" size="sm" onClick={template} data-testid="hotel-template"><Download size={14} /> Download the template (CSV)</Button>
          <p className="text-xs text-muted-foreground">Meal plans: EP room only, CP breakfast, MAP breakfast and dinner, AP all meals. Rates are per night, in rupees, as the hotel charges you. Dates like 2026-12-20 or 20/12/2026. Save an Excel sheet as .xlsx or .csv.</p>
          <div>
            <input ref={input} type="file" accept=".csv,.xlsx" className="hidden" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setRes(null); }} data-testid="hotel-import-file" />
            <Button type="button" variant="outline" onClick={() => input.current?.click()}><Upload size={14} /> {file ? file.name : "Choose the file"}</Button>
          </div>
          {res && (
            <div className="rounded-md border border-border p-3" data-testid="hotel-import-result">
              <div className="font-medium">{res.dry_run ? "Check: nothing has been saved yet" : "Done"}</div>
              <div className="mt-1 text-xs text-muted-foreground">{res.hotels_new} new hotel{res.hotels_new === 1 ? "" : "s"} · {res.rooms_seen} room type{res.rooms_seen === 1 ? "" : "s"} · {res.rates_new} new rate{res.rates_new === 1 ? "" : "s"} · {res.rates_updated} updated</div>
              {res.errors.length > 0 && (
                <ul className="mt-2 max-h-36 list-disc space-y-0.5 overflow-y-auto pl-5 text-xs text-red-700 dark:text-red-400">{res.errors.map((e, i) => <li key={i}>Row {e.row}: {e.error}</li>)}</ul>
              )}
            </div>
          )}
        </div>
        <DialogFooter className="gap-2 sm:gap-2">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>Close</Button>
          <Button type="button" variant="outline" onClick={() => run(true)} disabled={!file || busy} data-testid="hotel-import-check">{busy ? <Loader2 size={14} className="animate-spin" /> : null} Check first</Button>
          <Button type="button" onClick={() => run(false)} disabled={!file || busy || (res?.dry_run === true && res.errors.length > 0 && res.rates_new + res.rates_updated === 0)} data-testid="hotel-import-go">Import</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function NewHotelDialog({ open, onOpenChange, destinations }: { open: boolean; onOpenChange: (o: boolean) => void; destinations: string[] }) {
  const router = useRouter();
  const [f, setF] = useState({ name: "", destination: "", city: "", stars: "3" });
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (open) setF({ name: "", destination: "", city: "", stars: "3" }); }, [open]);
  const save = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true);
    try {
      const { data } = await api.post<Hotel>("/hotels", { name: f.name, destination: f.destination, city: f.city || null, stars: f.stars && f.stars !== "none" ? Number(f.stars) : null });
      toast.success("Hotel added. Now add its photos and rooms."); onOpenChange(false); router.push(`/hotels/${data.id}`);
    } catch (err) { toast.error(formatApiError(err)); }
    finally { setBusy(false); }
  };
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
      <DialogContent className="max-w-md" data-testid="new-hotel-dialog">
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">New hotel</DialogTitle><DialogDescription>The rest (address, photos, rooms and rates) is added on the hotel&apos;s page.</DialogDescription></DialogHeader>
        <form onSubmit={save} className="space-y-3">
          <div><Label className="label-caps">Hotel name</Label><Input className="mt-1.5" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required autoFocus data-testid="hotel-name" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><Label className="label-caps">Destination</Label><Input className="mt-1.5" list="hotel-destinations" value={f.destination} onChange={(e) => setF({ ...f, destination: e.target.value })} required placeholder="e.g. Manali" data-testid="hotel-destination" />
              <datalist id="hotel-destinations">{destinations.map((d) => <option key={d} value={d} />)}</datalist></div>
            <div><Label className="label-caps">Area (optional)</Label><Input className="mt-1.5" value={f.city} onChange={(e) => setF({ ...f, city: e.target.value })} placeholder="e.g. Simsa" /></div>
          </div>
          <div>
            <Label className="label-caps">Star category</Label>
            <Select value={f.stars} onValueChange={(v) => setF({ ...f, stars: v })}>
              <SelectTrigger className="mt-1.5"><SelectValue /></SelectTrigger>
              <SelectContent><SelectItem value="none">Not rated</SelectItem>{[1, 2, 3, 4, 5].map((n) => <SelectItem key={n} value={String(n)}>{n} star</SelectItem>)}</SelectContent>
            </Select>
          </div>
          <DialogFooter className="gap-2 sm:gap-2"><Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>Cancel</Button><Button type="submit" disabled={busy} data-testid="hotel-create">{busy ? "Adding…" : "Add hotel"}</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function HotelsPage() {
  const { can } = useCurrentUser();
  const manage = can("hotels.manage");
  const [hotels, setHotels] = useState<Hotel[] | null>(null);
  const [destinations, setDestinations] = useState<string[]>([]);
  const [q, setQ] = useState("");
  const [dest, setDest] = useState("all");
  const [stars, setStars] = useState("all");
  const [showInactive, setShowInactive] = useState(false);
  const [adding, setAdding] = useState(false);
  const [importing, setImporting] = useState(false);

  const load = useCallback(() => {
    api.get<Hotel[]>("/hotels", { params: { active: showInactive ? "0" : "1" } }).then((r) => setHotels(r.data)).catch((e) => toast.error(formatApiError(e)));
    api.get<string[]>("/hotels/destinations").then((r) => setDestinations(r.data)).catch(() => setDestinations([]));
  }, [showInactive]);
  useEffect(() => { load(); }, [load]);

  const shown = useMemo(() => (hotels ?? []).filter((h) => {
    const t = q.trim().toLowerCase();
    return (dest === "all" || h.destination === dest) && (stars === "all" || String(h.stars ?? "") === stars)
      && (!t || h.name.toLowerCase().includes(t) || (h.city ?? "").toLowerCase().includes(t) || h.destination.toLowerCase().includes(t));
  }), [hotels, q, dest, stars]);

  return (
    <div className="space-y-4" data-testid="hotels-page">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="label-caps">Operations</div>
          <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Hotels</h1>
          <p className="mt-1 text-xs text-muted-foreground">{manage ? "The hotels we sell, with photos, rooms and vendor rates. Quotations choose from here." : "The hotels we sell. Pick one when you build a quotation."}</p>
        </div>
        {manage && (
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => setImporting(true)} data-testid="hotel-import-btn"><Upload size={15} /> Import rates</Button>
            <Button onClick={() => setAdding(true)} data-testid="hotel-new-btn">+ New hotel</Button>
          </div>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[220px] max-w-[320px] flex-1">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search hotel, area or destination" className="h-9 pl-9" data-testid="hotel-search" />
        </div>
        <Select value={dest} onValueChange={setDest}>
          <SelectTrigger className="h-9 w-[180px]" data-testid="hotel-filter-destination"><SelectValue placeholder="All destinations" /></SelectTrigger>
          <SelectContent><SelectItem value="all">All destinations</SelectItem>{destinations.map((d) => <SelectItem key={d} value={d}>{d}</SelectItem>)}</SelectContent>
        </Select>
        <Select value={stars} onValueChange={setStars}>
          <SelectTrigger className="h-9 w-[130px]"><SelectValue placeholder="Any stars" /></SelectTrigger>
          <SelectContent><SelectItem value="all">Any stars</SelectItem>{[5, 4, 3, 2, 1].map((n) => <SelectItem key={n} value={String(n)}>{n} star</SelectItem>)}</SelectContent>
        </Select>
        {manage && <label className="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground"><input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} className="accent-black dark:accent-white" /> Show switched-off hotels</label>}
        <span className="text-xs text-muted-foreground">{shown.length} hotel{shown.length === 1 ? "" : "s"}</span>
      </div>

      {hotels === null && <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>}
      {hotels && shown.length === 0 && (
        <Card className="rounded-lg p-10 text-center text-sm text-muted-foreground" data-testid="hotels-empty">
          {hotels.length === 0 ? (manage ? "No hotels yet. Add one, or import your rate sheet." : "No hotels yet. Operations adds them.") : "No hotels match these filters."}
        </Card>
      )}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        {shown.map((h) => (
          <Link key={h.id} href={`/hotels/${h.id}`} data-testid={`hotel-card-${h.id}`}>
            <Card className={cn("h-full overflow-hidden rounded-lg transition-shadow hover:shadow-md", !h.active && "opacity-60")}>
              <div className="aspect-[16/10] bg-secondary">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                {h.cover ? <img src={h.cover.thumb_url} alt={h.name} className="h-full w-full object-cover" loading="lazy" /> : <div className="flex h-full items-center justify-center text-muted-foreground"><HotelIcon size={30} /></div>}
              </div>
              <div className="p-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0"><div className="truncate font-semibold leading-tight">{h.name}</div><div className="truncate text-xs text-muted-foreground">{h.destination}{h.city ? ` · ${h.city}` : ""}</div></div>
                  <Stars n={h.stars} className="shrink-0" />
                </div>
                <div className="mt-2 flex items-center justify-between text-[11px] text-muted-foreground">
                  <span>{h.google_rating ? `★ ${h.google_rating}${h.google_reviews ? ` (${h.google_reviews.toLocaleString("en-IN")})` : ""}` : "No rating"}</span>
                  <span>{h.rooms_count ?? 0} room type{h.rooms_count === 1 ? "" : "s"}{!h.active ? " · off" : ""}</span>
                </div>
              </div>
            </Card>
          </Link>
        ))}
      </div>
      {manage && <NewHotelDialog open={adding} onOpenChange={setAdding} destinations={destinations} />}
      {manage && <ImportDialog open={importing} onOpenChange={setImporting} onDone={load} />}
    </div>
  );
}
