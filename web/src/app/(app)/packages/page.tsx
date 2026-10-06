"use client";

import { Suspense, useEffect, useMemo, useState } from "react";
import { Search, X } from "lucide-react";
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
import type { TravelPackage } from "@/types";

function PackageList() {
  const { can } = useCurrentUser();
  const canManage = can("packages.manage");
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [category, setCategory] = useState("all");
  const [destination, setDestination] = useState("all");
  const [active, setActive] = useState("all");
  const [destinations, setDestinations] = useState<string[]>([]);
  const [tick, setTick] = useState(0);
  const [dlg, setDlg] = useState<{ open: boolean; id: string | null }>({ open: false, id: null });

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (category !== "all") p.category = category;
    if (destination !== "all") p.destination = destination;
    if (active !== "all") p.active = active;
    return p;
  }, [applied, category, destination, active]);
  const { rows, loading, loaded, error, paginationProps } = usePagedList<TravelPackage>("/packages", params, tick);

  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);
  useEffect(() => {
    api.get<{ destinations: string[] }>("/packages/options").then((r) => setDestinations(r.data.destinations)).catch(() => { /* filter stays empty */ });
  }, [tick]);

  const activeFilters = (q.trim() ? 1 : 0) + [category, destination, active].filter((v) => v !== "all").length;

  return (
    <div className="space-y-6" data-testid="packages-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="label-caps">Sales</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">Packages</h1>
          <p className="mt-1 text-sm text-muted-foreground">What you sell: prices per person, seasonal rates, itineraries and inclusions. Quotes pick from this list.</p>
        </div>
        {canManage && <Button onClick={() => setDlg({ open: true, id: null })} data-testid="new-package-btn">+ New package</Button>}
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
          <SelectContent><SelectItem value="all">All destinations</SelectItem>{destinations.map((d) => <SelectItem key={d} value={d}>{d}</SelectItem>)}</SelectContent>
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
                <TableHead>Package</TableHead><TableHead>Destination</TableHead><TableHead>Category</TableHead><TableHead>Duration</TableHead>
                <TableHead className="text-right">Adult</TableHead><TableHead className="text-right">Child</TableHead><TableHead className="text-right">GST</TableHead><TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={8} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && <TableRow><TableCell colSpan={8} className="py-12 text-center text-muted-foreground">{activeFilters ? "No packages match these filters." : "No packages yet."}</TableCell></TableRow>}
              {rows.map((p) => (
                <TableRow key={p.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => setDlg({ open: true, id: p.id })} data-testid={`package-row-${p.id}`}>
                  <TableCell><div className="font-medium">{p.name}</div><div className="font-mono text-xs text-muted-foreground">{p.code}</div></TableCell>
                  <TableCell className="text-sm">{p.destination || "—"}</TableCell>
                  <TableCell className="text-sm">{categoryLabel(p.category)}</TableCell>
                  <TableCell className="text-sm">{p.days ? `${p.nights}N / ${p.days}D` : "—"}</TableCell>
                  <TableCell className="text-right font-semibold">{INR(p.adult_price)}</TableCell>
                  <TableCell className="text-right">{p.child_price ? INR(p.child_price) : "—"}</TableCell>
                  <TableCell className="text-right text-sm text-muted-foreground">{p.gst_rate}%</TableCell>
                  <TableCell><span className={cn("rounded border px-2 py-0.5 text-xs", p.active ? "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300" : "border-border text-muted-foreground")}>{p.active ? "Active" : "Inactive"}</span></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
      {error && <div className="text-sm text-destructive">{error}</div>}

      <PackageDialog open={dlg.open} onOpenChange={(o) => setDlg((d) => ({ ...d, open: o }))} packageId={dlg.id} readOnly={!canManage} destinations={destinations} onSaved={() => setTick((t) => t + 1)} />
    </div>
  );
}

export default function PackagesPage() {
  return <Suspense fallback={null}><PackageList /></Suspense>;
}
