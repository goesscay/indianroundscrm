"use client";

import { useRouter } from "next/navigation";
import { Suspense, useEffect, useMemo, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { fetchAll, usePagedList } from "@/lib/usePagedList";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Combobox } from "@/components/ui/combobox";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import StatusPill, { LEAD_STATUSES } from "@/components/app/StatusPill";
import { Search, Download, Phone, MessageCircle, X, ListPlus } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import BulkLeadsDialog from "@/components/app/BulkLeadsDialog";
import { logContact } from "@/components/app/ContactTimeline";
import type { Lead } from "@/types";

interface FilterOptions {
  sources: string[];
  destinations: string[];
  owners: { id: string; name: string }[];
}

const INR = (n: number | undefined | null) => "₹" + (Number(n) || 0).toLocaleString("en-IN");

function LeadList() {
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [status, setStatus] = useState("all");
  const [source, setSource] = useState("all");
  const [destination, setDestination] = useState("all");
  const [owner, setOwner] = useState("all");
  const [options, setOptions] = useState<FilterOptions>({ sources: [], destinations: [], owners: [] });
  const [bulkOpen, setBulkOpen] = useState(false);
  const [refreshTick, setRefreshTick] = useState(0);
  const [exporting, setExporting] = useState(false);
  const [exportErr, setExportErr] = useState("");
  const router = useRouter();
  const { can } = useCurrentUser();

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (status !== "all") p.status = status;
    if (source !== "all") p.lead_source = source;
    if (destination !== "all") p.destination = destination;
    if (owner !== "all") p.staff_id = owner;
    return p;
  }, [applied, status, source, destination, owner]);
  const { rows: leads, loading, loaded, error, paginationProps } = usePagedList<Lead>("/leads", params, refreshTick);
  const err = error || exportErr;

  // Filter options (sources / destinations / owners) come from the leads this user can see.
  useEffect(() => {
    api.get<FilterOptions>("/leads/filters").then(r => setOptions(r.data)).catch(() => { /* filters stay empty */ });
  }, []);

  // Live search: apply shortly after typing stops (Enter applies immediately).
  useEffect(() => {
    const t = setTimeout(() => setApplied(q), 300);
    return () => clearTimeout(t);
  }, [q]);

  const activeFilters = (q.trim() ? 1 : 0) + [status, source, destination, owner].filter(v => v !== "all").length;
  const clearFilters = () => { setQ(""); setApplied(""); setStatus("all"); setSource("all"); setDestination("all"); setOwner("all"); };

  // Export ALL rows matching the current filters (legacy un-paged endpoint), not just this page.
  const exportCsv = async () => {
    setExporting(true); setExportErr("");
    let all: Lead[] = [];
    try { all = await fetchAll<Lead>("/leads", params); }
    catch (e) { setExportErr(formatApiError(e)); setExporting(false); return; }
    const headers = ["Lead ID", "Customer", "Phone", "Destination", "Travel Date", "Status", "Booking", "Supplier ₹", "Selling ₹", "Profit"];
    const rows = all.map(l => [l.lead_id, l.customer_name, l.phone, l.destination, l.travel_date, l.lead_status, l.booking_status, l.supplier_quote_amount || 0, l.selling_amount || 0, l.profit || 0]);
    const csv = [headers, ...rows].map(r => r.map(v => `"${(v ?? "").toString().replace(/"/g, '""')}"`).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const a = document.createElement("a"); a.href = URL.createObjectURL(blob);
    a.download = `leads-${Date.now()}.csv`; a.click();
    setExporting(false);
  };

  return (
    <div className="space-y-4" data-testid="leads-page">
      <div className="flex items-end justify-between flex-wrap gap-4">
        <div>
          <div className="label-caps">Pipeline</div>
          <h1 className="font-display text-2xl sm:text-3xl font-black tracking-tight mt-0.5">
            {can("leads.view_all") ? "All Leads" : "My Leads"}
          </h1>
        </div>
        <div className="flex gap-2">
          {can("leads.create") && (
            <Button variant="outline" onClick={() => setBulkOpen(true)} data-testid="bulk-add-btn">
              <ListPlus size={16} /> Bulk add
            </Button>
          )}
          {can("leads.export") && (
            <Button variant="outline" onClick={exportCsv} disabled={exporting} data-testid="export-csv">
              <Download size={16} /> Export CSV
            </Button>
          )}
          {can("leads.create") && (
            <Button onClick={() => router.push("/leads/new")} data-testid="new-lead-btn">+ New Lead</Button>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-3 items-center" data-testid="leads-filters">
        <div className="relative flex-1 min-w-[220px] max-w-[340px]">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            onKeyDown={(e) => e.key === "Enter" && setApplied(q)}
            placeholder="Search lead ID, customer, phone…"
            className="pl-9 h-9"
            data-testid="search-input"
          />
        </div>
        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="w-[170px] h-9" data-testid="filter-status">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            {LEAD_STATUSES.map(s => <SelectItem key={s} value={s}>{s}</SelectItem>)}
          </SelectContent>
        </Select>
        <Combobox className="w-[160px]" value={source} onChange={setSource} options={options.sources.map(v => ({ value: v }))} placeholder="All sources" clearLabel="All sources" clearValue="all" searchPlaceholder="Search…" testId="filter-source" />
        <Combobox className="w-[180px]" value={destination} onChange={setDestination} options={options.destinations.map(v => ({ value: v }))} placeholder="All destinations" clearLabel="All destinations" clearValue="all" searchPlaceholder="Search…" testId="filter-destination" />
        {can("leads.view_all") && (
          <Combobox className="w-[170px]" value={owner} onChange={setOwner} options={options.owners.map(o => ({ value: o.id, label: o.name }))} placeholder="Everyone's" clearLabel="Everyone's" clearValue="all" searchPlaceholder="Search consultant…" testId="filter-owner" />
        )}
        {activeFilters > 0 && (
          <Button variant="ghost" size="sm" onClick={clearFilters} data-testid="clear-filters">
            <X size={14} /> Clear filters
          </Button>
        )}
      </div>

      <Card className="rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Lead ID</TableHead>
                <TableHead>Customer</TableHead>
                <TableHead>Phone</TableHead>
                <TableHead>Destination</TableHead>
                <TableHead>Travel</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Selling ₹</TableHead>
                <TableHead className="text-right">Profit</TableHead>
                <TableHead>Staff</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={9} className="text-center text-muted-foreground py-8">Loading…</TableCell></TableRow>}
              {loaded && leads.length === 0 && (
                <TableRow><TableCell colSpan={9} className="text-center text-muted-foreground py-12">No leads yet. Create one to get started.</TableCell></TableRow>
              )}
              {leads.map((l) => {
                const digits = (l.phone || "").replace(/\D/g, "");
                const tenDigit = digits.slice(-10);
                const waPhone = tenDigit.length === 10 ? `91${tenDigit}` : digits;
                return (
                <TableRow key={l.lead_id} className="cursor-pointer hover:bg-secondary/50"
                          onClick={() => router.push(`/leads/${l.lead_id}`)}
                          data-testid={`lead-row-${l.lead_id}`}>
                  <TableCell className="font-mono font-semibold">{l.lead_id}</TableCell>
                  <TableCell className="font-medium">{l.customer_name}</TableCell>
                  <TableCell className="text-muted-foreground" onClick={(e) => e.stopPropagation()}>
                    <div className="flex items-center gap-1.5">
                      <span className="text-xs">{l.phone}</span>
                      {tenDigit.length === 10 && (
                        <>
                          <a href={`tel:+91${tenDigit}`} title="Call"
                             onClick={(e) => { e.stopPropagation(); logContact(l.lead_id, "call", l.phone); }}
                             className="p-1 rounded hover:bg-[#0A0A0A]/10 dark:hover:bg-white/10 text-[#0A0A0A] dark:text-foreground"
                             data-testid={`row-call-${l.lead_id}`}>
                            <Phone size={13} />
                          </a>
                          <a href={`https://wa.me/${waPhone}`} target="_blank" rel="noreferrer" title="WhatsApp"
                             onClick={(e) => { e.stopPropagation(); logContact(l.lead_id, "whatsapp", l.phone); }}
                             className="p-1 rounded hover:bg-emerald-100 dark:hover:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400"
                             data-testid={`row-wa-${l.lead_id}`}>
                            <MessageCircle size={13} />
                          </a>
                        </>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>{l.destination}</TableCell>
                  <TableCell className="text-muted-foreground text-xs">{(l.travel_date || "").slice(0, 10)}</TableCell>
                  <TableCell><StatusPill status={l.lead_status} /></TableCell>
                  <TableCell className="text-right font-semibold">{INR(l.selling_amount)}</TableCell>
                  <TableCell className={`text-right ${(l.profit || 0) >= 0 ? "text-[#0F766E] dark:text-emerald-400" : "text-[#B91C1C] dark:text-red-400"}`}>
                    {INR(l.profit)}
                  </TableCell>
                  <TableCell className="text-xs text-muted-foreground">{l.staff_name}</TableCell>
                </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
      {err && <div className="text-sm text-destructive">{err}</div>}
      {can("leads.create") && <BulkLeadsDialog open={bulkOpen} onOpenChange={setBulkOpen} onDone={() => setRefreshTick((t) => t + 1)} />}
    </div>
  );
}

export default function LeadsPage() {
  return <Suspense fallback={null}><LeadList /></Suspense>;
}
