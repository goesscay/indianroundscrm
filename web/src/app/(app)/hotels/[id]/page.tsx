"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Calculator, Loader2, Pencil, Plus, Star, Trash2, Upload } from "lucide-react";
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
import { INR, fmtDate } from "@/components/crm/shared";
import { cn } from "@/lib/utils";
import type { Hotel, HotelEstimate, HotelRate, HotelRoom, MealPlan, MediaItem } from "@/types";
import { Stars } from "@/components/hotels/Stars";

const MEALS: { v: MealPlan; l: string }[] = [{ v: "EP", l: "Room only" }, { v: "CP", l: "Breakfast" }, { v: "MAP", l: "Breakfast + dinner" }, { v: "AP", l: "All meals" }];
const mealLabel = (m: string) => MEALS.find((x) => x.v === m)?.l ?? m;
const PRICE_FIELDS: { k: keyof HotelRate; l: string; hint: string }[] = [
  { k: "single", l: "Single", hint: "One adult alone in the room" },
  { k: "double", l: "Double", hint: "Two adults sharing, per room" },
  { k: "triple", l: "Triple", hint: "Three adults in one room" },
  { k: "extra_adult", l: "Extra adult", hint: "Extra bed, per night" },
  { k: "child_with_bed", l: "Child with bed", hint: "Per night" },
  { k: "child_no_bed", l: "Child no bed", hint: "Per night" },
];
const today = () => new Date().toISOString().slice(0, 10);

function RateDialog({ room, rate, onClose, onSaved }: { room: HotelRoom | null; rate: HotelRate | null; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!room) return;
    setF(rate ? { meal_plan: rate.meal_plan, season: rate.season ?? "", start_date: rate.start_date, end_date: rate.end_date, ...Object.fromEntries(PRICE_FIELDS.map((p) => [p.k, rate[p.k] == null ? "" : String(rate[p.k])])) }
      : { meal_plan: "CP", season: "", start_date: "", end_date: "", ...Object.fromEntries(PRICE_FIELDS.map((p) => [p.k, ""])) });
  }, [room, rate]);
  const save = async (e: FormEvent) => {
    e.preventDefault(); if (!room) return;
    setBusy(true);
    const body = { meal_plan: f.meal_plan, season: f.season || null, start_date: f.start_date, end_date: f.end_date, ...Object.fromEntries(PRICE_FIELDS.map((p) => [p.k, f[p.k] === "" ? null : Number(f[p.k])])) };
    try {
      if (rate) await api.patch(`/hotel-rates/${rate.id}`, body); else await api.post(`/hotel-rooms/${room.id}/rates`, body);
      toast.success("Rate saved"); onSaved(); onClose();
    } catch (err) { toast.error(formatApiError(err)); }
    finally { setBusy(false); }
  };
  return (
    <Dialog open={!!room} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-w-lg" data-testid="rate-dialog">
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">{rate ? "Edit rate" : "Add a rate"}</DialogTitle><DialogDescription>{room?.name}. What the hotel charges you per night for these dates.</DialogDescription></DialogHeader>
        <form onSubmit={save} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label className="label-caps">Meal plan</Label>
              <Select value={f.meal_plan ?? "CP"} onValueChange={(v) => setF({ ...f, meal_plan: v })}>
                <SelectTrigger className="mt-1.5" data-testid="rate-meal"><SelectValue /></SelectTrigger>
                <SelectContent>{MEALS.map((m) => <SelectItem key={m.v} value={m.v}>{m.v} · {m.l}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <div><Label className="label-caps">Season name</Label><Input className="mt-1.5" value={f.season ?? ""} onChange={(e) => setF({ ...f, season: e.target.value })} placeholder="e.g. Peak" /></div>
            <div><Label className="label-caps">From</Label><Input type="date" className="mt-1.5" value={f.start_date ?? ""} onChange={(e) => setF({ ...f, start_date: e.target.value })} required data-testid="rate-from" /></div>
            <div><Label className="label-caps">To (last night)</Label><Input type="date" className="mt-1.5" value={f.end_date ?? ""} onChange={(e) => setF({ ...f, end_date: e.target.value })} required data-testid="rate-to" /></div>
          </div>
          <div className="grid grid-cols-3 gap-3">
            {PRICE_FIELDS.map((p) => (
              <div key={String(p.k)}>
                <Label className="label-caps" title={p.hint}>{p.l} ₹</Label>
                <Input type="number" min={0} step="1" className="mt-1.5" value={f[p.k as string] ?? ""} onChange={(e) => setF({ ...f, [p.k as string]: e.target.value })} data-testid={`rate-${String(p.k)}`} />
              </div>
            ))}
          </div>
          <p className="text-[11px] text-muted-foreground">Enter at least the double (or single) rate. A blank single uses the double rate. A blank child-with-bed uses the extra-adult rate.</p>
          <DialogFooter className="gap-2 sm:gap-2"><Button type="button" variant="outline" onClick={onClose} disabled={busy}>Cancel</Button><Button type="submit" disabled={busy} data-testid="rate-save">{busy ? "Saving…" : "Save rate"}</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function RoomDialog({ hotelId, room, open, onClose, onSaved }: { hotelId: string; room: HotelRoom | null; open: boolean; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ name: "", max_adults: "2", notes: "" });
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (open) setF({ name: room?.name ?? "", max_adults: String(room?.max_adults ?? 2), notes: room?.notes ?? "" }); }, [open, room]);
  const save = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true);
    try {
      const body = { name: f.name, max_adults: Number(f.max_adults) || 2, notes: f.notes || null };
      if (room) await api.patch(`/hotel-rooms/${room.id}`, body); else await api.post(`/hotels/${hotelId}/rooms`, body);
      onSaved(); onClose();
    } catch (err) { toast.error(formatApiError(err)); }
    finally { setBusy(false); }
  };
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-w-md" data-testid="room-dialog">
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">{room ? "Edit room type" : "New room type"}</DialogTitle><DialogDescription>For example Deluxe Room with Balcony View.</DialogDescription></DialogHeader>
        <form onSubmit={save} className="space-y-3">
          <div><Label className="label-caps">Room type</Label><Input className="mt-1.5" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required autoFocus data-testid="room-name" /></div>
          <div><Label className="label-caps">Most adults it sleeps</Label><Input type="number" min={1} max={6} className="mt-1.5 w-24" value={f.max_adults} onChange={(e) => setF({ ...f, max_adults: e.target.value })} /><p className="mt-1 text-[11px] text-muted-foreground">2 = a double room (an odd adult takes a single room). 3 or more allows the triple rate.</p></div>
          <div><Label className="label-caps">Notes (optional)</Label><Input className="mt-1.5" value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></div>
          <DialogFooter className="gap-2 sm:gap-2"><Button type="button" variant="outline" onClick={onClose} disabled={busy}>Cancel</Button><Button type="submit" disabled={busy} data-testid="room-save">{busy ? "Saving…" : "Save"}</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PriceCheck({ hotel }: { hotel: Hotel }) {
  const rooms = (hotel.rooms ?? []).filter((r) => r.active);
  const [f, setF] = useState({ room_id: "", meal_plan: "CP", check_in: today(), nights: "2", adults: "2", child_with_bed: "0", child_no_bed: "0" });
  const [res, setRes] = useState<HotelEstimate | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (!f.room_id && rooms[0]) setF((x) => ({ ...x, room_id: rooms[0].id })); }, [rooms, f.room_id]);
  const run = async () => {
    setBusy(true);
    try { const { data } = await api.get<HotelEstimate>(`/hotels/${hotel.id}/estimate`, { params: f }); setRes(data); }
    catch (e) { toast.error(formatApiError(e)); setRes(null); }
    finally { setBusy(false); }
  };
  const num = (k: keyof typeof f) => (
    <Input type="number" min={0} className="mt-1.5" value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
  );
  return (
    <Card className="rounded-lg p-5" data-testid="price-check">
      <h3 className="flex items-center gap-2 font-display text-lg font-bold"><Calculator size={16} /> Price check</h3>
      <p className="mt-0.5 text-xs text-muted-foreground">Try a stay and see the cost, your margin and the selling price. This is the same maths the quotation will use.</p>
      {rooms.length === 0 ? <p className="mt-3 text-sm text-muted-foreground">Add a room type and its rates first.</p> : (
        <>
          <div className="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
            <div className="col-span-2">
              <Label className="label-caps">Room</Label>
              <Select value={f.room_id} onValueChange={(v) => setF({ ...f, room_id: v })}><SelectTrigger className="mt-1.5"><SelectValue /></SelectTrigger><SelectContent>{rooms.map((r) => <SelectItem key={r.id} value={r.id}>{r.name}</SelectItem>)}</SelectContent></Select>
            </div>
            <div>
              <Label className="label-caps">Meal plan</Label>
              <Select value={f.meal_plan} onValueChange={(v) => setF({ ...f, meal_plan: v })}><SelectTrigger className="mt-1.5"><SelectValue /></SelectTrigger><SelectContent>{MEALS.map((m) => <SelectItem key={m.v} value={m.v}>{m.v} · {m.l}</SelectItem>)}</SelectContent></Select>
            </div>
            <div><Label className="label-caps">Check-in</Label><Input type="date" className="mt-1.5" value={f.check_in} onChange={(e) => setF({ ...f, check_in: e.target.value })} /></div>
            <div><Label className="label-caps">Nights</Label>{num("nights")}</div>
            <div><Label className="label-caps">Adults</Label>{num("adults")}</div>
            <div><Label className="label-caps">Children with bed</Label>{num("child_with_bed")}</div>
            <div><Label className="label-caps">Children no bed</Label>{num("child_no_bed")}</div>
          </div>
          <Button className="mt-3" onClick={run} disabled={busy || !f.room_id} data-testid="price-run">{busy ? <Loader2 size={14} className="animate-spin" /> : null} Work it out</Button>
        </>
      )}
      {res && (
        <div className="mt-4 space-y-3" data-testid="price-result">
          {res.warnings.length > 0 && <ul className="list-disc space-y-0.5 rounded-md bg-amber-50 p-3 pl-7 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{res.warnings.map((w) => <li key={w}>{w}</li>)}</ul>}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div className="rounded-md border border-border p-3"><div className="label-caps">Vendor cost</div><div className="font-display text-xl font-black" data-testid="price-cost">{INR(res.cost)}</div></div>
            <div className="rounded-md border border-border p-3"><div className="label-caps">Margin ({res.margin.type === "percent" ? `${res.margin.value}%` : `₹${res.margin.value}/person`} · {res.margin.source})</div><div className="font-display text-xl font-black" data-testid="price-margin">{INR(res.margin_amount)}</div></div>
            <div className="rounded-md border border-border bg-secondary/40 p-3"><div className="label-caps">Selling price</div><div className="font-display text-xl font-black" data-testid="price-selling">{INR(res.selling)}</div></div>
            <div className="rounded-md border border-border p-3"><div className="label-caps">Per person ({res.persons})</div><div className="font-display text-xl font-black" data-testid="price-pp">{INR(res.per_person)}</div></div>
          </div>
          <div className="text-xs text-muted-foreground">Rooms: {res.rooms.length ? res.rooms.join(" + ") : "none"}. Night by night: {res.nights.map((n) => `${fmtDate(n.date)} ${n.cost == null ? "no rate" : INR(n.cost)}${n.season ? ` (${n.season})` : ""}`).join(" · ")}</div>
        </div>
      )}
    </Card>
  );
}

export default function HotelDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const manage = can("hotels.manage");
  const [h, setH] = useState<Hotel | null>(null);
  const [tab, setTab] = useState<"details" | "photos" | "rooms" | "price">("details");
  const [err, setErr] = useState("");
  const [roomDlg, setRoomDlg] = useState<{ open: boolean; room: HotelRoom | null }>({ open: false, room: null });
  const [rateDlg, setRateDlg] = useState<{ room: HotelRoom | null; rate: HotelRate | null }>({ room: null, rate: null });
  const [busy, setBusy] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const [f, setF] = useState({ name: "", destination: "", city: "", stars: "none", address: "", phone: "", website: "", google_rating: "", google_reviews: "", description: "", amenities: "", margin_type: "inherit", margin_value: "", active: true });

  const load = useCallback(async () => {
    try { const { data } = await api.get<Hotel>(`/hotels/${id}`); setH(data); setErr(""); } catch (e) { setErr(formatApiError(e)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    if (!h) return;
    setF({
      name: h.name, destination: h.destination, city: h.city ?? "", stars: h.stars ? String(h.stars) : "none", address: h.address ?? "", phone: h.phone ?? "", website: h.website ?? "",
      google_rating: h.google_rating == null ? "" : String(h.google_rating), google_reviews: h.google_reviews == null ? "" : String(h.google_reviews), description: h.description ?? "",
      amenities: (h.amenities ?? []).join(", "), margin_type: h.margin_type ?? "inherit", margin_value: h.margin_value == null ? "" : String(h.margin_value), active: h.active,
    });
  }, [h]);

  const save = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true);
    try {
      await api.patch(`/hotels/${id}`, {
        name: f.name, destination: f.destination, city: f.city || null, stars: f.stars === "none" ? null : Number(f.stars), address: f.address || null, phone: f.phone || null, website: f.website || null,
        google_rating: f.google_rating === "" ? null : Number(f.google_rating), google_reviews: f.google_reviews === "" ? null : Number(f.google_reviews), description: f.description || null,
        amenities: f.amenities.split(",").map((x) => x.trim()).filter(Boolean), active: f.active,
        ...(manage ? { margin_type: f.margin_type === "inherit" ? null : f.margin_type, margin_value: f.margin_type === "inherit" || f.margin_value === "" ? null : Number(f.margin_value) } : {}),
      });
      toast.success("Saved"); await load();
    } catch (err2) { toast.error(formatApiError(err2)); }
    finally { setBusy(false); }
  };
  const remove = async () => {
    if (!window.confirm(`Delete ${h?.name}? Its rooms, rates and photos go too. (To just stop offering it, switch it off instead.)`)) return;
    try { await api.delete(`/hotels/${id}`); toast.success("Deleted"); router.replace("/hotels"); } catch (e) { toast.error(formatApiError(e)); }
  };
  const upload = async (files: FileList | null) => {
    if (!files?.length) return;
    setBusy(true);
    try {
      const fd = new FormData(); Array.from(files).forEach((x) => fd.append("files[]", x));
      await api.post(`/hotels/${id}/photos`, fd); toast.success("Photos added"); await load();
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); if (fileInput.current) fileInput.current.value = ""; }
  };
  const photo = async (m: MediaItem, body: Record<string, unknown>) => { try { await api.patch(`/media/${m.id}`, body); await load(); } catch (e) { toast.error(formatApiError(e)); } };
  const delPhoto = async (m: MediaItem) => { if (!window.confirm("Delete this photo?")) return; try { await api.delete(`/media/${m.id}`); await load(); } catch (e) { toast.error(formatApiError(e)); } };
  const delRoom = async (r: HotelRoom) => { if (!window.confirm(`Delete "${r.name}" and all its rates?`)) return; try { await api.delete(`/hotel-rooms/${r.id}`); await load(); } catch (e) { toast.error(formatApiError(e)); } };
  const delRate = async (r: HotelRate) => { if (!window.confirm("Delete this rate?")) return; try { await api.delete(`/hotel-rates/${r.id}`); await load(); } catch (e) { toast.error(formatApiError(e)); } };

  const tabs = useMemo(() => [{ k: "details", l: "Details" }, { k: "photos", l: `Photos${h?.photos ? ` (${h.photos.length})` : ""}` }, { k: "rooms", l: "Rooms" + (manage ? " and rates" : "") }, ...(manage ? [{ k: "price", l: "Price check" }] : [])] as { k: typeof tab; l: string }[], [h, manage]);

  if (err) return <div className="space-y-3"><Button variant="ghost" size="sm" onClick={() => router.push("/hotels")}><ArrowLeft size={16} /> Hotels</Button><div className="text-sm text-destructive">{err}</div></div>;
  if (!h) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;
  const set = (k: keyof typeof f, v: string | boolean) => setF({ ...f, [k]: v });

  return (
    <div className="space-y-4" data-testid="hotel-detail">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.push("/hotels")} className="-ml-3 mb-1 h-8"><ArrowLeft size={16} /> Hotels</Button>
          <div className="flex flex-wrap items-center gap-3"><h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl" data-testid="hotel-title">{h.name}</h1><Stars n={h.stars} />{!h.active && <span className="rounded bg-secondary px-2 py-0.5 text-xs">Switched off</span>}</div>
          <p className="mt-1 text-sm text-muted-foreground">{h.destination}{h.city ? ` · ${h.city}` : ""}{h.google_rating ? ` · ★ ${h.google_rating}${h.google_reviews ? ` (${h.google_reviews.toLocaleString("en-IN")} reviews)` : ""}` : ""}</p>
        </div>
        {manage && <Button variant="outline" onClick={remove} data-testid="hotel-delete"><Trash2 size={15} className="text-destructive" /> Delete</Button>}
      </div>

      <div className="flex gap-1 border-b border-border" role="tablist">
        {tabs.map((t) => (
          <button key={t.k} type="button" role="tab" aria-selected={tab === t.k} onClick={() => setTab(t.k)} data-testid={`hotel-tab-${t.k}`}
                  className={cn("-mb-px border-b-2 px-3 py-2 text-[13px] font-medium", tab === t.k ? "border-primary text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}>{t.l}</button>
        ))}
      </div>

      {tab === "details" && (
        <form onSubmit={save} className="space-y-4">
          <Card className="rounded-lg p-5">
            <fieldset disabled={!manage} className="grid gap-3 sm:grid-cols-2">
              <div><Label className="label-caps">Hotel name</Label><Input className="mt-1.5" value={f.name} onChange={(e) => set("name", e.target.value)} required data-testid="d-name" /></div>
              <div><Label className="label-caps">Destination</Label><Input className="mt-1.5" value={f.destination} onChange={(e) => set("destination", e.target.value)} required /></div>
              <div><Label className="label-caps">Area</Label><Input className="mt-1.5" value={f.city} onChange={(e) => set("city", e.target.value)} /></div>
              <div>
                <Label className="label-caps">Star category</Label>
                <Select value={f.stars} onValueChange={(v) => set("stars", v)}><SelectTrigger className="mt-1.5"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Not rated</SelectItem>{[1, 2, 3, 4, 5].map((n) => <SelectItem key={n} value={String(n)}>{n} star</SelectItem>)}</SelectContent></Select>
              </div>
              <div className="sm:col-span-2"><Label className="label-caps">Address</Label><Textarea rows={2} className="mt-1.5" value={f.address} onChange={(e) => set("address", e.target.value)} /></div>
              <div><Label className="label-caps">Phone</Label><Input className="mt-1.5" value={f.phone} onChange={(e) => set("phone", e.target.value)} /></div>
              <div><Label className="label-caps">Website</Label><Input className="mt-1.5" value={f.website} onChange={(e) => set("website", e.target.value)} placeholder="www.hotel.com" /></div>
              <div><Label className="label-caps">Google rating</Label><Input type="number" step="0.1" min={0} max={5} className="mt-1.5" value={f.google_rating} onChange={(e) => set("google_rating", e.target.value)} /></div>
              <div><Label className="label-caps">Number of reviews</Label><Input type="number" min={0} className="mt-1.5" value={f.google_reviews} onChange={(e) => set("google_reviews", e.target.value)} /></div>
              <div className="sm:col-span-2"><Label className="label-caps">About the hotel</Label><Textarea rows={3} className="mt-1.5" value={f.description} onChange={(e) => set("description", e.target.value)} /></div>
              <div className="sm:col-span-2"><Label className="label-caps">Amenities (separate with commas)</Label><Input className="mt-1.5" value={f.amenities} onChange={(e) => set("amenities", e.target.value)} placeholder="Wi-Fi, Restaurant, Parking, Room heater" /></div>
              <label className="flex cursor-pointer items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={f.active} onChange={(e) => set("active", e.target.checked)} className="accent-black dark:accent-white" /> Offer this hotel on quotations</label>
            </fieldset>
          </Card>
          {manage && (
            <Card className="rounded-lg p-5" data-testid="hotel-margin">
              <h3 className="font-display text-lg font-bold">Margin</h3>
              <p className="mt-0.5 text-xs text-muted-foreground">What we add to the vendor rate. Leave it on &quot;Use the standard margin&quot; unless this hotel is different. Currently {h.margin ? `${h.margin.type === "percent" ? `${h.margin.value}%` : `₹${h.margin.value} per person`} (${h.margin.source === "hotel" ? "set on this hotel" : h.margin.source === "destination" ? `the ${h.destination} margin` : "the default margin"})` : ""}.</p>
              <div className="mt-3 flex flex-wrap items-end gap-3">
                <div>
                  <Label className="label-caps">Margin</Label>
                  <Select value={f.margin_type} onValueChange={(v) => set("margin_type", v)}><SelectTrigger className="mt-1.5 w-[260px]" data-testid="margin-type"><SelectValue /></SelectTrigger>
                    <SelectContent><SelectItem value="inherit">Use the standard margin</SelectItem><SelectItem value="percent">A percentage of the cost</SelectItem><SelectItem value="amount">A fixed amount per person</SelectItem></SelectContent></Select>
                </div>
                {f.margin_type !== "inherit" && <div><Label className="label-caps">{f.margin_type === "percent" ? "Percent" : "₹ per person"}</Label><Input type="number" min={0} className="mt-1.5 w-32" value={f.margin_value} onChange={(e) => set("margin_value", e.target.value)} data-testid="margin-value" /></div>}
              </div>
            </Card>
          )}
          {manage && <Button type="submit" disabled={busy} data-testid="hotel-save">{busy ? "Saving…" : "Save changes"}</Button>}
        </form>
      )}

      {tab === "photos" && (
        <Card className="rounded-lg p-5" data-testid="hotel-photos">
          {manage && (
            <div className="mb-4 flex flex-wrap items-center gap-3">
              <input ref={fileInput} type="file" multiple accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => void upload(e.target.files)} data-testid="photo-input" />
              <Button type="button" onClick={() => fileInput.current?.click()} disabled={busy}>{busy ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />} Add photos</Button>
              <span className="text-xs text-muted-foreground">JPG, PNG or WebP, up to 15 at a time. They are resized for the quotation. The first one is the cover.</span>
            </div>
          )}
          {(h.photos?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">No photos yet.</p>}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            {h.photos?.map((m) => (
              <div key={m.id} className="overflow-hidden rounded-md border border-border" data-testid={`photo-${m.id}`}>
                <div className="relative aspect-[4/3] bg-secondary">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={m.thumb_url} alt={m.caption ?? h.name} className="h-full w-full object-cover" loading="lazy" />
                  {m.is_cover && <span className="absolute left-2 top-2 rounded bg-foreground px-1.5 py-0.5 text-[10px] font-bold uppercase text-background">Cover</span>}
                </div>
                {manage && (
                  <div className="flex items-center justify-between gap-1 p-1.5">
                    {!m.is_cover ? <Button type="button" size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={() => void photo(m, { is_cover: true })} data-testid={`photo-cover-${m.id}`}><Star size={12} /> Make cover</Button> : <span />}
                    <Button type="button" size="sm" variant="ghost" className="h-7 px-2" onClick={() => void delPhoto(m)} aria-label="Delete photo"><Trash2 size={13} className="text-destructive" /></Button>
                  </div>
                )}
              </div>
            ))}
          </div>
        </Card>
      )}

      {tab === "rooms" && (
        <div className="space-y-3" data-testid="hotel-rooms">
          {manage && <Button onClick={() => setRoomDlg({ open: true, room: null })} data-testid="room-add"><Plus size={15} /> New room type</Button>}
          {(h.rooms?.length ?? 0) === 0 && <Card className="rounded-lg p-6 text-sm text-muted-foreground">No room types yet.</Card>}
          {h.rooms?.map((r) => (
            <Card key={r.id} className="rounded-lg p-4" data-testid={`room-${r.id}`}>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div><div className="font-semibold">{r.name} {!r.active && <span className="text-xs font-normal text-muted-foreground">(off)</span>}</div><div className="text-xs text-muted-foreground">Sleeps up to {r.max_adults} adult{r.max_adults === 1 ? "" : "s"}{r.notes ? ` · ${r.notes}` : ""}</div></div>
                {manage && (
                  <div className="flex gap-1">
                    <Button size="sm" variant="outline" onClick={() => setRateDlg({ room: r, rate: null })} data-testid={`rate-add-${r.id}`}><Plus size={13} /> Rate</Button>
                    <Button size="sm" variant="ghost" onClick={() => setRoomDlg({ open: true, room: r })} aria-label="Edit room"><Pencil size={14} /></Button>
                    <Button size="sm" variant="ghost" onClick={() => void delRoom(r)} aria-label="Delete room"><Trash2 size={14} className="text-destructive" /></Button>
                  </div>
                )}
              </div>
              {manage && (
                r.rates.length === 0 ? <p className="mt-3 text-xs text-muted-foreground">No rates yet. Add the season rates the hotel gave you.</p> : (
                  <div className="mt-3 overflow-x-auto">
                    <table className="w-full text-left text-xs">
                      <thead><tr className="border-b border-border text-muted-foreground"><th className="py-1.5 pr-3">Meal</th><th className="pr-3">Season</th><th className="pr-3">Dates</th>{PRICE_FIELDS.map((p) => <th key={String(p.k)} className="pr-3 text-right">{p.l}</th>)}<th /></tr></thead>
                      <tbody>
                        {r.rates.map((x) => (
                          <tr key={x.id} className="border-b border-border/60" data-testid={`rate-row-${x.id}`}>
                            <td className="py-1.5 pr-3 font-medium" title={mealLabel(x.meal_plan)}>{x.meal_plan}</td><td className="pr-3">{x.season ?? "—"}</td>
                            <td className="whitespace-nowrap pr-3">{fmtDate(x.start_date)} → {fmtDate(x.end_date)}</td>
                            {PRICE_FIELDS.map((p) => <td key={String(p.k)} className="pr-3 text-right tabular-nums">{x[p.k] == null ? "—" : INR(x[p.k] as number)}</td>)}
                            <td className="whitespace-nowrap text-right"><Button size="sm" variant="ghost" className="h-6 px-1.5" onClick={() => setRateDlg({ room: r, rate: x })} aria-label="Edit rate"><Pencil size={12} /></Button><Button size="sm" variant="ghost" className="h-6 px-1.5" onClick={() => void delRate(x)} aria-label="Delete rate"><Trash2 size={12} className="text-destructive" /></Button></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )
              )}
            </Card>
          ))}
        </div>
      )}

      {tab === "price" && manage && <PriceCheck hotel={h} />}

      {manage && <RoomDialog hotelId={h.id} room={roomDlg.room} open={roomDlg.open} onClose={() => setRoomDlg({ open: false, room: null })} onSaved={() => void load()} />}
      {manage && <RateDialog room={rateDlg.room} rate={rateDlg.rate} onClose={() => setRateDlg({ room: null, rate: null })} onSaved={() => void load()} />}
      <p className="text-xs text-muted-foreground"><Link href="/hotels" className="underline">Back to all hotels</Link></p>
    </div>
  );
}
