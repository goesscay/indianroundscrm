"use client";

import { useEffect, useMemo, useState } from "react";
import { AlertTriangle, Bus, Car, Plane, Plus, Ship, Train, Trash2, Truck } from "lucide-react";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { INR } from "@/components/crm/shared";
import ProposalReach from "@/components/crm/ProposalReach";
import { cn } from "@/lib/utils";
import type { ProposalComputed, ProposalData, RoadExtra, RoadKind, RoadOption, RoadPlan, RoadRow } from "@/types";

const NONE = "none";
type SetFn = (fn: (d: ProposalData) => void) => void;

const KINDS: { v: RoadKind; label: string; types: string[]; none: string }[] = [
  { v: "transfer", label: "Airport transfer", types: ["cab", "van"], none: "airport transfers" },
  { v: "bus", label: "Volvo or bus", types: ["bus"], none: "buses" },
  { v: "ferry", label: "Ferry or boat", types: ["ferry"], none: "ferries" },
  { v: "vehicle", label: "Another vehicle", types: ["cab", "van"], none: "vehicles" },
];
const TYPE_ICON = { cab: Car, van: Truck, bus: Bus, ferry: Ship } as const;
const defaultRoad = (dest: string | null): RoadPlan => ({ mode: "stay", stays: [{ destination: dest, line_id: null, from_day: 1, days: null, qty: null }], daily: [], extras: [] });
const same = (a: string | null | undefined, b: string | null | undefined) => (a ?? "").trim().toLowerCase() === (b ?? "").trim().toLowerCase();
const newId = () => `x${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;

/**
 * The Transport card: one card with Road transport, Flights and Trains tabs. Road transport is priced from the rate list Operations keep; flights and
 * trains show details only, with no price. A bar at the bottom always shows the Transport price, which counts road transport only.
 */
export default function ProposalTransportCard({ p, set, computed, manager }: { p: ProposalData; set: SetFn; computed: ProposalComputed | null; manager: boolean }) {
  const [tab, setTab] = useState<"road" | "flight" | "train">("road");
  const [options, setOptions] = useState<RoadOption[] | null>(null);
  const [adding, setAdding] = useState<{ kind: RoadKind; line_id: string; day: number; way: "one_way" | "return"; qty: string } | null>(null);
  const trip = p.trip;
  const road = p.road ?? defaultRoad(trip.destination || null);

  useEffect(() => { api.get<RoadOption[]>("/transport/road-options").then((r) => setOptions(r.data)).catch(() => setOptions([])); }, []);

  const setRoad = (fn: (r: RoadPlan) => void) => set((d) => { d.road ??= defaultRoad(d.trip.destination || null); fn(d.road); });
  const dayCount = p.days.length > 0 ? p.days.length : trip.nights + 1;
  const dateOf = (day: number) => (trip.start_date ? new Date(new Date(`${trip.start_date}T00:00:00`).getTime() + (day - 1) * 86400000) : null);
  const fmt = (d: Date | null) => (d ? d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" }) : "");
  const days = useMemo(() => Array.from({ length: dayCount }, (_, i) => ({ n: i + 1, title: p.days[i]?.title || `Day ${i + 1}` })), [dayCount, p.days]);
  const pax = trip.adults + trip.children_bed + trip.children_no_bed;

  const priced = computed?.options[p.selected_option]?.actual.road;
  const rowBy = (key: string): RoadRow | undefined => priced?.rows.find((r) => r.key === key);
  const opt = (id: number | string | null) => (id == null ? undefined : options?.find((o) => o.id === String(id)));
  const vehiclesFor = (dest: string | null) => (options ?? []).filter((o) => (o.type === "cab" || o.type === "van") && same(o.destination, dest || trip.destination));
  const destinations = useMemo(() => Array.from(new Set((options ?? []).map((o) => o.destination).filter(Boolean) as string[])).sort(), [options]);

  const roadCount = (road.mode === "stay" ? road.stays.filter((s) => s.line_id != null).length : road.daily.filter((d) => d.line_id != null).length) + road.extras.filter((e) => e.line_id != null).length;
  const flights = (p.reach?.items ?? []).filter((x) => x.kind === "flight").length;
  const trains = (p.reach?.items ?? []).filter((x) => x.kind === "train").length;
  const rangeText = trip.start_date ? `${fmt(dateOf(1))} to ${fmt(dateOf(dayCount))}` : "Set the travel date";

  const setDaily = (day: number, lineId: string) => setRoad((r) => {
    const i = r.daily.findIndex((x) => x.day === day);
    const id = lineId === NONE ? null : lineId;
    if (i >= 0) r.daily[i].line_id = id; else r.daily.push({ day, line_id: id, qty: null });
  });
  const startAdd = (kind: RoadKind) => setAdding({ kind, line_id: "", day: 1, way: "one_way", qty: "" });
  const kindOpts = (kind: RoadKind) => { const k = KINDS.find((x) => x.v === kind)!; return (options ?? []).filter((o) => k.types.includes(o.type)); };
  const confirmAdd = () => {
    if (!adding || !adding.line_id) return;
    const a = adding;
    setRoad((r) => { r.extras.push({ id: newId(), kind: a.kind, line_id: a.line_id, qty: a.qty === "" ? null : Math.max(1, Number(a.qty) || 1), day: a.day, way: a.way } as RoadExtra); });
    setAdding(null);
  };
  const needFx = priced?.fx_needed ?? [];
  const foreign = Array.from(new Set((priced?.rows ?? []).map((r) => r.currency).filter((c) => c !== "INR").concat(needFx)));
  const ensureTransport = (d: ProposalData) => {
    const t = (d.transport ??= { fx: { date: null, rates: {}, safety_pct: 0 }, cab_scope: {} });
    if (Array.isArray(t.fx.rates)) t.fx.rates = {};
    return t;
  };

  const qtyText = (r: RoadRow) => (r.charged === "per_person" ? `${r.persons} persons${r.way === "return" ? " × 2" : ""}` : `${r.qty ?? 1} vehicle${(r.qty ?? 1) === 1 ? "" : "s"}${r.way === "return" && r.charged === "per_trip" ? " × 2" : ""}`);
  const sub = (r: RoadRow | undefined) => (r?.warnings.length ? <p className="mt-1 flex items-start gap-1 text-xs text-amber-700 dark:text-amber-300"><AlertTriangle size={12} className="mt-0.5 shrink-0" /> {r.warnings.join(" ")}</p> : null);

  return (
    <div className="space-y-3" data-testid="transport-card">
      <p className="text-sm text-muted-foreground">{trip.destination || "Your trip"} · {rangeText} · {trip.adults} adult{trip.adults === 1 ? "" : "s"}{trip.children_bed + trip.children_no_bed > 0 ? ` + ${trip.children_bed + trip.children_no_bed} child${trip.children_bed + trip.children_no_bed === 1 ? "" : "ren"}` : ""}. Choose a tab: road transport is priced, flights and trains are details only.</p>
      <div className="flex gap-1 border-b" role="tablist" data-testid="transport-tabs-card">
        {([["road", "Road transport", roadCount, Car], ["flight", "Flights", flights, Plane], ["train", "Trains", trains, Train]] as const).map(([k, l, n, Icon]) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)} data-testid={`ttab-${k}`}
            className={cn("-mb-px flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium", tab === k ? "border-foreground text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}>
            <Icon size={15} />{l}<span className="rounded-full bg-secondary px-2 py-0.5 text-xs" data-testid={`ttab-count-${k}`}>{n}</span>
          </button>
        ))}
      </div>

      {tab === "road" && (
        <div className="space-y-4" data-testid="road-tab">
          <div className="flex flex-wrap items-center gap-2 text-sm" data-testid="road-mode">
            <span className="font-medium">Cab for the stay:</span>
            {([["stay", "Same vehicle every day"], ["daily", "Choose day by day"]] as const).map(([m, l]) => (
              <button key={m} type="button" aria-pressed={road.mode === m} onClick={() => setRoad((r) => { r.mode = m; })} data-testid={`road-mode-${m}`}
                className={cn("rounded-md border px-3 py-1.5", road.mode === m ? "border-foreground bg-foreground text-background" : "border-border text-muted-foreground hover:bg-accent")}>{l}</button>
            ))}
          </div>

          {options !== null && options.length === 0 && <p className="rounded-md border border-dashed p-3 text-sm text-muted-foreground" data-testid="road-no-rates">There are no road transport rates yet. Operations add them under Transport → Rates.</p>}

          {road.mode === "stay" && (
            <div className="space-y-2" data-testid="road-stay">
              {road.stays.map((s, i) => {
                const dest = s.destination || trip.destination;
                const list = vehiclesFor(dest);
                const row = rowBy(`stay${i}`);
                return (
                  <div key={i} className="rounded-md border p-3" data-testid={`road-stay-${i}`}>
                    {road.stays.length > 1 && (
                      <div className="mb-2 flex items-center gap-2"><Label className="text-xs">Destination</Label>
                        <Input list="road-destinations" value={s.destination ?? ""} onChange={(e) => setRoad((r) => { r.stays[i].destination = e.target.value || null; r.stays[i].line_id = null; })} className="h-8 w-[180px]" />
                        <Label className="text-xs">From day</Label><Input type="number" min={1} value={s.from_day} onChange={(e) => setRoad((r) => { r.stays[i].from_day = Math.max(1, Number(e.target.value) || 1); })} className="h-8 w-[70px]" />
                        <button type="button" aria-label="Remove destination" className="ml-auto rounded p-1.5 text-muted-foreground hover:bg-accent" onClick={() => setRoad((r) => { r.stays.splice(i, 1); })}><Trash2 size={14} /></button></div>
                    )}
                    <div className="grid gap-3 sm:grid-cols-[1.6fr_90px_90px_110px_120px]">
                      <div><Label className="text-xs">Vehicle</Label>
                        <Select value={s.line_id != null ? String(s.line_id) : NONE} onValueChange={(v) => setRoad((r) => { r.stays[i].line_id = v === NONE ? null : v; })}>
                          <SelectTrigger className="mt-1 h-9" data-testid={`road-stay-vehicle-${i}`}><SelectValue placeholder="Choose a vehicle" /></SelectTrigger>
                          <SelectContent><SelectItem value={NONE}>No vehicle</SelectItem>{list.map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}</SelectContent>
                        </Select></div>
                      <div><Label className="text-xs">Days</Label><Input type="number" min={1} max={90} value={s.days ?? ""} placeholder={String(Math.max(1, dayCount - s.from_day + 1))} onChange={(e) => setRoad((r) => { r.stays[i].days = e.target.value === "" ? null : Math.max(1, Number(e.target.value) || 1); })} className="mt-1 h-9" data-testid={`road-stay-days-${i}`} /></div>
                      <div><Label className="text-xs">Vehicles</Label><Input type="number" min={1} max={50} value={s.qty ?? ""} placeholder={row?.qty != null ? String(row.qty) : "auto"} onChange={(e) => setRoad((r) => { r.stays[i].qty = e.target.value === "" ? null : Math.max(1, Number(e.target.value) || 1); })} className="mt-1 h-9" data-testid={`road-stay-qty-${i}`} /></div>
                      <div><Label className="text-xs">Rate per day</Label><div className="mt-1 flex h-9 items-center text-sm tabular-nums" data-testid={`road-stay-rate-${i}`}>{row?.unit_selling != null ? INR(row.unit_selling) : "–"}</div></div>
                      <div><Label className="text-xs">Amount</Label><div className="mt-1 flex h-9 items-center text-sm font-semibold tabular-nums" data-testid={`road-stay-amount-${i}`}>{row && !row.rate_needed ? INR(row.selling) : "–"}</div></div>
                    </div>
                    {options !== null && options.length > 0 && list.length === 0 && <p className="mt-2 text-xs text-muted-foreground">No cab or van in the rate list for {dest || "this destination"} yet. Operations add one under Transport → Rates.</p>}
                    {row && <p className="mt-2 text-xs text-muted-foreground">The rate is found from Operations → Transport → Rates for the travel date. Change the vehicle and the price follows.{row.currency !== "INR" ? ` Priced in ${row.currency}, converted at the rate on this quotation.` : ""}</p>}
                    {sub(row)}
                  </div>
                );
              })}
              <datalist id="road-destinations">{destinations.map((d) => <option key={d} value={d} />)}</datalist>
              <Button type="button" variant="outline" size="sm" onClick={() => setRoad((r) => { const last = r.stays[r.stays.length - 1]; r.stays.push({ destination: null, line_id: null, from_day: Math.min(dayCount, (last?.from_day ?? 1) + (last?.days ?? 1)), days: null, qty: null }); })} data-testid="road-add-destination"><Plus size={14} /> Add another destination</Button>
            </div>
          )}

          {road.mode === "daily" && (
            <div className="overflow-x-auto rounded-md border" data-testid="road-daily">
              <table className="w-full min-w-[620px] text-sm">
                <thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="p-2.5">Day</th><th className="p-2.5">Plan</th><th className="p-2.5">Vehicle</th><th className="p-2.5 text-right">Rate</th></tr></thead>
                <tbody>
                  {days.map((d) => {
                    const idx = road.daily.findIndex((x) => x.day === d.n);
                    const cur = idx >= 0 ? road.daily[idx] : undefined;
                    const row = idx >= 0 ? rowBy(`day${d.n}-${idx}`) : undefined;
                    const list = vehiclesFor(p.days[d.n - 1]?.stay || trip.destination);
                    return (
                      <tr key={d.n} className="border-b last:border-0" data-testid={`road-day-${d.n}`}>
                        <td className="p-2.5 font-medium">Day {d.n}<div className="text-xs font-normal text-muted-foreground">{fmt(dateOf(d.n))}</div></td>
                        <td className="p-2.5">{d.title}</td>
                        <td className="p-2.5"><Select value={cur?.line_id != null ? String(cur.line_id) : NONE} onValueChange={(v) => setDaily(d.n, v)}>
                          <SelectTrigger className="h-9 min-w-[230px]" data-testid={`road-day-vehicle-${d.n}`}><SelectValue /></SelectTrigger>
                          <SelectContent><SelectItem value={NONE}>No vehicle</SelectItem>{list.map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}</SelectContent></Select>{sub(row)}</td>
                        <td className="p-2.5 text-right tabular-nums" data-testid={`road-day-rate-${d.n}`}>{row && !row.rate_needed ? INR(row.selling) : "–"}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          <div className="space-y-2" data-testid="road-extras">
            {road.extras.length > 0 && (
              <div className="overflow-x-auto rounded-md border">
                <table className="w-full min-w-[620px] text-sm">
                  <thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="p-2.5">Type</th><th className="p-2.5">Added transport</th><th className="p-2.5">Qty</th><th className="p-2.5 text-right">Rate</th><th className="p-2.5 text-right">Amount</th><th className="w-10 p-2.5" /></tr></thead>
                  <tbody>
                    {road.extras.map((x, i) => {
                      const o = opt(x.line_id);
                      const row = rowBy(x.id);
                      const Icon = TYPE_ICON[(o?.type ?? "cab") as keyof typeof TYPE_ICON] ?? Car;
                      return (
                        <tr key={x.id} className="border-b last:border-0" data-testid={`road-extra-${i}`}>
                          <td className="p-2.5"><span className="inline-flex items-center gap-1 rounded bg-secondary px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"><Icon size={11} />{o?.type ?? "road"}</span></td>
                          <td className="p-2.5 font-medium">{o?.name ?? row?.name ?? "Rate removed"}<div className="text-xs font-normal text-muted-foreground">{fmt(dateOf(x.day)) || `Day ${x.day}`}{x.way === "return" ? " · return" : ""}</div>{sub(row)}</td>
                          <td className="p-2.5">{row ? qtyText(row) : "–"}</td>
                          <td className="p-2.5 text-right tabular-nums">{row?.unit_selling != null ? INR(row.unit_selling) : "–"}</td>
                          <td className="p-2.5 text-right font-medium tabular-nums">{row && !row.rate_needed ? INR(row.selling) : "–"}</td>
                          <td className="p-2.5 text-right"><button type="button" aria-label="Remove" className="rounded p-1.5 text-muted-foreground hover:bg-accent" onClick={() => setRoad((r) => { r.extras.splice(i, 1); })} data-testid={`road-extra-remove-${i}`}><Trash2 size={14} /></button></td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-medium">Add:</span>
              {KINDS.map((k) => <Button key={k.v} type="button" variant="outline" size="sm" onClick={() => startAdd(k.v)} data-testid={`road-add-${k.v}`}><Plus size={14} /> {k.label}</Button>)}
            </div>
            {adding && (() => {
              const k = KINDS.find((x) => x.v === adding.kind)!;
              const list = kindOpts(adding.kind);
              return (
                <div className="space-y-2 rounded-md border p-3" data-testid="road-add-form">
                  {list.length === 0 ? <p className="text-sm text-muted-foreground">There are no {k.none} in the rate list yet. Operations add them under Transport → Rates.</p> : (
                    <div className="grid gap-2 sm:grid-cols-[1.8fr_150px_120px_90px]">
                      <div><Label className="text-xs">{k.label}</Label>
                        <Select value={adding.line_id || NONE} onValueChange={(v) => setAdding({ ...adding, line_id: v === NONE ? "" : v })}><SelectTrigger className="mt-1 h-9" data-testid="road-add-line"><SelectValue placeholder="Choose" /></SelectTrigger>
                          <SelectContent><SelectItem value={NONE}>Choose</SelectItem>{list.map((o) => <SelectItem key={o.id} value={o.id}>{o.name}{o.destination && !same(o.destination, trip.destination) ? ` · ${o.destination}` : ""}</SelectItem>)}</SelectContent></Select></div>
                      <div><Label className="text-xs">Day</Label>
                        <Select value={String(adding.day)} onValueChange={(v) => setAdding({ ...adding, day: Number(v) })}><SelectTrigger className="mt-1 h-9"><SelectValue /></SelectTrigger>
                          <SelectContent>{days.map((d) => <SelectItem key={d.n} value={String(d.n)}>Day {d.n} {fmt(dateOf(d.n))}</SelectItem>)}</SelectContent></Select></div>
                      <div><Label className="text-xs">Way</Label>
                        <Select value={adding.way} onValueChange={(v) => setAdding({ ...adding, way: v as "one_way" | "return" })}><SelectTrigger className="mt-1 h-9" data-testid="road-add-way"><SelectValue /></SelectTrigger>
                          <SelectContent><SelectItem value="one_way">One way</SelectItem><SelectItem value="return">Return</SelectItem></SelectContent></Select></div>
                      <div><Label className="text-xs">Vehicles</Label><Input type="number" min={1} value={adding.qty} placeholder="auto" onChange={(e) => setAdding({ ...adding, qty: e.target.value })} className="mt-1 h-9" /></div>
                    </div>
                  )}
                  <div className="flex gap-2"><Button type="button" size="sm" disabled={!adding.line_id} onClick={confirmAdd} data-testid="road-add-confirm">Add to quotation</Button><Button type="button" size="sm" variant="outline" onClick={() => setAdding(null)}>Cancel</Button></div>
                </div>
              );
            })()}
          </div>

          {foreign.length > 0 && (
            <div className="rounded-md border p-3 text-sm" data-testid="road-fx">
              <div className="font-medium">Foreign prices</div>
              {needFx.length > 0 && <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">Exchange rate needed for {needFx.join(", ")}. {manager ? "Enter it below, or ask Operations to keep a default rate." : "Operations keep the default rates; ask them to add it."}</p>}
              {manager ? (
                <div className="mt-2 flex flex-wrap items-end gap-3">
                  {foreign.map((c) => (
                    <div key={c} className="w-[130px]"><Label className="text-xs">1 {c} = ₹</Label>
                      <Input type="number" min={0} step="any" value={p.transport?.fx.rates[c] ?? ""} onChange={(e) => set((d) => { const t = ensureTransport(d); if (e.target.value === "" || Number(e.target.value) <= 0) delete t.fx.rates[c]; else t.fx.rates[c] = Number(e.target.value); t.fx.date ??= new Date().toISOString().slice(0, 10); })} className="mt-1 h-9" data-testid={`road-fx-${c}`} /></div>
                  ))}
                </div>
              ) : <p className="mt-1 text-xs text-muted-foreground">Converted at the exchange rates held on this quotation{p.transport?.fx.date ? `, set on ${p.transport.fx.date}` : ""}.</p>}
            </div>
          )}

          <div className="flex flex-wrap items-end gap-4 rounded-md border bg-muted/30 p-3 text-sm" data-testid="customer-wording">
            <span className="font-medium">What the customer sees</span>
            <div className="w-[250px]"><Label className="text-xs">Vehicle names</Label>
              <Select value={p.transport?.wording ?? "named"} onValueChange={(v) => set((d) => { ensureTransport(d).wording = v as "named" | "general"; })}><SelectTrigger className="mt-1 h-9" data-testid="wording-select"><SelectValue /></SelectTrigger>
                <SelectContent><SelectItem value="named">Show them (Private Ertiga)</SelectItem><SelectItem value="general">General only (Private cab)</SelectItem></SelectContent></Select></div>
            <div className="w-[250px]"><Label className="text-xs">Transport price</Label>
              <Select value={p.transport?.tax_display ?? "with_gst"} onValueChange={(v) => set((d) => { ensureTransport(d).tax_display = v as "with_gst" | "before_gst"; })}><SelectTrigger className="mt-1 h-9" data-testid="taxdisplay-select"><SelectValue /></SelectTrigger>
                <SelectContent><SelectItem value="with_gst">Show the total including GST</SelectItem><SelectItem value="before_gst">Show before GST (GST extra)</SelectItem></SelectContent></Select></div>
            <span className="text-xs text-muted-foreground">Suppliers, costs and margin are never shown to the customer.</span>
          </div>
        </div>
      )}

      {tab === "flight" && <ProposalReach p={p} set={set} kind="flight" />}
      {tab === "train" && <ProposalReach p={p} set={set} kind="train" />}

      <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-secondary/60 px-4 py-3" data-testid="transport-price-bar">
        <div><span className="text-sm text-muted-foreground">Transport price</span> <span className="font-display text-xl font-black" data-testid="transport-price">{INR(priced?.selling ?? 0)}</span>
          {manager && priced?.cost != null && priced.selling > 0 && <span className="ml-3 text-xs text-muted-foreground" data-testid="transport-price-ops">Operations view: cost {INR(priced.cost)}, margin {INR(priced.margin_amount ?? 0)}</span>}</div>
        <div className="text-xs text-muted-foreground">Road transport only. Flights and trains have no price.{pax > 0 ? ` For ${pax} traveller${pax === 1 ? "" : "s"}, before GST.` : ""}</div>
      </div>
    </div>
  );
}
