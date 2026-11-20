"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Bus, Download, Globe2, Loader2, Search, Upload } from "lucide-react";
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
import type { TransportMeta, TransportMode, TransportProvider } from "@/types";

export const MODE_LABEL: Record<string, string> = { cab: "Cab", van: "Van", bus: "Bus", ferry: "Ferry" };
export const modesText = (m: string[] | undefined) => (m ?? []).map((x) => MODE_LABEL[x] ?? x).join(" · ") || "No vehicles yet";

interface ImportResult { dry_run: boolean; providers_new: number; vehicles_seen: number; routes_seen: number; rates_new: number; rates_updated: number; errors: { row: number; error: string }[] }

function ImportDialog({ open, onOpenChange, onDone }: { open: boolean; onOpenChange: (o: boolean) => void; onDone: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [res, setRes] = useState<ImportResult | null>(null);
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => { if (open) { setFile(null); setRes(null); setBusy(false); } }, [open]);

  const template = async () => {
    try {
      const r = await api.get("/transport/import-template", { responseType: "blob" });
      const a = document.createElement("a"); a.href = URL.createObjectURL(r.data as Blob); a.download = "transport-rates-template.csv"; a.click();
    } catch (e) { toast.error(formatApiError(e)); }
  };
  const run = async (dry: boolean) => {
    if (!file) return;
    setBusy(true);
    try {
      const fd = new FormData(); fd.append("file", file); fd.append("dry_run", dry ? "1" : "0");
      const { data } = await api.post<ImportResult>("/transport/import", fd);
      setRes(data);
      if (!dry) { toast.success(`Imported: ${data.providers_new} new provider${data.providers_new === 1 ? "" : "s"}, ${data.rates_new} new and ${data.rates_updated} updated rates`); onDone(); onOpenChange(false); }
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
      <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto" data-testid="transport-import-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">Import transport rates</DialogTitle>
          <DialogDescription>One row per provider, vehicle, route and season. New providers, vehicles and routes are created; rows for rates that exist are updated.</DialogDescription>
        </DialogHeader>
        <div className="space-y-3 text-sm">
          <Button type="button" variant="outline" size="sm" onClick={template} data-testid="transport-template"><Download size={14} /> Download the template (CSV)</Button>
          <p className="text-xs text-muted-foreground">
            Country is the 2-letter code (IN, SG) and must be set up first under Countries and places. Mode: cab, van, bus or ferry. Basis: per_day, per_transfer, per_route or per_person.
            Rates are in the provider&apos;s own currency. Leave both dates empty if the client has not given the season dates yet; the rate is kept but marked &quot;dates needed&quot;.
          </p>
          <div>
            <input ref={input} type="file" accept=".csv,.xlsx" className="hidden" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setRes(null); }} data-testid="transport-import-file" />
            <Button type="button" variant="outline" onClick={() => input.current?.click()}><Upload size={14} /> {file ? file.name : "Choose the file"}</Button>
          </div>
          {res && (
            <div className="rounded-md border border-border p-3" data-testid="transport-import-result">
              <div className="font-medium">{res.dry_run ? "Check: nothing has been saved yet" : "Done"}</div>
              <div className="mt-1 text-xs text-muted-foreground">{res.providers_new} new provider{res.providers_new === 1 ? "" : "s"} · {res.vehicles_seen} vehicle type{res.vehicles_seen === 1 ? "" : "s"} · {res.routes_seen} route{res.routes_seen === 1 ? "" : "s"} · {res.rates_new} new rate{res.rates_new === 1 ? "" : "s"} · {res.rates_updated} updated</div>
              {res.errors.length > 0 && (
                <ul className="mt-2 max-h-36 list-disc space-y-0.5 overflow-y-auto pl-5 text-xs text-red-700 dark:text-red-400">{res.errors.map((e, i) => <li key={i}>Row {e.row}: {e.error}</li>)}</ul>
              )}
            </div>
          )}
        </div>
        <DialogFooter className="gap-2 sm:gap-2">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>Close</Button>
          <Button type="button" variant="outline" onClick={() => run(true)} disabled={!file || busy} data-testid="transport-import-check">{busy ? <Loader2 size={14} className="animate-spin" /> : null} Check first</Button>
          <Button type="button" onClick={() => run(false)} disabled={!file || busy || (res?.dry_run === true && res.errors.length > 0 && res.rates_new + res.rates_updated === 0)} data-testid="transport-import-go">Import</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function NewProviderDialog({ open, onOpenChange, meta, destinations }: { open: boolean; onOpenChange: (o: boolean) => void; meta: TransportMeta | null; destinations: string[] }) {
  const router = useRouter();
  const [f, setF] = useState({ name: "", country_code: "IN", destination: "", mode: "cab" as TransportMode });
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (open) setF({ name: "", country_code: meta?.countries.find((c) => c.active)?.code ?? "IN", destination: "", mode: "cab" }); }, [open, meta]);
  const currency = meta?.countries.find((c) => c.code === f.country_code)?.currency;
  const save = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true);
    try {
      const { data } = await api.post<TransportProvider>("/transport/providers", { name: f.name, country_code: f.country_code, destination: f.destination, modes: [f.mode] });
      toast.success("Provider added. Now add its vehicles, routes and rates."); onOpenChange(false); router.push(`/transport/${data.id}`);
    } catch (err) { toast.error(formatApiError(err)); }
    finally { setBusy(false); }
  };
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
      <DialogContent className="max-w-md" data-testid="new-transport-dialog">
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">New transport provider</DialogTitle><DialogDescription>A cab, van, bus or ferry company. Vehicles, routes and rates are added on its page.</DialogDescription></DialogHeader>
        <form onSubmit={save} className="space-y-3">
          <div><Label className="label-caps">Provider name</Label><Input className="mt-1.5" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required autoFocus data-testid="transport-name" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label className="label-caps">Country</Label>
              <Select value={f.country_code} onValueChange={(v) => setF({ ...f, country_code: v })}>
                <SelectTrigger className="mt-1.5" data-testid="transport-country"><SelectValue /></SelectTrigger>
                <SelectContent>{(meta?.countries ?? []).filter((c) => c.active).map((c) => <SelectItem key={c.code} value={c.code}>{c.name} ({c.currency})</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <div><Label className="label-caps">Destination</Label><Input className="mt-1.5" list="transport-destinations" value={f.destination} onChange={(e) => setF({ ...f, destination: e.target.value })} required placeholder="e.g. Manali" data-testid="transport-destination" />
              <datalist id="transport-destinations">{destinations.map((d) => <option key={d} value={d} />)}</datalist></div>
          </div>
          <div>
            <Label className="label-caps">Main service</Label>
            <Select value={f.mode} onValueChange={(v) => setF({ ...f, mode: v as TransportMode })}>
              <SelectTrigger className="mt-1.5"><SelectValue /></SelectTrigger>
              <SelectContent>{(meta?.modes ?? ["cab", "van", "bus", "ferry"]).map((m) => <SelectItem key={m} value={m}>{MODE_LABEL[m] ?? m}</SelectItem>)}</SelectContent>
            </Select>
          </div>
          {currency && <p className="text-xs text-muted-foreground">Rates for this provider are entered in {currency}.</p>}
          <DialogFooter className="gap-2 sm:gap-2"><Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>Cancel</Button><Button type="submit" disabled={busy} data-testid="transport-create">{busy ? "Adding…" : "Add provider"}</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function TransportPage() {
  const { can } = useCurrentUser();
  const manage = can("transport.manage");
  const [rows, setRows] = useState<TransportProvider[] | null>(null);
  const [meta, setMeta] = useState<TransportMeta | null>(null);
  const [destinations, setDestinations] = useState<string[]>([]);
  const [q, setQ] = useState("");
  const [dest, setDest] = useState("all");
  const [country, setCountry] = useState("all");
  const [showInactive, setShowInactive] = useState(false);
  const [adding, setAdding] = useState(false);
  const [importing, setImporting] = useState(false);

  const load = useCallback(() => {
    api.get<TransportProvider[]>("/transport/providers", { params: { active: showInactive ? "0" : "1" } }).then((r) => setRows(r.data)).catch((e) => toast.error(formatApiError(e)));
    api.get<string[]>("/transport/destinations").then((r) => setDestinations(r.data)).catch(() => setDestinations([]));
    api.get<TransportMeta>("/transport/meta").then((r) => setMeta(r.data)).catch(() => setMeta(null));
  }, [showInactive]);
  useEffect(() => { load(); }, [load]);

  const shown = useMemo(() => (rows ?? []).filter((p) => {
    const t = q.trim().toLowerCase();
    return (dest === "all" || p.destination === dest) && (country === "all" || p.country_code === country)
      && (!t || p.name.toLowerCase().includes(t) || p.destination.toLowerCase().includes(t) || (p.city ?? "").toLowerCase().includes(t));
  }), [rows, q, dest, country]);

  return (
    <div className="space-y-4" data-testid="transport-page">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="label-caps">Operations</div>
          <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Transport</h1>
          <p className="mt-1 text-xs text-muted-foreground">{manage ? "Cabs, vans, buses and ferries with their vehicles, routes and vendor rates, in any country. Quotations choose from here." : "The transport we sell. Pick it when you build a quotation."}</p>
        </div>
        {manage && (
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" asChild><Link href="/transport/places" data-testid="transport-places-link"><Globe2 size={15} /> Countries and places</Link></Button>
            <Button variant="outline" onClick={() => setImporting(true)} data-testid="transport-import-btn"><Upload size={15} /> Import rates</Button>
            <Button onClick={() => setAdding(true)} data-testid="transport-new-btn">+ Add transport</Button>
          </div>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[220px] max-w-[320px] flex-1">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search provider or destination" className="h-9 pl-9" data-testid="transport-search" />
        </div>
        <Select value={country} onValueChange={setCountry}>
          <SelectTrigger className="h-9 w-[160px]"><SelectValue placeholder="All countries" /></SelectTrigger>
          <SelectContent><SelectItem value="all">All countries</SelectItem>{(meta?.countries ?? []).map((c) => <SelectItem key={c.code} value={c.code}>{c.name}</SelectItem>)}</SelectContent>
        </Select>
        <Select value={dest} onValueChange={setDest}>
          <SelectTrigger className="h-9 w-[180px]" data-testid="transport-filter-destination"><SelectValue placeholder="All destinations" /></SelectTrigger>
          <SelectContent><SelectItem value="all">All destinations</SelectItem>{destinations.map((d) => <SelectItem key={d} value={d}>{d}</SelectItem>)}</SelectContent>
        </Select>
        {manage && <label className="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground"><input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} className="accent-black dark:accent-white" /> Show switched-off providers</label>}
        <span className="text-xs text-muted-foreground">{shown.length} provider{shown.length === 1 ? "" : "s"}</span>
      </div>

      {rows === null && <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>}
      {rows && shown.length === 0 && (
        <Card className="rounded-lg p-10 text-center text-sm text-muted-foreground" data-testid="transport-empty">
          {rows.length === 0 ? (manage ? "No transport yet. Add a provider, or import your rate sheet." : "No transport yet. Operations adds it.") : "No providers match these filters."}
        </Card>
      )}
      {shown.length > 0 && (
        <Card className="overflow-x-auto rounded-lg">
          <table className="w-full min-w-[720px] text-sm">
            <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
              <th className="p-3">Provider</th><th className="p-3">Destination</th><th className="p-3">Currency</th><th className="p-3 text-right">Vehicles</th><th className="p-3 text-right">Routes</th>
              {manage && <th className="p-3 text-right">Rates</th>}{manage && <th className="p-3">Margin</th>}<th className="p-3">Status</th>
            </tr></thead>
            <tbody>
              {shown.map((p) => (
                <tr key={p.id} className={cn("border-b border-border/60 last:border-0 hover:bg-secondary/50", !p.active && "opacity-60")} data-testid={`transport-row-${p.id}`}>
                  <td className="p-3"><Link href={`/transport/${p.id}`} className="flex items-center gap-2 font-medium hover:underline"><Bus size={15} className="shrink-0 text-muted-foreground" />{p.name}</Link><div className="ml-[23px] text-xs text-muted-foreground">{modesText(p.modes)}</div></td>
                  <td className="p-3">{p.destination}<div className="text-xs text-muted-foreground">{p.country_code}</div></td>
                  <td className="p-3">{p.currency}</td>
                  <td className="p-3 text-right">{p.vehicles_count ?? 0}</td>
                  <td className="p-3 text-right">{p.routes_count ?? 0}</td>
                  {manage && <td className="p-3 text-right">{p.rates_count ?? 0}</td>}
                  {manage && <td className="p-3 text-xs">{p.margin_type ? (p.margin_type === "percent" ? `${p.margin_value}%` : `${p.margin_value} per person`) : <span className="text-muted-foreground">Destination default</span>}</td>}
                  <td className="p-3"><span className={cn("rounded-full px-2 py-0.5 text-[11px] font-medium", p.active ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300" : "bg-secondary text-muted-foreground")}>{p.active ? "Active" : "Off"}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
      {manage && <NewProviderDialog open={adding} onOpenChange={setAdding} meta={meta} destinations={destinations} />}
      {manage && <ImportDialog open={importing} onOpenChange={setImporting} onDone={load} />}
    </div>
  );
}
