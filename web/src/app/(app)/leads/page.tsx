"use client";

import Link from "next/link";
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
  const [dateField, setDateField] = useState<"created" | "travel">("created");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [options, setOptions] = useState<FilterOptions>({ sources: [], destinations: [], owners: [] });
  const [bulkOpen, setBulkOpen] = useState(false);
  const [refreshTick, setRefreshTick] = useState(0);
  const [exporting, setExporting] = useState(false);
  const [exportErr, setExportErr] = useState("");
  const router = useRouter();

  /** A plain click opens the lead here; Ctrl/Cmd/Shift-click and clicks on links or buttons are left to the browser. */
  const openRow = (e: React.MouseEvent, leadId: string) => {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
    if ((e.target as HTMLElement).closest("a,button")) return;
    router.push(`/leads/${leadId}`);
  };
  const { can } = useCurrentUser();
  const pricing = can("leads.edit", "suppliers.view", "rsq.manage"); // supplier quote, selling price and profit are not shown to sales

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (status !== "all") p.status = status;
    if (source !== "all") p.lead_source = source;
    if (destination !== "all") p.destination = destination;
    if (owner !== "all") p.staff_id = owner;
    if (dateFrom || dateTo) {
      p.date_field = dateField;
      if (dateFrom) p.date_from = dateFrom;
      if (dateTo) p.date_to = dateTo;
    }
    return p;
  }, [applied, status, source, destination, owner, dateField, dateFrom, dateTo]);
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

  const activeFilters = (q.trim() ? 1 : 0) + [status, source, destination, owner].filter(v => v !== "all").length + (dateFrom || dateTo ? 1 : 0);
  const clearFilters = () => { setQ(""); setApplied(""); setStatus("all"); setSource("all"); setDestination("all"); setOwner("all"); setDateFrom(""); setDateTo(""); setDateField("created"); };

  // quick ranges for the created date (calendar days as the team sees them)
  const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const preset = (kind: "today" | "week" | "month") => {
    const now = new Date();
    const from = new Date(now);
    if (kind === "week") from.setDate(now.getDate() - 6);
    if (kind === "month") from.setDate(1);
    setDateField("created"); setDateFrom(iso(from)); setDateTo(iso(now));
  };

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
            <Button asChild><Link href="/leads/new" data-testid="new-lead-btn">+ New Lead</Link></Button>
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
        <div className="flex flex-wrap items-center gap-2" data-testid="filter-dates">
          <Select value={dateField} onValueChange={(v) => setDateField(v as "created" | "travel")}>
            <SelectTrigger className="w-[130px] h-9" aria-label="Date to filter by" data-testid="filter-date-field"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="created">Created date</SelectItem>
              <SelectItem value="travel">Travel date</SelectItem>
            </SelectContent>
          </Select>
          <Input type="date" className="h-9 w-[150px]" value={dateFrom} max={dateTo || undefined} onChange={(e) => setDateFrom(e.target.value)} aria-label="From date" title="From" data-testid="filter-date-from" />
          <span className="text-xs text-muted-foreground">to</span>
          <Input type="date" className="h-9 w-[150px]" value={dateTo} min={dateFrom || undefined} onChange={(e) => setDateTo(e.target.value)} aria-label="To date" title="To" data-testid="filter-date-to" />
          {dateField === "created" && (
            <>
              <Button variant="outline" size="sm" onClick={() => preset("today")} data-testid="date-today">Today</Button>
              <Button variant="outline" size="sm" onClick={() => preset("week")} data-testid="date-week">Last 7 days</Button>
              <Button variant="outline" size="sm" onClick={() => preset("month")} data-testid="date-month">This month</Button>
            </>
          )}
        </div>
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
                <TableHead>Created</TableHead>
                <TableHead>Travel</TableHead>
                <TableHead>Status</TableHead>
                {pricing && <TableHead className="text-right">Selling ₹</TableHead>}
                {pricing && <TableHead className="text-right">Profit</TableHead>}
                <TableHead>Staff</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={10} className="text-center text-muted-foreground py-8">Loading…</TableCell></TableRow>}
              {loaded && leads.length === 0 && (
                <TableRow><TableCell colSpan={10} className="text-center text-muted-foreground py-12">No leads yet. Create one to get started.</TableCell></TableRow>
              )}
              {leads.map((l) => {
                const digits = (l.phone || "").replace(/\D/g, "");
                const tenDigit = digits.slice(-10);
                const waPhone = tenDigit.length === 10 ? `91${tenDigit}` : digits;
                return (
                <TableRow key={l.lead_id} className="cursor-pointer hover:bg-secondary/50"
                          onClick={(e) => openRow(e, l.lead_id)}
                          onAuxClick={(e) => { if (e.button === 1 && !(e.target as HTMLElement).closest("a,button")) window.open(`/leads/${l.lead_id}`, "_blank"); }}
                          data-testid={`lead-row-${l.lead_id}`}>
                  <TableCell className="font-mono font-semibold"><Link href={`/leads/${l.lead_id}`} className="hover:underline" data-testid={`lead-link-${l.lead_id}`}>{l.lead_id}</Link></TableCell>
                  <TableCell className="font-medium"><Link href={`/leads/${l.lead_id}`} className="hover:underline">{l.customer_name}</Link></TableCell>
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
                  <TableCell className="text-muted-foreground text-xs whitespace-nowrap" title={l.created_at ? new Date(l.created_at).toLocaleString("en-IN", { timeZone: "Asia/Kolkata" }) : ""} data-testid={`lead-created-${l.lead_id}`}>
                    {l.created_at ? new Date(l.created_at).toLocaleDateString("en-IN", { timeZone: "Asia/Kolkata", day: "numeric", month: "short", year: "numeric" }) : ""}
                  </TableCell>
                  <TableCell className="text-muted-foreground text-xs">{(l.travel_date || "").slice(0, 10)}</TableCell>
                  <TableCell><StatusPill status={l.lead_status} /></TableCell>
                  {pricing && <TableCell className="text-right font-semibold">{INR(l.selling_amount)}</TableCell>}
                  {pricing && <TableCell className={`text-right ${(l.profit || 0) >= 0 ? "text-[#0F766E] dark:text-emerald-400" : "text-[#B91C1C] dark:text-red-400"}`}>
                    {INR(l.profit)}
                  </TableCell>}
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
