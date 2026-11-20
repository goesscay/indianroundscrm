"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react";
import { useParams, useRouter } from "next/navigation";
import { AlertTriangle, ArrowLeft, Pencil, Plus, Trash2 } from "lucide-react";
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
import type { TransportMeta, TransportMode, TransportPlace, TransportProvider, TransportRate, TransportRoute, TransportVehicle } from "@/types";

const MODE_LABEL: Record<string, string> = { cab: "Cab", van: "Van", bus: "Bus", ferry: "Ferry" };
const KIND_LABEL: Record<string, string> = { sightseeing: "Sightseeing", transfer: "Transfer", intercity: "Intercity" };
const money = (n: number | null | undefined, cur: string) => (n == null ? "–" : new Intl.NumberFormat("en-IN", { style: "currency", currency: cur, maximumFractionDigits: 2 }).format(n));
const num = (v: string) => (v.trim() === "" ? null : Number(v));

/** A small modal with a form: the title, a save handler and the fields as children. */
function FormDialog({ open, title, description, busy, onClose, onSubmit, children, testId }: { open: boolean; title: string; description?: string; busy: boolean; onClose: () => void; onSubmit: (e: FormEvent) => void; children: ReactNode; testId: string }) {
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid={testId}>
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">{title}</DialogTitle>{description && <DialogDescription>{description}</DialogDescription>}</DialogHeader>
        <form onSubmit={onSubmit} className="space-y-3">
          {children}
          <DialogFooter className="gap-2 sm:gap-2"><Button type="button" variant="outline" onClick={onClose} disabled={busy}>Cancel</Button><Button type="submit" disabled={busy} data-testid={`${testId}-save`}>{busy ? "Saving…" : "Save"}</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

const Field = ({ label, children, className }: { label: string; children: ReactNode; className?: string }) => <div className={className}><Label className="label-caps">{label}</Label><div className="mt-1.5">{children}</div></div>;

function useSave(onSaved: () => void, onClose: () => void) {
  const [busy, setBusy] = useState(false);
  const run = async (fn: () => Promise<unknown>, ok: string) => {
    setBusy(true);
    try { await fn(); toast.success(ok); onSaved(); onClose(); } catch (err) { toast.error(formatApiError(err)); } finally { setBusy(false); }
  };
  return { busy, run };
}

function VehicleDialog({ provider, vehicle, open, onClose, onSaved }: { provider: TransportProvider; vehicle: TransportVehicle | null; open: boolean; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ name: "", mode: "cab", seats: "4", notes: "" });
  const { busy, run } = useSave(onSaved, onClose);
  useEffect(() => { if (open) setF(vehicle ? { name: vehicle.name, mode: vehicle.mode, seats: String(vehicle.seats), notes: vehicle.notes ?? "" } : { name: "", mode: provider.modes[0] ?? "cab", seats: "4", notes: "" }); }, [open, vehicle, provider.modes]);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body = { name: f.name, mode: f.mode, seats: Number(f.seats), notes: f.notes || null };
    void run(() => (vehicle ? api.patch(`/transport/vehicles/${vehicle.id}`, body) : api.post(`/transport/providers/${provider.id}/vehicles`, body)), "Vehicle saved");
  };
  return (
    <FormDialog open={open} title={vehicle ? "Edit vehicle" : "Add a vehicle"} description="The seats decide how many vehicles a group needs." busy={busy} onClose={onClose} onSubmit={submit} testId="vehicle-dialog">
      <Field label="Vehicle name"><Input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required placeholder="e.g. Ertiga, Tempo Traveller (14 seater)" data-testid="vehicle-name" /></Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Type">
          <Select value={f.mode} onValueChange={(v) => setF({ ...f, mode: v })}><SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>{Object.entries(MODE_LABEL).map(([k, l]) => <SelectItem key={k} value={k}>{l}</SelectItem>)}</SelectContent></Select>
        </Field>
        <Field label="Seats"><Input type="number" min={1} max={100} value={f.seats} onChange={(e) => setF({ ...f, seats: e.target.value })} required data-testid="vehicle-seats" /></Field>
      </div>
      <Field label="Notes (A/C, luggage…)"><Input value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
    </FormDialog>
  );
}

function RouteDialog({ provider, route, places, open, onClose, onSaved }: { provider: TransportProvider; route: TransportRoute | null; places: TransportPlace[]; open: boolean; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ title: "", kind: "sightseeing", from: "none", to: "none", duration: "", inclusions: "" });
  const { busy, run } = useSave(onSaved, onClose);
  useEffect(() => { if (open) setF(route ? { title: route.title, kind: route.kind, from: route.from_place_id ?? "none", to: route.to_place_id ?? "none", duration: route.duration ?? "", inclusions: route.inclusions ?? "" } : { title: "", kind: "sightseeing", from: "none", to: "none", duration: "", inclusions: "" }); }, [open, route]);
  const mine = places.filter((p) => p.country_code === provider.country_code);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body = { title: f.title, kind: f.kind, from_place_id: f.from === "none" ? null : Number(f.from), to_place_id: f.to === "none" ? null : Number(f.to), duration: f.duration || null, inclusions: f.inclusions || null };
    void run(() => (route ? api.patch(`/transport/routes/${route.id}`, body) : api.post(`/transport/providers/${provider.id}/routes`, body)), "Route saved");
  };
  const placeSelect = (k: "from" | "to") => (
    <Select value={f[k]} onValueChange={(v) => setF({ ...f, [k]: v })}><SelectTrigger><SelectValue /></SelectTrigger>
      <SelectContent><SelectItem value="none">Not set</SelectItem>{mine.map((p) => <SelectItem key={p.id} value={p.id}>{p.name}</SelectItem>)}</SelectContent></Select>
  );
  return (
    <FormDialog open={open} title={route ? "Edit route" : "Add a route"} description="A sightseeing package, a transfer or an intercity trip this provider runs." busy={busy} onClose={onClose} onSubmit={submit} testId="route-dialog">
      <Field label="Title"><Input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} required placeholder="e.g. Local Manali, Solang Valley, Kullu-Naggar" data-testid="route-title" /></Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Type"><Select value={f.kind} onValueChange={(v) => setF({ ...f, kind: v })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{Object.entries(KIND_LABEL).map(([k, l]) => <SelectItem key={k} value={k}>{l}</SelectItem>)}</SelectContent></Select></Field>
        <Field label="Duration"><Input value={f.duration} onChange={(e) => setF({ ...f, duration: e.target.value })} placeholder="e.g. Full day" /></Field>
        <Field label="From">{placeSelect("from")}</Field>
        <Field label="To">{placeSelect("to")}</Field>
      </div>
      <Field label="What the customer is told is included"><Textarea rows={2} value={f.inclusions} onChange={(e) => setF({ ...f, inclusions: e.target.value })} placeholder="e.g. Pickup and drop at Manali Volvo Bus Stand" /></Field>
    </FormDialog>
  );
}

function RateDialog({ provider, rate, open, onClose, onSaved }: { provider: TransportProvider; rate: TransportRate | null; open: boolean; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ vehicle_id: "", route_id: "none", basis: "per_day", season: "", start_date: "", end_date: "", cost: "", child_cost: "" });
  const { busy, run } = useSave(onSaved, onClose);
  useEffect(() => {
    if (!open) return;
    setF(rate ? { vehicle_id: rate.vehicle_id, route_id: rate.route_id ?? "none", basis: rate.basis, season: rate.season ?? "", start_date: rate.start_date ?? "", end_date: rate.end_date ?? "", cost: String(rate.cost), child_cost: rate.child_cost == null ? "" : String(rate.child_cost) }
      : { vehicle_id: provider.vehicles?.[0]?.id ?? "", route_id: "none", basis: "per_day", season: "", start_date: "", end_date: "", cost: "", child_cost: "" });
  }, [open, rate, provider.vehicles]);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body = { vehicle_id: Number(f.vehicle_id), route_id: f.route_id === "none" ? null : Number(f.route_id), basis: f.basis, season: f.season || null, start_date: f.start_date || null, end_date: f.end_date || null, cost: Number(f.cost), child_cost: num(f.child_cost) };
    void run(() => (rate ? api.patch(`/transport/rates/${rate.id}`, body) : api.post(`/transport/providers/${provider.id}/rates`, body)), "Rate saved");
  };
  const noVehicles = !(provider.vehicles ?? []).length;
  return (
    <FormDialog open={open} title={rate ? "Edit rate" : "Add a rate"} description={`What ${provider.name} charges you, in ${provider.currency}.`} busy={busy} onClose={onClose} onSubmit={submit} testId="transport-rate-dialog">
      {noVehicles ? <p className="text-sm text-muted-foreground">Add a vehicle first, then its rates.</p> : (<>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Vehicle"><Select value={f.vehicle_id} onValueChange={(v) => setF({ ...f, vehicle_id: v })}><SelectTrigger data-testid="rate-vehicle"><SelectValue /></SelectTrigger><SelectContent>{(provider.vehicles ?? []).map((v) => <SelectItem key={v.id} value={v.id}>{v.name}</SelectItem>)}</SelectContent></Select></Field>
          <Field label="Route"><Select value={f.route_id} onValueChange={(v) => setF({ ...f, route_id: v })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Any / not route-specific</SelectItem>{(provider.routes ?? []).map((r) => <SelectItem key={r.id} value={r.id}>{r.title}</SelectItem>)}</SelectContent></Select></Field>
          <Field label="Charged"><Select value={f.basis} onValueChange={(v) => setF({ ...f, basis: v })}><SelectTrigger data-testid="rate-basis"><SelectValue /></SelectTrigger><SelectContent>{Object.entries(BASIS_LABEL).map(([k, l]) => <SelectItem key={k} value={k}>{l}</SelectItem>)}</SelectContent></Select></Field>
          <Field label="Season name"><Input value={f.season} onChange={(e) => setF({ ...f, season: e.target.value })} placeholder="Off Season, Peak…" /></Field>
          <Field label="Start date"><Input type="date" value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} /></Field>
          <Field label="End date"><Input type="date" value={f.end_date} onChange={(e) => setF({ ...f, end_date: e.target.value })} /></Field>
          <Field label={`Vendor cost (${provider.currency})`}><Input type="number" min={0} step="any" value={f.cost} onChange={(e) => setF({ ...f, cost: e.target.value })} required data-testid="rate-cost" /></Field>
          <Field label="Child cost (per person only)"><Input type="number" min={0} step="any" value={f.child_cost} onChange={(e) => setF({ ...f, child_cost: e.target.value })} /></Field>
        </div>
        <p className="text-xs text-muted-foreground">Leave both dates empty if the client has not given the season dates yet. The rate is kept and marked &quot;dates needed&quot;; it is not used for pricing until the dates are filled in.</p>
      </>)}
    </FormDialog>
  );
}

const BASIS_LABEL: Record<string, string> = { per_day: "Per vehicle, per day", per_transfer: "Per vehicle, per transfer", per_route: "Per route package", per_person: "Per person" };

function Section({ title, hint, action, children, testId }: { title: string; hint?: string; action?: ReactNode; children: ReactNode; testId: string }) {
  return (
    <section className="space-y-2" data-testid={testId}>
      <div className="flex flex-wrap items-end justify-between gap-2"><div><h2 className="font-display text-lg font-black">{title}</h2>{hint && <p className="text-xs text-muted-foreground">{hint}</p>}</div>{action}</div>
      <Card className="overflow-x-auto rounded-lg">{children}</Card>
    </section>
  );
}
const Th = ({ children, right }: { children?: ReactNode; right?: boolean }) => <th className={cn("p-3 text-[11px] font-medium uppercase tracking-wide text-muted-foreground", right ? "text-right" : "text-left")}>{children}</th>;
const Empty = ({ children }: { children: ReactNode }) => <div className="p-6 text-center text-sm text-muted-foreground">{children}</div>;

export default function TransportProviderPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const manage = can("transport.manage");
  const [p, setP] = useState<TransportProvider | null>(null);
  const [meta, setMeta] = useState<TransportMeta | null>(null);
  const [places, setPlaces] = useState<TransportPlace[]>([]);
  const [missing, setMissing] = useState(false);
  const [vDlg, setVDlg] = useState<{ open: boolean; item: TransportVehicle | null }>({ open: false, item: null });
  const [rDlg, setRDlg] = useState<{ open: boolean; item: TransportRoute | null }>({ open: false, item: null });
  const [tDlg, setTDlg] = useState<{ open: boolean; item: TransportRate | null }>({ open: false, item: null });
  const [f, setF] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const load = useCallback(() => {
    api.get<TransportProvider>(`/transport/providers/${id}`).then((r) => setP(r.data)).catch((e) => { setMissing(true); toast.error(formatApiError(e)); });
  }, [id]);
  useEffect(() => { load(); api.get<TransportMeta>("/transport/meta").then((r) => setMeta(r.data)).catch(() => null); }, [load]);
  useEffect(() => { if (manage) api.get<TransportPlace[]>("/transport/places").then((r) => setPlaces(r.data)).catch(() => null); }, [manage]);
  useEffect(() => {
    if (p) setF({ name: p.name, country_code: p.country_code, destination: p.destination, city: p.city ?? "", currency: p.currency, contact_name: p.contact_name ?? "", phone: p.phone ?? "", email: p.email ?? "", notes: p.notes ?? "",
      margin_type: p.margin_type ?? "default", margin_value: p.margin_value == null ? "" : String(p.margin_value), active: p.active ? "1" : "0" });
  }, [p]);

  const vehicleName = useMemo(() => new Map((p?.vehicles ?? []).map((v) => [v.id, v.name])), [p]);
  const routeTitle = useMemo(() => new Map((p?.routes ?? []).map((r) => [r.id, r.title])), [p]);
  const placeName = useMemo(() => new Map(places.map((x) => [x.id, x.name])), [places]);
  const modes = (f.modes ?? "") ? f.modes.split(",") : (p?.modes ?? []);

  const save = async (e: FormEvent) => {
    e.preventDefault(); setSaving(true);
    try {
      await api.patch(`/transport/providers/${id}`, {
        name: f.name, country_code: f.country_code, destination: f.destination, city: f.city || null, currency: f.currency, contact_name: f.contact_name || null, phone: f.phone || null, email: f.email || null, notes: f.notes || null,
        modes, margin_type: f.margin_type === "default" ? null : f.margin_type, margin_value: f.margin_type === "default" ? null : num(f.margin_value), active: f.active === "1",
      });
      toast.success("Saved"); load();
    } catch (err) { toast.error(formatApiError(err)); } finally { setSaving(false); }
  };
  const del = async (url: string, what: string) => { if (!window.confirm(what)) return; try { await api.delete(url); load(); } catch (e) { toast.error(formatApiError(e)); } };
  const toggleMode = (m: TransportMode) => setF((s) => { const cur = s.modes ? s.modes.split(",").filter(Boolean) : [...(p?.modes ?? [])]; const next = cur.includes(m) ? cur.filter((x) => x !== m) : [...cur, m]; return { ...s, modes: next.join(",") }; });

  if (missing) return <div className="py-12 text-center text-sm text-muted-foreground">This provider could not be found. <Link href="/transport" className="underline">Back to Transport</Link></div>;
  if (!p) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;
  const needDates = (p.rates ?? []).filter((r) => r.dates_needed).length;

  return (
    <div className="space-y-6" data-testid="transport-detail">
      <div>
        <Link href="/transport" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft size={13} /> Transport</Link>
        <div className="mt-1 flex flex-wrap items-end justify-between gap-2">
          <div><div className="label-caps">{p.destination} · {p.country_code} · {p.currency}</div><h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">{p.name}</h1></div>
          {manage && <Button variant="outline" className="text-red-600" onClick={() => { if (window.confirm(`Delete ${p.name}? Its vehicles, routes and rates go too. (To just stop offering it, switch it off instead.)`)) api.delete(`/transport/providers/${id}`).then(() => router.push("/transport")).catch((e) => toast.error(formatApiError(e))); }} data-testid="transport-delete"><Trash2 size={14} /> Delete</Button>}
        </div>
      </div>

      {manage ? (
        <Card className="rounded-lg p-4">
          <form onSubmit={save} className="space-y-3" data-testid="transport-form">
            <div className="grid gap-3 sm:grid-cols-3">
              <Field label="Provider name"><Input value={f.name ?? ""} onChange={(e) => setF({ ...f, name: e.target.value })} required /></Field>
              <Field label="Country">
                <Select value={f.country_code ?? "IN"} onValueChange={(v) => setF({ ...f, country_code: v, currency: meta?.countries.find((c) => c.code === v)?.currency ?? f.currency })}>
                  <SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{(meta?.countries ?? []).map((c) => <SelectItem key={c.code} value={c.code}>{c.name}</SelectItem>)}</SelectContent>
                </Select>
              </Field>
              <Field label="Currency"><Input value={f.currency ?? ""} maxLength={3} onChange={(e) => setF({ ...f, currency: e.target.value.toUpperCase() })} required /></Field>
              <Field label="Destination"><Input value={f.destination ?? ""} onChange={(e) => setF({ ...f, destination: e.target.value })} required /></Field>
              <Field label="City / base area"><Input value={f.city ?? ""} onChange={(e) => setF({ ...f, city: e.target.value })} /></Field>
              <Field label="Status">
                <Select value={f.active ?? "1"} onValueChange={(v) => setF({ ...f, active: v })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="1">Active</SelectItem><SelectItem value="0">Switched off</SelectItem></SelectContent></Select>
              </Field>
              <Field label="Contact person"><Input value={f.contact_name ?? ""} onChange={(e) => setF({ ...f, contact_name: e.target.value })} /></Field>
              <Field label="Phone"><Input value={f.phone ?? ""} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
              <Field label="Email"><Input type="email" value={f.email ?? ""} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
              <Field label="Margin">
                <div className="flex gap-2">
                  <Select value={f.margin_type ?? "default"} onValueChange={(v) => setF({ ...f, margin_type: v })}><SelectTrigger className="w-[150px]"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="default">Destination default</SelectItem><SelectItem value="percent">Percent</SelectItem><SelectItem value="amount">Per person</SelectItem></SelectContent></Select>
                  {f.margin_type !== "default" && <Input type="number" min={0} step="any" value={f.margin_value ?? ""} onChange={(e) => setF({ ...f, margin_value: e.target.value })} className="w-24" />}
                </div>
              </Field>
              <Field label="Services" className="sm:col-span-2">
                <div className="flex flex-wrap gap-3 pt-1.5 text-sm">{(meta?.modes ?? ["cab", "van", "bus", "ferry"]).map((m) => <label key={m} className="flex cursor-pointer items-center gap-1.5"><input type="checkbox" checked={modes.includes(m)} onChange={() => toggleMode(m)} className="accent-black dark:accent-white" />{MODE_LABEL[m] ?? m}</label>)}</div>
              </Field>
            </div>
            <Field label="Notes"><Textarea rows={2} value={f.notes ?? ""} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
            <div className="flex justify-end"><Button type="submit" disabled={saving} data-testid="transport-save">{saving ? "Saving…" : "Save details"}</Button></div>
          </form>
        </Card>
      ) : (
        <Card className="rounded-lg p-4 text-sm"><div className="text-muted-foreground">{p.notes || "No notes."}</div>{(p.phone || p.contact_name) && <div className="mt-2">{p.contact_name} {p.phone}</div>}</Card>
      )}

      <Section testId="vehicles-section" title="Vehicles" hint="Seats are used to work out how many vehicles a group needs."
        action={manage && <Button size="sm" onClick={() => setVDlg({ open: true, item: null })} data-testid="vehicle-add"><Plus size={14} /> Add vehicle</Button>}>
        {(p.vehicles ?? []).length === 0 ? <Empty>No vehicles yet.</Empty> : (
          <table className="w-full text-sm"><thead><tr className="border-b border-border"><Th>Vehicle</Th><Th>Type</Th><Th right>Seats</Th><Th>Notes</Th>{manage && <Th />}</tr></thead>
            <tbody>{(p.vehicles ?? []).map((v) => (
              <tr key={v.id} className="border-b border-border/60 last:border-0" data-testid={`vehicle-row-${v.id}`}><td className="p-3 font-medium">{v.name}</td><td className="p-3">{MODE_LABEL[v.mode] ?? v.mode}</td><td className="p-3 text-right">{v.seats}</td><td className="p-3 text-xs text-muted-foreground">{v.notes}</td>
                {manage && <td className="p-3 text-right"><Button variant="ghost" size="sm" onClick={() => setVDlg({ open: true, item: v })}><Pencil size={13} /></Button><Button variant="ghost" size="sm" onClick={() => del(`/transport/vehicles/${v.id}`, `Delete "${v.name}" and its rates?`)}><Trash2 size={13} /></Button></td>}</tr>
            ))}</tbody></table>
        )}
      </Section>

      <Section testId="routes-section" title="Routes and packages" hint="Sightseeing packages, transfers and intercity trips."
        action={manage && <Button size="sm" onClick={() => setRDlg({ open: true, item: null })} data-testid="route-add"><Plus size={14} /> Add route</Button>}>
        {(p.routes ?? []).length === 0 ? <Empty>No routes yet.</Empty> : (
          <table className="w-full text-sm"><thead><tr className="border-b border-border"><Th>Route</Th><Th>Type</Th><Th>From → To</Th><Th>Included</Th>{manage && <Th />}</tr></thead>
            <tbody>{(p.routes ?? []).map((r) => (
              <tr key={r.id} className="border-b border-border/60 last:border-0" data-testid={`route-row-${r.id}`}><td className="p-3 font-medium">{r.title}{r.duration && <div className="text-xs font-normal text-muted-foreground">{r.duration}</div>}</td><td className="p-3">{KIND_LABEL[r.kind] ?? r.kind}</td>
                <td className="p-3 text-xs">{r.from_place_id || r.to_place_id ? `${placeName.get(r.from_place_id ?? "") ?? "–"} → ${placeName.get(r.to_place_id ?? "") ?? "–"}` : "–"}</td><td className="p-3 text-xs text-muted-foreground">{r.inclusions}</td>
                {manage && <td className="p-3 text-right"><Button variant="ghost" size="sm" onClick={() => setRDlg({ open: true, item: r })}><Pencil size={13} /></Button><Button variant="ghost" size="sm" onClick={() => del(`/transport/routes/${r.id}`, `Delete "${r.title}" and its rates?`)}><Trash2 size={13} /></Button></td>}</tr>
            ))}</tbody></table>
        )}
      </Section>

      {manage && (
        <Section testId="rates-section" title="Rate cards" hint={`Vendor cost in ${p.currency}. Sales never see these.`}
          action={<Button size="sm" onClick={() => setTDlg({ open: true, item: null })} data-testid="transport-rate-add"><Plus size={14} /> Add rate</Button>}>
          {needDates > 0 && <div className="flex items-center gap-2 border-b border-border bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200" data-testid="dates-needed"><AlertTriangle size={14} />{needDates} rate{needDates === 1 ? "" : "s"} still need season dates. They are kept, but not used for pricing until the dates are entered.</div>}
          {(p.rates ?? []).length === 0 ? <Empty>No rates yet. Add one, or import the vendor sheet from the Transport page.</Empty> : (
            <table className="w-full min-w-[760px] text-sm"><thead><tr className="border-b border-border"><Th>Vehicle</Th><Th>Route</Th><Th>Charged</Th><Th>Season</Th><Th>Dates</Th><Th right>Cost</Th><Th /></tr></thead>
              <tbody>{(p.rates ?? []).map((r) => (
                <tr key={r.id} className="border-b border-border/60 last:border-0" data-testid={`transport-rate-row-${r.id}`}>
                  <td className="p-3 font-medium">{vehicleName.get(r.vehicle_id) ?? "–"}</td><td className="p-3 text-xs">{r.route_id ? routeTitle.get(r.route_id) : "Any"}</td>
                  <td className="p-3 text-xs">{BASIS_LABEL[r.basis] ?? r.basis}</td><td className="p-3">{r.season ?? "–"}</td>
                  <td className="p-3 text-xs">{r.dates_needed ? <span className="rounded bg-amber-100 px-1.5 py-0.5 text-amber-900 dark:bg-amber-950 dark:text-amber-200">Dates needed</span> : `${fmtDate(r.start_date ?? "")} – ${fmtDate(r.end_date ?? "")}`}</td>
                  <td className="p-3 text-right tabular-nums">{money(r.cost, r.currency)}{r.child_cost != null && <div className="text-xs text-muted-foreground">child {money(r.child_cost, r.currency)}</div>}</td>
                  <td className="p-3 text-right"><Button variant="ghost" size="sm" onClick={() => setTDlg({ open: true, item: r })}><Pencil size={13} /></Button><Button variant="ghost" size="sm" onClick={() => del(`/transport/rates/${r.id}`, "Delete this rate?")}><Trash2 size={13} /></Button></td>
                </tr>
              ))}</tbody></table>
          )}
        </Section>
      )}

      {manage && <VehicleDialog provider={p} vehicle={vDlg.item} open={vDlg.open} onClose={() => setVDlg({ open: false, item: null })} onSaved={load} />}
      {manage && <RouteDialog provider={p} route={rDlg.item} places={places} open={rDlg.open} onClose={() => setRDlg({ open: false, item: null })} onSaved={load} />}
      {manage && <RateDialog provider={p} rate={tDlg.item} open={tDlg.open} onClose={() => setTDlg({ open: false, item: null })} onSaved={load} />}
    </div>
  );
}
