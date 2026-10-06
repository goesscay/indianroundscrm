"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Loader2, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { PackageDay, PackageSeason, TravelPackage } from "@/types";
import { PACKAGE_CATEGORIES } from "./shared";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Package id to edit / view; omit to create. */
  packageId?: string | null;
  /** View-only mode for people who cannot manage packages. */
  readOnly?: boolean;
  destinations?: string[];
  onSaved: () => void;
}

const field = "mt-1.5 h-10";
const blankSeason = (): PackageSeason => ({ name: "", start_date: "", end_date: "", adult_price: 0, child_price: 0, adult_cost: 0, child_cost: 0 });

function Section({ title, children, action }: { title: string; children: React.ReactNode; action?: React.ReactNode }) {
  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between border-b border-border pb-1.5"><h4 className="label-caps">{title}</h4>{action}</div>
      {children}
    </div>
  );
}

export default function PackageDialog({ open, onOpenChange, packageId, readOnly, destinations = [], onSaved }: Props) {
  const editing = !!packageId;
  const [f, setF] = useState<Record<string, string>>({});
  const [category, setCategory] = useState("domestic");
  const [active, setActive] = useState(true);
  const [seasons, setSeasons] = useState<PackageSeason[]>([]);
  const [days, setDays] = useState<PackageDay[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setError(""); setSaving(false);
    const apply = (p: TravelPackage | null) => {
      const s = (v: unknown) => (v == null ? "" : String(v));
      setF({
        name: s(p?.name), code: s(p?.code), destination: s(p?.destination), nights: s(p?.nights ?? ""), days: s(p?.days ?? ""),
        adult_price: s(p?.adult_price || ""), child_price: s(p?.child_price || ""), adult_cost: s(p?.adult_cost || ""), child_cost: s(p?.child_cost || ""),
        gst_rate: s(p?.gst_rate ?? 5), summary: s(p?.summary), inclusions: s(p?.inclusions), exclusions: s(p?.exclusions), terms: s(p?.terms),
      });
      setCategory(p?.category ?? "domestic"); setActive(p?.active ?? true);
      setSeasons((p?.seasons ?? []).map((x) => ({ ...x }))); setDays((p?.itinerary ?? []).map((x) => ({ ...x })));
    };
    if (!packageId) { apply(null); return; }
    setLoading(true);
    api.get<TravelPackage>(`/packages/${packageId}`).then((r) => apply(r.data)).catch((e) => setError(formatApiError(e))).finally(() => setLoading(false));
  }, [open, packageId]);

  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setF((p) => ({ ...p, [k]: e.target.value }));
  const num = (v: string) => (v.trim() === "" ? 0 : Number(v));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (readOnly) return;
    if (!f.name?.trim()) { setError("Please enter the package name."); return; }
    setSaving(true); setError("");
    const body = {
      name: f.name.trim(), code: f.code?.trim() || null, destination: f.destination?.trim() || null, category, active,
      nights: num(f.nights), days: num(f.days), adult_price: num(f.adult_price), child_price: num(f.child_price),
      adult_cost: num(f.adult_cost), child_cost: num(f.child_cost), gst_rate: f.gst_rate?.trim() === "" ? 5 : Number(f.gst_rate),
      summary: f.summary, inclusions: f.inclusions, exclusions: f.exclusions, terms: f.terms,
      seasons: seasons.filter((s) => s.name.trim()), itinerary: days.filter((d) => d.title.trim()),
    };
    try {
      if (editing) await api.patch(`/packages/${packageId}`, body); else await api.post("/packages", body);
      toast.success(editing ? "Package updated" : "Package created");
      onSaved(); onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  const updSeason = (i: number, patch: Partial<PackageSeason>) => setSeasons((l) => l.map((x, k) => (k === i ? { ...x, ...patch } : x)));
  const updDay = (i: number, patch: Partial<PackageDay>) => setDays((l) => l.map((x, k) => (k === i ? { ...x, ...patch } : x)));
  const ro = !!readOnly;

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-h-[92vh] max-w-3xl overflow-y-auto" data-testid="package-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">{ro ? f.name || "Package" : editing ? `Edit ${f.name || "package"}` : "New package"}</DialogTitle>
          <DialogDescription>{ro ? f.code : "A ready-made trip you can drop into a quote. Prices are per person."}</DialogDescription>
        </DialogHeader>

        {loading ? <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground"><Loader2 size={16} className="animate-spin" /> Loading…</div> : (
          <form onSubmit={submit} className="space-y-6">
            <Section title="Package">
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2"><Label htmlFor="p-name">Name *</Label><Input id="p-name" className={field} value={f.name ?? ""} onChange={set("name")} disabled={ro} autoFocus data-testid="package-name" /></div>
                <div>
                  <Label htmlFor="p-dest">Destination</Label>
                  <Input id="p-dest" className={field} value={f.destination ?? ""} onChange={set("destination")} list="pkg-destinations" disabled={ro} />
                  <datalist id="pkg-destinations">{destinations.map((d) => <option key={d} value={d} />)}</datalist>
                </div>
                <div>
                  <Label>Category</Label>
                  <Select value={category} onValueChange={setCategory} disabled={ro}>
                    <SelectTrigger className={field}><SelectValue /></SelectTrigger>
                    <SelectContent>{PACKAGE_CATEGORIES.map((c) => <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>)}</SelectContent>
                  </Select>
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div><Label htmlFor="p-n">Nights</Label><Input id="p-n" type="number" min={0} className={field} value={f.nights ?? ""} onChange={set("nights")} disabled={ro} /></div>
                  <div><Label htmlFor="p-d">Days</Label><Input id="p-d" type="number" min={0} className={field} value={f.days ?? ""} onChange={set("days")} disabled={ro} /></div>
                </div>
                <div><Label htmlFor="p-code">Code</Label><Input id="p-code" className={field} value={f.code ?? ""} onChange={set("code")} placeholder="Auto: PKG-0001" disabled={ro} /></div>
                <div className="sm:col-span-2"><Label htmlFor="p-sum">Summary</Label><Textarea id="p-sum" rows={2} className="mt-1.5" value={f.summary ?? ""} onChange={set("summary")} disabled={ro} /></div>
              </div>
            </Section>

            <Section title="Prices (per person, ₹)">
              <div className="grid gap-3 sm:grid-cols-4">
                <div><Label htmlFor="p-ap">Adult price</Label><Input id="p-ap" type="number" min={0} className={field} value={f.adult_price ?? ""} onChange={set("adult_price")} disabled={ro} data-testid="package-adult-price" /></div>
                <div><Label htmlFor="p-cp">Child price</Label><Input id="p-cp" type="number" min={0} className={field} value={f.child_price ?? ""} onChange={set("child_price")} disabled={ro} /></div>
                <div><Label htmlFor="p-ac">Adult cost</Label><Input id="p-ac" type="number" min={0} className={field} value={f.adult_cost ?? ""} onChange={set("adult_cost")} disabled={ro} /></div>
                <div><Label htmlFor="p-cc">Child cost</Label><Input id="p-cc" type="number" min={0} className={field} value={f.child_cost ?? ""} onChange={set("child_cost")} disabled={ro} /></div>
                <div><Label htmlFor="p-gst">GST %</Label><Input id="p-gst" type="number" min={0} max={100} step="0.5" className={field} value={f.gst_rate ?? ""} onChange={set("gst_rate")} disabled={ro} /></div>
              </div>
              <p className="text-xs text-muted-foreground">Cost is what the supplier charges you. It is never shown to the customer; it feeds the margin on deals.</p>
            </Section>

            <Section title="Seasonal prices" action={!ro && <Button type="button" size="sm" variant="outline" onClick={() => setSeasons((l) => [...l, blankSeason()])} data-testid="add-season"><Plus size={14} /> Add season</Button>}>
              {seasons.length === 0 && <p className="text-sm text-muted-foreground">No seasons. The prices above apply to every date. Add a season (peak, festival, off-season) to override them for a date range.</p>}
              {seasons.map((s, i) => (
                <div key={i} className="space-y-2 rounded border border-border p-3">
                  <div className="grid gap-2 sm:grid-cols-[1.4fr_1fr_1fr_auto]">
                    <Input aria-label="Season name" placeholder="Season (e.g. Peak)" value={s.name} onChange={(e) => updSeason(i, { name: e.target.value })} disabled={ro} />
                    <Input aria-label="From" type="date" value={s.start_date} onChange={(e) => updSeason(i, { start_date: e.target.value })} disabled={ro} />
                    <Input aria-label="To" type="date" value={s.end_date} onChange={(e) => updSeason(i, { end_date: e.target.value })} disabled={ro} />
                    {!ro && <Button type="button" size="icon" variant="ghost" aria-label="Remove season" onClick={() => setSeasons((l) => l.filter((_, k) => k !== i))}><Trash2 size={15} /></Button>}
                  </div>
                  <div className="grid gap-2 sm:grid-cols-4">
                    {(["adult_price", "child_price", "adult_cost", "child_cost"] as const).map((k) => (
                      <Input key={k} type="number" min={0} aria-label={k.replace("_", " ")} placeholder={k.replace("_", " ")} value={s[k] || ""} onChange={(e) => updSeason(i, { [k]: Number(e.target.value) || 0 })} disabled={ro} />
                    ))}
                  </div>
                </div>
              ))}
            </Section>

            <Section title="Day-by-day itinerary" action={!ro && <Button type="button" size="sm" variant="outline" onClick={() => setDays((l) => [...l, { title: "", description: "" }])} data-testid="add-day"><Plus size={14} /> Add day</Button>}>
              {days.length === 0 && <p className="text-sm text-muted-foreground">Add the days so quotes can print the itinerary.</p>}
              {days.map((d, i) => (
                <div key={i} className="grid gap-2 rounded border border-border p-3 sm:grid-cols-[auto_1fr_auto]">
                  <div className="pt-2 text-sm font-semibold text-muted-foreground">Day {i + 1}</div>
                  <div className="space-y-2">
                    <Input aria-label={`Day ${i + 1} title`} placeholder="Title (e.g. Arrival and beach evening)" value={d.title} onChange={(e) => updDay(i, { title: e.target.value })} disabled={ro} />
                    <Textarea aria-label={`Day ${i + 1} details`} rows={2} placeholder="What happens this day" value={d.description ?? ""} onChange={(e) => updDay(i, { description: e.target.value })} disabled={ro} />
                  </div>
                  {!ro && <Button type="button" size="icon" variant="ghost" aria-label="Remove day" onClick={() => setDays((l) => l.filter((_, k) => k !== i))}><Trash2 size={15} /></Button>}
                </div>
              ))}
            </Section>

            <Section title="Inclusions, exclusions and terms">
              <div className="grid gap-3 sm:grid-cols-2">
                <div><Label htmlFor="p-inc">Inclusions</Label><Textarea id="p-inc" rows={5} className="mt-1.5" placeholder="One per line" value={f.inclusions ?? ""} onChange={set("inclusions")} disabled={ro} /></div>
                <div><Label htmlFor="p-exc">Exclusions</Label><Textarea id="p-exc" rows={5} className="mt-1.5" placeholder="One per line" value={f.exclusions ?? ""} onChange={set("exclusions")} disabled={ro} /></div>
                <div className="sm:col-span-2"><Label htmlFor="p-terms">Terms &amp; conditions</Label><Textarea id="p-terms" rows={4} className="mt-1.5" value={f.terms ?? ""} onChange={set("terms")} disabled={ro} /></div>
              </div>
            </Section>

            <label className="flex cursor-pointer items-center gap-2 text-sm">
              <Checkbox checked={active} onCheckedChange={(v) => setActive(v === true)} disabled={ro} data-testid="package-active" /> Active (can be added to new quotes)
            </label>

            {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>{ro ? "Close" : "Cancel"}</Button>
              {!ro && <Button type="submit" disabled={saving} data-testid="package-save">{saving && <Loader2 size={14} className="animate-spin" />} {editing ? "Save changes" : "Create package"}</Button>}
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
