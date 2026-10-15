"use client";

import { Suspense, useEffect, useMemo, useRef, useState } from "react";
import { Globe, RefreshCw, Search, UserCheck, X } from "lucide-react";
import { api } from "@/lib/api";
import { usePagedList } from "@/lib/usePagedList";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import PackageDialog from "@/components/crm/PackageDialog";
import { INR, PACKAGE_CATEGORIES, categoryLabel } from "@/components/crm/shared";
import { formatApiError } from "@/lib/api";
import { myDestinations } from "@/lib/places";
import { Checkbox } from "@/components/ui/checkbox";
import PackageAssignDialog from "@/components/crm/PackageAssignDialog";
import { toast } from "sonner";
import type { TravelPackage, WebsiteSyncResult } from "@/types";

function PackageList() {
  const { user, can } = useCurrentUser();
  const canManage = can("packages.manage");
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [category, setCategory] = useState("all");
  const [destination, setDestination] = useState("all");
  const [active, setActive] = useState("all");
  const [destinations, setDestinations] = useState<string[]>([]);
  const mineHere = myDestinations(user).filter((d) => destinations.includes(d));   // their destinations that the catalogue has packages for
  const [tick, setTick] = useState(0);
  const defaultApplied = useRef(false);
  const [website, setWebsite] = useState<{ packages: number; unlocked: number; synced_at: string | null } | null>(null);
  const [syncing, setSyncing] = useState(false);
  const [sel, setSel] = useState<Set<string>>(new Set());
  const [assignOpen, setAssignOpen] = useState(false);
  const seesAll = can("packages.view_all", "packages.manage");
  const [dlg, setDlg] = useState<{ open: boolean; id: string | null }>({ open: false, id: null });

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (category !== "all") p.category = category;
    if (destination === "mine") p.destination = myDestinations(user).filter((d) => destinations.includes(d)).join("|");
    else if (destination !== "all") p.destination = destination;
    if (active !== "all") p.active = active;
    return p;
  }, [applied, category, destination, active, user, destinations]);
  const { rows, loading, loaded, error, paginationProps } = usePagedList<TravelPackage>("/packages", params, tick);

  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);
  useEffect(() => {
    api.get<{ destinations: string[]; website?: { packages: number; unlocked: number; synced_at: string | null } }>("/packages/options").then((r) => {
      setDestinations(r.data.destinations);
      setWebsite(r.data.website ?? null);
      // start on their own destination when the catalogue has packages for it (once, so a later change sticks)
      const mine = myDestinations(user);
      if (!defaultApplied.current && mine.some((d) => r.data.destinations.includes(d))) { setDestination("mine"); }
      defaultApplied.current = true;
    }).catch(() => { /* filter stays empty */ });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tick]);

  const sync = async () => {
    setSyncing(true);
    try {
      const { data: r } = await api.post<WebsiteSyncResult>("/packages/sync-website");
      toast.success(`${r.tours} tours read: ${r.created} new, ${r.updated} updated, ${r.unchanged} unchanged${r.deactivated ? `, ${r.deactivated} hidden` : ""}${r.failed ? `, ${r.failed} could not be read` : ""}.`);
      setTick((t) => t + 1);
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setSyncing(false); }
  };
  const pageIds = rows.map((p) => p.id);
  const allOnPage = pageIds.length > 0 && pageIds.every((id) => sel.has(id));
  const toggleOne = (id: string) => setSel((cur) => { const n = new Set(cur); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  const togglePage = () => setSel((cur) => { const n = new Set(cur); pageIds.forEach((id) => (allOnPage ? n.delete(id) : n.add(id))); return n; });

  const activeFilters = (q.trim() ? 1 : 0) + [category, destination, active].filter((v) => v !== "all").length;

  return (
    <div className="space-y-6" data-testid="packages-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="label-caps">Sales</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">Packages</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {seesAll ? "What you sell: prices per person, seasonal rates, itineraries and inclusions. Quotes pick from this list." : "The packages Operations has assigned to you. Quotes pick from this list."}
          </p>
          {canManage && website && (
            <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground" data-testid="website-sync-info">
              <Globe size={12} /> {website.packages} packages from the website
              {website.synced_at ? `, last synced ${new Date(website.synced_at).toLocaleString("en-IN", { dateStyle: "medium", timeStyle: "short" })}` : ", not synced yet"}
              {website.unlocked ? `, ${website.unlocked} unlocked` : ""}. Refreshes every night.
            </p>
          )}
        </div>
        {canManage && (
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={sync} disabled={syncing} data-testid="sync-website-btn"><RefreshCw size={15} className={syncing ? "animate-spin" : ""} /> {syncing ? "Syncing…" : "Sync from website"}</Button>
            <Button variant="outline" onClick={() => setAssignOpen(true)} data-testid="assign-btn"><UserCheck size={15} /> Assign to sales{sel.size ? ` (${sel.size})` : ""}</Button>
            <Button onClick={() => setDlg({ open: true, id: null })} data-testid="new-package-btn">+ New package</Button>
          </div>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3" data-testid="packages-filters">
        <div className="relative min-w-[220px] max-w-[340px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === "Enter" && setApplied(q)} placeholder="Search name, code, destination…" className="h-10 pl-9" data-testid="package-search" />
        </div>
        <Select value={category} onValueChange={setCategory}>
          <SelectTrigger className="h-10 w-[160px]" data-testid="filter-package-category"><SelectValue placeholder="All categories" /></SelectTrigger>
          <SelectContent><SelectItem value="all">All categories</SelectItem>{PACKAGE_CATEGORIES.map((c) => <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>)}</SelectContent>
        </Select>
        <Select value={destination} onValueChange={setDestination}>
          <SelectTrigger className="h-10 w-[170px]" data-testid="filter-package-destination"><SelectValue placeholder="All destinations" /></SelectTrigger>
          <SelectContent><SelectItem value="all">All destinations</SelectItem>{mineHere.length > 0 && <SelectItem value="mine">My destinations ({mineHere.join(", ")})</SelectItem>}{destinations.map((d) => <SelectItem key={d} value={d}>{d}</SelectItem>)}</SelectContent>
        </Select>
        <Select value={active} onValueChange={setActive}>
          <SelectTrigger className="h-10 w-[150px]"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">Active and inactive</SelectItem><SelectItem value="1">Active only</SelectItem><SelectItem value="0">Inactive only</SelectItem></SelectContent>
        </Select>
        {activeFilters > 0 && <Button variant="ghost" size="sm" onClick={() => { setQ(""); setApplied(""); setCategory("all"); setDestination("all"); setActive("all"); }}><X size={14} /> Clear filters</Button>}
      </div>

      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                {canManage && <TableHead className="w-8"><Checkbox checked={allOnPage} onCheckedChange={togglePage} aria-label="Select all on this page" data-testid="package-select-page" /></TableHead>}
                <TableHead>Package</TableHead><TableHead>Destination</TableHead><TableHead>Category</TableHead><TableHead>Duration</TableHead>
                <TableHead className="text-right">Adult</TableHead><TableHead className="text-right">Child</TableHead><TableHead className="text-right">GST</TableHead>{canManage && <TableHead>Seen by</TableHead>}<TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={10} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && <TableRow><TableCell colSpan={10} className="py-12 text-center text-muted-foreground">{activeFilters ? "No packages match these filters." : seesAll ? "No packages yet." : "No packages have been assigned to you yet. Ask Operations to assign some."}</TableCell></TableRow>}
              {rows.map((p) => (
                <TableRow key={p.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => setDlg({ open: true, id: p.id })} data-testid={`package-row-${p.id}`}>
                  {canManage && <TableCell onClick={(e) => e.stopPropagation()}><Checkbox checked={sel.has(p.id)} onCheckedChange={() => toggleOne(p.id)} aria-label={`Select ${p.name}`} data-testid={`package-select-${p.id}`} /></TableCell>}
                  <TableCell>
                    <div className="flex items-center gap-1.5 font-medium">{p.name}{p.source === "website" && <span className="inline-flex items-center gap-0.5 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 dark:bg-sky-950/60 dark:text-sky-300" title={p.source_unlocked ? "From the website, unlocked" : "From the website"} data-testid={`package-web-${p.id}`}><Globe size={10} />{p.source_unlocked ? "Web · unlocked" : "Web"}</span>}</div>
                    <div className="font-mono text-xs text-muted-foreground">{p.code}</div>
                  </TableCell>
                  <TableCell className="text-sm">{p.destination || "—"}</TableCell>
                  <TableCell className="text-sm">{categoryLabel(p.category)}</TableCell>
                  <TableCell className="text-sm">{p.days ? `${p.nights}N / ${p.days}D` : "—"}</TableCell>
                  <TableCell className="text-right font-semibold">{INR(p.adult_price)}</TableCell>
                  <TableCell className="text-right">{p.child_price ? INR(p.child_price) : "—"}</TableCell>
                  <TableCell className="text-right text-sm text-muted-foreground">{p.gst_rate}%</TableCell>
                  {canManage && <TableCell className="text-xs text-muted-foreground" data-testid={`package-seen-${p.id}`}>{p.assignees_count ? `${p.assignees_count} ${p.assignees_count === 1 ? "person" : "people"}` : "No sales people"}</TableCell>}
                  <TableCell><span className={cn("rounded border px-2 py-0.5 text-xs", p.active ? "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300" : "border-border text-muted-foreground")}>{p.active ? "Active" : "Inactive"}</span></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
      {error && <div className="text-sm text-destructive">{error}</div>}
      {canManage && <PackageAssignDialog open={assignOpen} onOpenChange={setAssignOpen} selectedIds={Array.from(sel)} destinations={destinations} onDone={() => { setTick((t) => t + 1); setSel(new Set()); }} />}

      <PackageDialog open={dlg.open} onOpenChange={(o) => setDlg((d) => ({ ...d, open: o }))} packageId={dlg.id} readOnly={!canManage} destinations={destinations} onSaved={() => setTick((t) => t + 1)} />
    </div>
  );
}

export default function PackagesPage() {
  return <Suspense fallback={null}><PackageList /></Suspense>;
}
