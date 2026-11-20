"use client";

import { useEffect, useMemo, useState } from "react";
import { AlertTriangle, Bus, Car, Plane, Plus, Ship, Train, Trash2, Truck } from "lucide-react";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { INR } from "@/components/crm/shared";
import { cn } from "@/lib/utils";
import type { LegMode, ProposalComputed, ProposalData, ProposalLeg, TransportProvider } from "@/types";

const NONE = "none";
const MODES: { v: LegMode; label: string; icon: typeof Car }[] = [
  { v: "flight", label: "Flight", icon: Plane }, { v: "train", label: "Train", icon: Train }, { v: "bus", label: "Bus", icon: Bus },
  { v: "cab", label: "Cab", icon: Car }, { v: "van", label: "Tempo / van", icon: Truck }, { v: "ferry", label: "Ferry", icon: Ship },
];
const TYPED: LegMode[] = ["flight", "train"];
const modeLabel = (m: string) => MODES.find((x) => x.v === m)?.label ?? m;
const num = (v: string) => (v.trim() === "" ? null : Number(v));
const newId = () => `l${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;

type SetFn = (fn: (d: ProposalData) => void) => void;

/** The Transport section of the premium quotation: legs per option, cab scope for the destination, and the exchange rates held on this quotation. */
export default function ProposalTransport({ p, set, computed, manager }: { p: ProposalData; set: SetFn; computed: ProposalComputed | null; manager: boolean }) {
  const [providers, setProviders] = useState<TransportProvider[]>([]);
  const [details, setDetails] = useState<Record<string, TransportProvider>>({});
  const [newCur, setNewCur] = useState("");
  const [extra, setExtra] = useState<string[]>([]);
  const dest = p.trip.destination;

  useEffect(() => { api.get<TransportProvider[]>("/transport/providers").then((r) => setProviders(r.data)).catch(() => setProviders([])); }, []);
  const usedProviders = useMemo(() => Array.from(new Set(p.options.flatMap((o) => (o.legs ?? []).map((l) => l.provider_id).filter((x) => x != null).map(String)))), [p.options]);
  useEffect(() => {
    usedProviders.filter((id) => !details[id]).forEach((id) => {
      api.get<TransportProvider>(`/transport/providers/${id}`).then((r) => setDetails((c) => ({ ...c, [id]: r.data }))).catch(() => null);
    });
  }, [usedProviders, details]);
  const loadDetail = (id: string) => { if (!details[id]) api.get<TransportProvider>(`/transport/providers/${id}`).then((r) => setDetails((c) => ({ ...c, [id]: r.data }))).catch(() => null); };

  const scope = (dest && p.transport?.cab_scope?.[dest]) || "stay";
  // the server sends an empty map as [] (a PHP empty array); make it an object before a key is added to it
  const ensureTransport = (d: ProposalData) => {
    const t = (d.transport ??= { fx: { date: null, rates: {}, safety_pct: 0 }, cab_scope: {} });
    if (Array.isArray(t.fx.rates)) t.fx.rates = {};
    if (Array.isArray(t.cab_scope)) t.cab_scope = {};
    return t;
  };

  const addLeg = (oi: number, mode: LegMode) => set((d) => {
    const o = d.options[oi]; o.legs ??= [];
    const cabs = o.legs.filter((l) => l.mode === "cab" || l.mode === "van").length;
    const stay = (d.transport?.cab_scope?.[d.trip.destination] ?? "stay") === "stay";
    const isCab = mode === "cab" || mode === "van";
    o.legs.push({
      id: newId(), mode, destination: d.trip.destination || null, provider_id: null, vehicle_id: null, route_id: null, qty: null,
      day_offset: isCab ? (stay ? 0 : Math.min(cabs, Math.max(0, d.trip.nights))) : 0, days: isCab && stay ? Math.max(1, d.trip.nights + 1) : 1, legs: 1,
      title: null, from: null, to: null, depart: null, arrive: null, class: null, baggage: null, reference: null, fare_pp: null, child_fare_pp: null, currency: "INR", included: true, note: null,
    });
  });
  const patch = (oi: number, li: number, fn: (l: ProposalLeg) => void) => set((d) => { const l = d.options[oi].legs?.[li]; if (l) fn(l); });
  const remove = (oi: number, li: number) => set((d) => { d.options[oi].legs?.splice(li, 1); });

  // currencies this quotation needs a rate for: those the priced legs use, plus any already typed
  const needed = useMemo(() => {
    const s = new Set<string>([...Object.keys(p.transport?.fx.rates ?? {}), ...extra]);
    computed?.options.forEach((o) => { o.actual.transport?.fx_needed.forEach((c) => s.add(c)); o.actual.transport?.legs.forEach((l) => { if (l.currency !== "INR") s.add(l.currency); }); });
    p.options.forEach((o) => (o.legs ?? []).forEach((l) => { if (l.currency !== "INR") s.add(l.currency); }));
    return Array.from(s).sort();
  }, [computed, p, extra]);
  const rates = p.transport?.fx.rates ?? {};

  return (
    <div className="space-y-4" data-testid="proposal-transport">
      <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border bg-muted/30 p-3 text-sm" data-testid="cab-scope">
        <span className="font-medium">Cabs in {dest || "the destination"}</span>
        {(["stay", "daily"] as const).map((s) => (
          <label key={s} className="flex cursor-pointer items-center gap-1.5">
            <input type="radio" name="cab-scope" checked={scope === s} onChange={() => set((d) => { if (d.trip.destination) ensureTransport(d).cab_scope[d.trip.destination] = s; })} data-testid={`cab-scope-${s}`} />
            {s === "stay" ? "Same cab for the whole stay" : "Cab day by day (a different one each day)"}
          </label>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-x-6 gap-y-2 rounded-md border bg-muted/30 p-3 text-sm" data-testid="customer-wording">
        <span className="font-medium">What the customer sees</span>
        <div className="w-[260px]"><Label className="text-xs">Vehicle and airline names</Label>
          <Select value={p.transport?.wording ?? "named"} onValueChange={(v) => set((d) => { ensureTransport(d).wording = v as "named" | "general"; })}>
            <SelectTrigger className="mt-1 h-9" data-testid="wording-select"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem value="named">Show them (Private Ertiga, Volvo bus)</SelectItem><SelectItem value="general">General only (Private cab, Bus)</SelectItem></SelectContent>
          </Select></div>
        <div className="w-[260px]"><Label className="text-xs">Transport price</Label>
          <Select value={p.transport?.tax_display ?? "with_gst"} onValueChange={(v) => set((d) => { ensureTransport(d).tax_display = v as "with_gst" | "before_gst"; })}>
            <SelectTrigger className="mt-1 h-9" data-testid="taxdisplay-select"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem value="with_gst">Show the total including GST</SelectItem><SelectItem value="before_gst">Show before GST (GST extra)</SelectItem></SelectContent>
          </Select></div>
        <span className="text-xs text-muted-foreground">Provider names, costs and margin are never shown to the customer.</span>
      </div>

      {p.options.map((o, oi) => {
        const legs = o.legs ?? [];
        const priced = computed?.options[oi]?.actual.transport;
        return (
          <div key={oi} className="rounded-lg border p-3" data-testid={`transport-option-${oi}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="font-medium">{o.name}{p.selected_option === oi && <span className="ml-2 rounded bg-primary/10 px-1.5 py-0.5 text-xs text-primary">quote</span>}</div>
              <div className="flex flex-wrap gap-1.5">
                {MODES.map((m) => <Button key={m.v} type="button" variant="outline" size="sm" onClick={() => addLeg(oi, m.v)} data-testid={`add-leg-${oi}-${m.v}`}><m.icon size={14} /> {m.label}</Button>)}
              </div>
            </div>
            {legs.length === 0 && <p className="mt-3 text-sm text-muted-foreground">No transport on this option. Add a flight, train, bus, cab, van or ferry. Or use the manual Land package line below.</p>}
            <div className="mt-3 space-y-2">
              {[...legs.keys()].sort((a, b) => legs[a].day_offset - legs[b].day_offset).map((li) => {
                const l = legs[li];
                const price = priced?.legs.find((x) => x.id === l.id);
                const typed = TYPED.includes(l.mode);
                const prov = l.provider_id != null ? details[String(l.provider_id)] : undefined;
                const cands = providers.filter((x) => (x.modes as string[]).includes(l.mode) || (l.mode === "van" && x.modes.includes("cab")) || String(x.id) === String(l.provider_id));
                const vehicles = (prov?.vehicles ?? []).filter((v) => v.active);
                const veh = vehicles.find((v) => String(v.id) === String(l.vehicle_id));
                const personBasis = price?.basis === "per_person" || l.mode === "bus";
                return (
                  <div key={l.id} className={cn("rounded-md bg-muted/40 p-2", price?.rate_needed && "ring-1 ring-amber-500/60")} data-testid={`leg-${oi}-${li}`}>
                    <div className="flex flex-wrap items-end gap-2">
                      <span className="mb-2 flex items-center gap-1 rounded bg-background px-2 py-1 text-xs font-bold">{(() => { const M = MODES.find((x) => x.v === l.mode)!.icon; return <M size={13} />; })()} {modeLabel(l.mode)}</span>
                      <div className="w-[72px]"><Label className="text-xs">Day</Label><Input type="number" min={1} value={l.day_offset + 1} onChange={(e) => patch(oi, li, (x) => { x.day_offset = Math.max(0, (Number(e.target.value) || 1) - 1); })} className="mt-1 h-9" aria-label="Day" /></div>
                      {typed ? (<>
                        <div className="min-w-[160px] flex-1"><Label className="text-xs">{l.mode === "flight" ? "Airline and flight" : "Train name and number"}</Label><Input value={l.title ?? ""} onChange={(e) => patch(oi, li, (x) => { x.title = e.target.value || null; })} className="mt-1 h-9" data-testid={`leg-title-${oi}-${li}`} /></div>
                        <div className="w-[120px]"><Label className="text-xs">From</Label><Input value={l.from ?? ""} onChange={(e) => patch(oi, li, (x) => { x.from = e.target.value || null; })} className="mt-1 h-9" /></div>
                        <div className="w-[120px]"><Label className="text-xs">To</Label><Input value={l.to ?? ""} onChange={(e) => patch(oi, li, (x) => { x.to = e.target.value || null; })} className="mt-1 h-9" /></div>
                        <div className="w-[84px]"><Label className="text-xs">Departs</Label><Input value={l.depart ?? ""} placeholder="06:30" onChange={(e) => patch(oi, li, (x) => { x.depart = e.target.value || null; })} className="mt-1 h-9" /></div>
                        <div className="w-[84px]"><Label className="text-xs">Arrives</Label><Input value={l.arrive ?? ""} placeholder="09:10" onChange={(e) => patch(oi, li, (x) => { x.arrive = e.target.value || null; })} className="mt-1 h-9" /></div>
                        <div className="w-[100px]"><Label className="text-xs">Class</Label><Input value={l.class ?? ""} onChange={(e) => patch(oi, li, (x) => { x.class = e.target.value || null; })} className="mt-1 h-9" /></div>
                        <div className="w-[90px]"><Label className="text-xs">Baggage</Label><Input value={l.baggage ?? ""} onChange={(e) => patch(oi, li, (x) => { x.baggage = e.target.value || null; })} className="mt-1 h-9" /></div>
                        <div className="w-[70px]"><Label className="text-xs">Currency</Label><Input value={l.currency} maxLength={3} onChange={(e) => patch(oi, li, (x) => { x.currency = e.target.value.toUpperCase(); })} className="mt-1 h-9" /></div>
                        <div className="w-[100px]"><Label className="text-xs">Fare per adult</Label><Input type="number" min={0} step="any" value={l.fare_pp ?? ""} onChange={(e) => patch(oi, li, (x) => { x.fare_pp = num(e.target.value); })} className="mt-1 h-9" data-testid={`leg-fare-${oi}-${li}`} /></div>
                        <div className="w-[100px]"><Label className="text-xs">Fare per child</Label><Input type="number" min={0} step="any" value={l.child_fare_pp ?? ""} onChange={(e) => patch(oi, li, (x) => { x.child_fare_pp = num(e.target.value); })} className="mt-1 h-9" /></div>
                      </>) : (<>
                        <div className="min-w-[170px] flex-1"><Label className="text-xs">Service</Label>
                          <Select value={l.provider_id != null ? String(l.provider_id) : NONE} onValueChange={(v) => { if (v !== NONE) loadDetail(v); patch(oi, li, (x) => { x.provider_id = v === NONE ? null : v; x.vehicle_id = null; x.route_id = null; }); }}>
                            <SelectTrigger className="mt-1 h-9" data-testid={`leg-provider-${oi}-${li}`}><SelectValue placeholder="Choose service" /></SelectTrigger>
                            <SelectContent><SelectItem value={NONE}>Choose service</SelectItem>{cands.map((x) => <SelectItem key={x.id} value={String(x.id)}>{x.name}</SelectItem>)}</SelectContent>
                          </Select></div>
                        <div className="min-w-[150px] flex-1"><Label className="text-xs">Vehicle</Label>
                          <Select value={l.vehicle_id != null ? String(l.vehicle_id) : NONE} onValueChange={(v) => patch(oi, li, (x) => { x.vehicle_id = v === NONE ? null : v; })} disabled={!prov}>
                            <SelectTrigger className="mt-1 h-9" data-testid={`leg-vehicle-${oi}-${li}`}><SelectValue placeholder="Vehicle" /></SelectTrigger>
                            <SelectContent><SelectItem value={NONE}>Choose vehicle</SelectItem>{vehicles.map((v) => <SelectItem key={v.id} value={String(v.id)}>{v.name} ({v.seats} seat{v.seats === 1 ? "" : "s"})</SelectItem>)}</SelectContent>
                          </Select></div>
                        <div className="min-w-[190px] flex-[1.4]"><Label className="text-xs">Route</Label>
                          <Select value={l.route_id != null ? String(l.route_id) : NONE} onValueChange={(v) => patch(oi, li, (x) => { x.route_id = v === NONE ? null : v; })} disabled={!prov}>
                            <SelectTrigger className="mt-1 h-9" data-testid={`leg-route-${oi}-${li}`}><SelectValue placeholder="Route" /></SelectTrigger>
                            <SelectContent><SelectItem value={NONE}>Any / not route-specific</SelectItem>{(prov?.routes ?? []).filter((r) => r.active).map((r) => <SelectItem key={r.id} value={String(r.id)}>{r.title}</SelectItem>)}</SelectContent>
                          </Select></div>
                        {!personBasis && <div className="w-[84px]"><Label className="text-xs">Vehicles</Label><Input type="number" min={1} max={50} value={l.qty ?? ""} placeholder={price?.qty != null ? String(price.qty) : "auto"} onChange={(e) => patch(oi, li, (x) => { x.qty = e.target.value === "" ? null : Math.max(1, Number(e.target.value) || 1); })} className="mt-1 h-9" aria-label="Number of vehicles" data-testid={`leg-qty-${oi}-${li}`} /></div>}
                        {(l.mode === "cab" || l.mode === "van") && <div className="w-[76px]"><Label className="text-xs">Days</Label><Input type="number" min={1} max={30} value={l.days} onChange={(e) => patch(oi, li, (x) => { x.days = Math.max(1, Number(e.target.value) || 1); })} className="mt-1 h-9" aria-label="Days" /></div>}
                        {(personBasis || l.mode === "ferry" || (price?.basis === "per_transfer")) && (
                          <div className="w-[116px]"><Label className="text-xs">Journey</Label>
                            <Select value={String(l.legs)} onValueChange={(v) => patch(oi, li, (x) => { x.legs = Number(v); })}><SelectTrigger className="mt-1 h-9"><SelectValue /></SelectTrigger>
                              <SelectContent><SelectItem value="1">One way</SelectItem><SelectItem value="2">Return (2 legs)</SelectItem></SelectContent></Select></div>
                        )}
                      </>)}
                      <label className="mb-2 flex cursor-pointer items-center gap-1.5 text-xs" title="Untick to show this on the quotation without adding it to the price"><input type="checkbox" checked={l.included} onChange={(e) => patch(oi, li, (x) => { x.included = e.target.checked; })} /> In the price</label>
                      <button type="button" onClick={() => remove(oi, li)} aria-label="Remove leg" className="mb-1 rounded p-2 text-muted-foreground hover:bg-accent"><Trash2 size={14} /></button>
                    </div>
                    <div className="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-xs">
                      <span className="text-muted-foreground">
                        {veh && price?.qty != null && !personBasis && `Fits ${p.trip.adults + p.trip.children_bed + p.trip.children_no_bed} travellers: ${price.qty} vehicle${price.qty === 1 ? "" : "s"}${l.qty == null ? " suggested" : ""}. `}
                        {price && !price.rate_needed && price.basis && `Rate found, ${price.basis.replace("_", " ")}${price.currency !== "INR" ? ` in ${price.currency}` : ""}.`}
                      </span>
                      {price && <span className={cn("font-medium tabular-nums", !price.included && "text-muted-foreground line-through")} data-testid={`leg-price-${oi}-${li}`}>{price.rate_needed && price.selling === 0 ? "Rate needed" : INR(price.selling)}{manager && price.cost != null && price.selling > 0 ? <span className="ml-2 font-normal text-muted-foreground">cost {INR(price.cost)}{price.currency !== "INR" ? ` (${price.currency})` : ""}</span> : null}</span>}
                    </div>
                    {price?.warnings && price.warnings.length > 0 && <p className="mt-1 flex items-start gap-1 text-xs text-amber-700 dark:text-amber-300"><AlertTriangle size={12} className="mt-0.5 shrink-0" /> {price.warnings.join(" ")}</p>}
                  </div>
                );
              })}
            </div>
            {priced && Object.keys(priced.by_mode).length > 0 && (
              <p className="mt-2 text-xs text-muted-foreground" data-testid={`transport-bymode-${oi}`}>
                For this group, before GST: {Object.entries(priced.by_mode).map(([m, v]) => `${modeLabel(m)} ${INR(v)}`).join(" · ")} · Transport {INR(priced.selling)}
              </p>
            )}
          </div>
        );
      })}

      {(needed.length > 0 || Object.keys(rates).length > 0) && (
        <div className="rounded-lg border p-3" data-testid="proposal-fx">
          <div className="text-sm font-medium">Exchange rates for this quotation</div>
          <p className="text-xs text-muted-foreground">Rupees per one unit of the foreign currency. They are kept on this quotation, so re-opening it never changes the price. A safety margin covers movement before booking.</p>
          <div className="mt-2 flex flex-wrap items-end gap-3">
            {needed.map((c) => (
              <div key={c} className="w-[130px]"><Label className="text-xs">1 {c} = ₹</Label>
                <Input type="number" min={0} step="any" value={rates[c] ?? ""} onChange={(e) => set((d) => { const t = ensureTransport(d); if (e.target.value === "" || Number(e.target.value) <= 0) delete t.fx.rates[c]; else t.fx.rates[c] = Number(e.target.value); t.fx.date ??= new Date().toISOString().slice(0, 10); })} className="mt-1 h-9" data-testid={`fx-${c}`} /></div>
            ))}
            <div className="w-[130px]"><Label className="text-xs">Rates as of</Label><Input type="date" value={p.transport?.fx.date ?? ""} onChange={(e) => set((d) => { ensureTransport(d).fx.date = e.target.value || null; })} className="mt-1 h-9" /></div>
            <div className="w-[130px]"><Label className="text-xs">Safety margin (%)</Label><Input type="number" min={0} max={25} step="any" value={p.transport?.fx.safety_pct ?? 0} onChange={(e) => set((d) => { ensureTransport(d).fx.safety_pct = Math.max(0, Number(e.target.value) || 0); })} className="mt-1 h-9" /></div>
            <div className="flex items-end gap-1"><div className="w-[84px]"><Label className="text-xs">Add currency</Label><Input value={newCur} maxLength={3} placeholder="USD" onChange={(e) => setNewCur(e.target.value.toUpperCase())} className="mt-1 h-9" /></div>
              <Button type="button" variant="outline" size="sm" disabled={newCur.length !== 3 || newCur === "INR"} onClick={() => { setExtra((c) => (c.includes(newCur) ? c : [...c, newCur])); setNewCur(""); }}><Plus size={14} /></Button></div>
          </div>
        </div>
      )}
    </div>
  );
}
