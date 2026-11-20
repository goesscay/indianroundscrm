"use client";

import Link from "next/link";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { ArrowLeft, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import type { TransportCountry, TransportMeta, TransportPlace } from "@/types";

const KIND_LABEL: Record<string, string> = { city: "City", airport: "Airport", station: "Station", bus_stand: "Bus stand", harbour: "Harbour", other: "Other" };

export default function TransportPlacesPage() {
  const { can } = useCurrentUser();
  const manage = can("transport.manage");
  const [countries, setCountries] = useState<TransportCountry[]>([]);
  const [kinds, setKinds] = useState<string[]>(Object.keys(KIND_LABEL));
  const [places, setPlaces] = useState<TransportPlace[]>([]);
  const [cf, setCf] = useState({ code: "", name: "", currency: "", timezone: "" });
  const [pf, setPf] = useState({ country_code: "IN", name: "", kind: "city", city: "", code: "", timezone: "" });
  const [filter, setFilter] = useState("all");

  const load = useCallback(() => {
    api.get<TransportMeta>("/transport/meta").then((r) => { setCountries(r.data.countries); setKinds(r.data.place_kinds); }).catch((e) => toast.error(formatApiError(e)));
    api.get<TransportPlace[]>("/transport/places").then((r) => setPlaces(r.data)).catch((e) => toast.error(formatApiError(e)));
  }, []);
  useEffect(() => { load(); }, [load]);

  const addCountry = async (e: FormEvent) => {
    e.preventDefault();
    try { await api.post("/transport/countries", { code: cf.code, name: cf.name, currency: cf.currency, timezone: cf.timezone || null }); toast.success("Country added"); setCf({ code: "", name: "", currency: "", timezone: "" }); load(); }
    catch (err) { toast.error(formatApiError(err)); }
  };
  const toggleCountry = async (c: TransportCountry) => { try { await api.patch(`/transport/countries/${c.code}`, { active: !c.active }); load(); } catch (e) { toast.error(formatApiError(e)); } };
  const addPlace = async (e: FormEvent) => {
    e.preventDefault();
    try { await api.post("/transport/places", { country_code: pf.country_code, name: pf.name, kind: pf.kind, city: pf.city || null, code: pf.code || null, timezone: pf.timezone || null }); toast.success("Place added"); setPf({ ...pf, name: "", city: "", code: "" }); load(); }
    catch (err) { toast.error(formatApiError(err)); }
  };
  const delPlace = async (p: TransportPlace) => { if (!window.confirm(`Delete ${p.name}?`)) return; try { await api.delete(`/transport/places/${p.id}`); load(); } catch (e) { toast.error(formatApiError(e)); } };

  if (!manage) return <div className="py-12 text-center text-sm text-muted-foreground">Only Operations can manage countries and places.</div>;
  const shown = places.filter((p) => filter === "all" || p.country_code === filter);

  return (
    <div className="space-y-6" data-testid="transport-places-page">
      <div>
        <Link href="/transport" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft size={13} /> Transport</Link>
        <div className="label-caps mt-1">Operations · Transport</div>
        <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Countries and places</h1>
        <p className="mt-1 text-xs text-muted-foreground">Add a country and its currency to start selling transport there. Places are cities and pickup points (airport, station, bus stand, harbour) used on routes.</p>
      </div>

      <section className="space-y-2">
        <h2 className="font-display text-lg font-black">Countries</h2>
        <Card className="overflow-x-auto rounded-lg">
          <table className="w-full text-sm"><thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="p-3">Code</th><th className="p-3">Country</th><th className="p-3">Currency</th><th className="p-3">Time zone</th><th className="p-3">Status</th></tr></thead>
            <tbody>{countries.map((c) => (
              <tr key={c.code} className="border-b border-border/60 last:border-0" data-testid={`country-row-${c.code}`}><td className="p-3 font-mono">{c.code}</td><td className="p-3 font-medium">{c.name}</td><td className="p-3">{c.currency}</td><td className="p-3 text-xs">{c.timezone ?? "–"}</td>
                <td className="p-3"><Button variant="outline" size="sm" onClick={() => toggleCountry(c)}>{c.active ? "Active" : "Switched off"}</Button></td></tr>
            ))}</tbody></table>
          <form onSubmit={addCountry} className="grid gap-2 border-t border-border p-3 sm:grid-cols-5" data-testid="country-form">
            <div><Label className="label-caps">Code</Label><Input className="mt-1" value={cf.code} maxLength={2} onChange={(e) => setCf({ ...cf, code: e.target.value.toUpperCase() })} required placeholder="ID" data-testid="country-code" /></div>
            <div><Label className="label-caps">Country</Label><Input className="mt-1" value={cf.name} onChange={(e) => setCf({ ...cf, name: e.target.value })} required placeholder="Indonesia" data-testid="country-name" /></div>
            <div><Label className="label-caps">Currency</Label><Input className="mt-1" value={cf.currency} maxLength={3} onChange={(e) => setCf({ ...cf, currency: e.target.value.toUpperCase() })} required placeholder="IDR" data-testid="country-currency" /></div>
            <div><Label className="label-caps">Time zone</Label><Input className="mt-1" value={cf.timezone} onChange={(e) => setCf({ ...cf, timezone: e.target.value })} placeholder="Asia/Jakarta" /></div>
            <div className="flex items-end"><Button type="submit" data-testid="country-add">Add country</Button></div>
          </form>
        </Card>
      </section>

      <section className="space-y-2">
        <div className="flex items-center justify-between gap-2"><h2 className="font-display text-lg font-black">Places</h2>
          <Select value={filter} onValueChange={setFilter}><SelectTrigger className="h-8 w-[160px]"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="all">All countries</SelectItem>{countries.map((c) => <SelectItem key={c.code} value={c.code}>{c.name}</SelectItem>)}</SelectContent></Select></div>
        <Card className="overflow-x-auto rounded-lg">
          {shown.length === 0 ? <div className="p-6 text-center text-sm text-muted-foreground">No places yet.</div> : (
            <table className="w-full text-sm"><thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="p-3">Place</th><th className="p-3">Type</th><th className="p-3">City</th><th className="p-3">Country</th><th className="p-3">Code</th><th className="p-3" /></tr></thead>
              <tbody>{shown.map((p) => (
                <tr key={p.id} className="border-b border-border/60 last:border-0" data-testid={`place-row-${p.id}`}><td className="p-3 font-medium">{p.name}</td><td className="p-3">{KIND_LABEL[p.kind] ?? p.kind}</td><td className="p-3">{p.city ?? "–"}</td><td className="p-3">{p.country_code}</td><td className="p-3 font-mono text-xs">{p.code ?? ""}</td>
                  <td className="p-3 text-right"><Button variant="ghost" size="sm" onClick={() => delPlace(p)}><Trash2 size={13} /></Button></td></tr>
              ))}</tbody></table>
          )}
          <form onSubmit={addPlace} className="grid gap-2 border-t border-border p-3 sm:grid-cols-6" data-testid="place-form">
            <div><Label className="label-caps">Country</Label>
              <Select value={pf.country_code} onValueChange={(v) => setPf({ ...pf, country_code: v })}><SelectTrigger className="mt-1"><SelectValue /></SelectTrigger><SelectContent>{countries.map((c) => <SelectItem key={c.code} value={c.code}>{c.name}</SelectItem>)}</SelectContent></Select></div>
            <div className="sm:col-span-2"><Label className="label-caps">Place name</Label><Input className="mt-1" value={pf.name} onChange={(e) => setPf({ ...pf, name: e.target.value })} required placeholder="e.g. Manali Volvo Bus Stand" data-testid="place-name" /></div>
            <div><Label className="label-caps">Type</Label>
              <Select value={pf.kind} onValueChange={(v) => setPf({ ...pf, kind: v })}><SelectTrigger className="mt-1"><SelectValue /></SelectTrigger><SelectContent>{kinds.map((k) => <SelectItem key={k} value={k}>{KIND_LABEL[k] ?? k}</SelectItem>)}</SelectContent></Select></div>
            <div><Label className="label-caps">City</Label><Input className="mt-1" value={pf.city} onChange={(e) => setPf({ ...pf, city: e.target.value })} /></div>
            <div className="flex items-end"><Button type="submit" data-testid="place-add">Add place</Button></div>
          </form>
        </Card>
      </section>
    </div>
  );
}
