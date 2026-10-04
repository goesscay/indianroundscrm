"use client";

import { useEffect, useState, type FormEvent } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "sonner";
import { X, Plus } from "lucide-react";
import type { DestinationCities, MasterItem } from "@/types";

const CATEGORIES = [
  { key: "destination", label: "Destinations" },
  { key: "hotel_category", label: "Hotel Categories" },
  { key: "meal_plan", label: "Meal Plans" },
  { key: "package_type", label: "Package Types" },
  { key: "room_type", label: "Room Types" },
  { key: "departure_city", label: "Departure Cities" },
  { key: "payment_mode", label: "Payment Modes" },
  { key: "lead_source", label: "Lead Sources" },
];

function DestinationCitiesEditor() {
  const [dcMap, setDcMap] = useState<DestinationCities>({});
  const [destinations, setDestinations] = useState<string[]>([]);
  const [selDest, setSelDest] = useState("");
  const [newCity, setNewCity] = useState("");

  const load = async () => {
    try {
      const [dc, master] = await Promise.all([
        api.get<DestinationCities>("/destination-cities"),
        api.get<MasterItem[]>("/master?category=destination"),
      ]);
      setDcMap(dc.data || {});
      const dests = (master.data || []).map(m => m.value).sort();
      setDestinations(dests);
      if (!selDest && dests.length) setSelDest(dests[0]);
    } catch (e) { toast.error(formatApiError(e)); }
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, []);

  const cities = dcMap[selDest] || [];

  const saveList = async (updated: string[]) => {
    try {
      await api.put(`/destination-cities/${encodeURIComponent(selDest)}`, { cities: updated });
      setDcMap(p => ({ ...p, [selDest]: updated }));
      toast.success("Saved");
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const addCity = () => {
    const v = newCity.trim();
    if (!v || !selDest) return;
    if (cities.some(c => c.toLowerCase() === v.toLowerCase())) { toast.info("Already exists"); return; }
    saveList([...cities, v]);
    setNewCity("");
  };
  const removeCity = (city: string) => saveList(cities.filter(c => c !== city));
  const move = (idx: number, delta: number) => {
    const arr = [...cities];
    const j = idx + delta;
    if (j < 0 || j >= arr.length) return;
    [arr[idx], arr[j]] = [arr[j], arr[idx]];
    saveList(arr);
  };

  return (
    <Card className="p-6 rounded-lg" data-testid="dest-cities-editor">
      <div className="flex items-end justify-between flex-wrap gap-3 mb-4">
        <div>
          <div className="label-caps">Smart lookup</div>
          <h3 className="font-display text-xl font-bold">Destination → Cities Mapping</h3>
          <p className="text-xs text-muted-foreground mt-1">Cities shown to Sales when they pick a destination. Reorder to control display sequence.</p>
        </div>
        <div className="min-w-[220px]">
          <Select value={selDest || undefined} onValueChange={setSelDest}>
            <SelectTrigger className="h-10" data-testid="dc-dest-select"><SelectValue placeholder="Choose destination" /></SelectTrigger>
            <SelectContent>
              {destinations.map(d => <SelectItem key={d} value={d}>{d}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
      </div>

      {selDest ? (
        <>
          <div className="flex gap-2 mb-3">
            <Input value={newCity} onChange={e => setNewCity(e.target.value)}
                   onKeyDown={e => e.key === "Enter" && (e.preventDefault(), addCity())}
                   placeholder={`Add a city to ${selDest}…`} data-testid="dc-new-city" />
            <Button onClick={addCity} style={{ background: "#106EBE" }} data-testid="dc-add"><Plus size={14} /> Add</Button>
          </div>
          <div className="space-y-1.5">
            {cities.length === 0 && <div className="text-xs text-muted-foreground py-4 text-center border border-dashed rounded-md">No cities yet — add above</div>}
            {cities.map((c, i) => (
              <div key={c} className="flex items-center gap-2 px-3 py-2 rounded-md bg-secondary/40 border border-border" data-testid={`dc-row-${c}`}>
                <span className="text-xs text-muted-foreground font-mono w-6">{i + 1}.</span>
                <span className="flex-1 text-sm font-medium">{c}</span>
                <button onClick={() => move(i, -1)} disabled={i === 0} className="text-xs text-muted-foreground hover:text-foreground disabled:opacity-30" title="Move up">▲</button>
                <button onClick={() => move(i, 1)} disabled={i === cities.length - 1} className="text-xs text-muted-foreground hover:text-foreground disabled:opacity-30" title="Move down">▼</button>
                <button onClick={() => removeCity(c)} className="text-muted-foreground hover:text-destructive" title="Remove"><X size={14} /></button>
              </div>
            ))}
          </div>
        </>
      ) : (
        <div className="text-sm text-muted-foreground">Select a destination to manage its cities.</div>
      )}
    </Card>
  );
}

export default function MasterData() {
  const [items, setItems] = useState<MasterItem[]>([]);
  const [category, setCategory] = useState("destination");
  const [value, setValue] = useState("");

  const load = async () => {
    try { const { data } = await api.get<MasterItem[]>("/master"); setItems(data); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  useEffect(() => { load(); }, []);

  const add = async (e: FormEvent) => {
    e.preventDefault();
    if (!value.trim()) return;
    try { await api.post("/master", { category, value: value.trim() }); toast.success("Added"); setValue(""); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const remove = async (id: string) => {
    try { await api.delete(`/master/${id}`); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <div className="space-y-6" data-testid="master-page">
      <div>
        <div className="label-caps">Configuration</div>
        <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Master Data</h1>
      </div>

      <Card className="p-6 rounded-lg">
        <form onSubmit={add} className="flex flex-wrap gap-3 items-end">
          <div className="flex-1 min-w-[200px]">
            <Select value={category} onValueChange={setCategory}>
              <SelectTrigger className="h-10" data-testid="m-category"><SelectValue /></SelectTrigger>
              <SelectContent>
                {CATEGORIES.map(c => <SelectItem key={c.key} value={c.key}>{c.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="flex-1 min-w-[200px]">
            <Input value={value} onChange={e => setValue(e.target.value)} placeholder="Add new value…" data-testid="m-value" />
          </div>
          <Button type="submit" style={{ background: "#106EBE" }} data-testid="m-add">Add</Button>
        </form>
      </Card>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {CATEGORIES.map(c => {
          const rows = items.filter(i => i.category === c.key);
          return (
            <Card key={c.key} className="p-5 rounded-lg" data-testid={`m-cat-${c.key}`}>
              <div className="label-caps">{c.label}</div>
              <div className="mt-3 flex flex-wrap gap-2">
                {rows.length === 0 && <div className="text-xs text-muted-foreground">No items</div>}
                {rows.map(r => (
                  <span key={r.id} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-secondary text-sm">
                    {r.value}
                    <button onClick={() => remove(r.id)} className="text-muted-foreground hover:text-destructive"><X size={12} /></button>
                  </span>
                ))}
              </div>
            </Card>
          );
        })}
      </div>

      <DestinationCitiesEditor />
    </div>
  );
}
