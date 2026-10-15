"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { fetchAll, usePagedList } from "@/lib/usePagedList";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Combobox } from "@/components/ui/combobox";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import StatusPill from "@/components/app/StatusPill";
import LeadDateFilter, { NO_DATES, type DateFilter } from "@/components/app/LeadDateFilter";
import { STAGES, stageByKey } from "@/lib/leadStages";
import { Search, Download, Phone, MessageCircle, X, ListPlus } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import BulkLeadsDialog from "@/components/app/BulkLeadsDialog";
import { logContact } from "@/components/app/ContactTimeline";
import { cn } from "@/lib/utils";
import type { Lead } from "@/types";

interface FilterOptions {
  sources: string[];
  destinations: string[];
  owners: { id: string; name: string }[];
}

interface StageCounts { stages: Record<string, number>; statuses: Record<string, number> }

/** The budget is typed by hand ("80,000 – 1,20,000" or "₹6 L"): add the rupee sign only when it starts with a digit. */
const budgetText = (b: string) => { const t = b.trim(); return /^\d/.test(t) ? `₹ ${t}` : t; };

/** "3N / 4D · 2 adults, 1 child": what the trip looks like, from what the lead already holds. */
function tripLine(l: Lead): string {
  const adults = Number(l.adults) || 0;
  const kids = Number(l.children) || 0;
  const guests = [adults ? `${adults} adult${adults === 1 ? "" : "s"}` : "", kids ? `${kids} child${kids === 1 ? "" : "ren"}` : ""].filter(Boolean).join(", ");
  return [l.duration_label, guests].filter(Boolean).join(" · ");
}

const TABS = [{ key: "all", label: "All", statuses: [] as string[] }, ...STAGES];

function LeadList() {
  const router = useRouter();
  const sp = useSearchParams();
  const stageParam = sp.get("stage") || "all";
  const stage = TABS.some((t) => t.key === stageParam) ? stageParam : "all";

  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [status, setStatus] = useState("all");     // a precise status inside the open stage
  const [source, setSource] = useState("all");
  const { user: me } = useCurrentUser();
  const [destination, setDestination] = useState(me.default_destination?.trim() || "all");   // their own destination, until they change it
  const [owner, setOwner] = useState("all");
  const [dates, setDates] = useState<DateFilter>(NO_DATES);
  const [options, setOptions] = useState<FilterOptions>({ sources: [], destinations: [], owners: [] });
  const [counts, setCounts] = useState<StageCounts>({ stages: {}, statuses: {} });
  const [bulkOpen, setBulkOpen] = useState(false);
  const [refreshTick, setRefreshTick] = useState(0);
  const [exporting, setExporting] = useState(false);
  const [exportErr, setExportErr] = useState("");
  const { can } = useCurrentUser();
  const canMove = can("leads.edit", "leads.edit_limited");

  const setStage = useCallback((key: string) => {
    const next = new URLSearchParams(window.location.search);
    if (key === "all") next.delete("stage"); else next.set("stage", key);
    next.delete("page");
    const qs = next.toString();
    router.replace(qs ? `/leads?${qs}` : "/leads", { scroll: false });
    setStatus("all");
  }, [router]);

  /** Everything except the stage: what the tab counts are measured against. */
  const baseParams = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (source !== "all") p.lead_source = source;
    if (destination !== "all") p.destination = destination;
    if (owner !== "all") p.staff_id = owner;
    if (dates.from || dates.to) {
      p.date_field = dates.field;
      if (dates.from) p.date_from = dates.from;
      if (dates.to) p.date_to = dates.to;
    }
    return p;
  }, [applied, source, destination, owner, dates]);

  const params = useMemo(() => {
    const p = { ...baseParams };
    if (stage !== "all") p.stage = stage;
    if (status !== "all" && stageByKey(stage)?.statuses.includes(status)) p.status = status;   // a status only counts inside its own stage
    return p;
  }, [baseParams, stage, status]);

  const { rows: leads, loading, loaded, error, paginationProps } = usePagedList<Lead>("/leads", params, refreshTick);
  const err = error || exportErr;

  // Filter options (sources / destinations / owners) come from the leads this user can see.
  useEffect(() => {
    api.get<FilterOptions>("/leads/filters").then(r => setOptions(r.data)).catch(() => { /* filters stay empty */ });
  }, []);

  // Tab counts follow the search and filters, so a tab never promises leads the filters would hide.
  useEffect(() => {
    let live = true;
    api.get<StageCounts>("/leads/stage-counts", { params: baseParams }).then((r) => { if (live) setCounts(r.data); }).catch(() => { /* counts stay as they were */ });
    return () => { live = false; };
  }, [baseParams, refreshTick]);

  // Live search: apply shortly after typing stops (Enter applies immediately).
  useEffect(() => {
    const t = setTimeout(() => setApplied(q), 300);
    return () => clearTimeout(t);
  }, [q]);

  const datesOn = !!(dates.from || dates.to);
  const activeFilters = (q.trim() ? 1 : 0) + [source, destination, owner, status].filter(v => v !== "all").length + (datesOn ? 1 : 0);
  const clearFilters = () => { setQ(""); setApplied(""); setStatus("all"); setSource("all"); setDestination("all"); setOwner("all"); setDates(NO_DATES); };

  const openStage = stageByKey(stage);

  /** A plain click opens the lead here; Ctrl/Cmd/Shift-click, clicks on links or buttons, and clicks inside menus are left alone. */
  const openRow = (e: React.MouseEvent<HTMLElement>, leadId: string) => {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
    if (!e.currentTarget.contains(e.target as Node)) return;         // a menu rendered outside the row
    if ((e.target as HTMLElement).closest("a,button")) return;
    router.push(`/leads/${leadId}`);
  };

  const moveLead = async (leadId: string, to: string) => {
    try {
      await api.patch(`/leads/${leadId}`, { lead_status: to });
      toast.success(`${leadId} moved to ${to}`);
      setRefreshTick((t) => t + 1);
    } catch (e) { toast.error(formatApiError(e)); }
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
    <div className="space-y-3" data-testid="leads-page">
      <div className="flex items-end justify-between flex-wrap gap-3">
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

      {/* stages */}
      <div className="flex gap-1 overflow-x-auto border-b border-border" role="tablist" aria-label="Lead stage" data-testid="stage-tabs">
        {TABS.map((t) => {
          const active = t.key === stage;
          const n = counts.stages[t.key];
          return (
            <button
              key={t.key} type="button" role="tab" aria-selected={active} onClick={() => setStage(t.key)}
              className={cn(
                "-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:bg-accent",
                active ? "border-primary text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"
              )}
              data-testid={`stage-tab-${t.key}`}
            >
              {t.label}
              <span
                className={cn("min-w-[20px] rounded-full px-1.5 py-px text-center text-[11px] font-semibold tabular-nums", active ? "bg-primary text-primary-foreground" : "bg-secondary text-muted-foreground")}
                data-testid={`stage-count-${t.key}`}
              >{n ?? "–"}</span>
            </button>
          );
        })}
      </div>

      <div className="flex flex-wrap gap-2 items-center" data-testid="leads-filters">
        <div className="relative flex-1 min-w-[220px] max-w-[320px]">
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
        {openStage && openStage.statuses.length > 1 && (
          <Select value={status} onValueChange={setStatus}>
            <SelectTrigger className="w-[180px] h-9" data-testid="filter-status">
              <SelectValue placeholder={`All ${openStage.label.toLowerCase()}`} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All in {openStage.label}</SelectItem>
              {openStage.statuses.map(s => <SelectItem key={s} value={s}>{s}{counts.statuses[s] != null ? ` (${counts.statuses[s]})` : ""}</SelectItem>)}
            </SelectContent>
          </Select>
        )}
        <Combobox className="w-[150px]" value={source} onChange={setSource} options={options.sources.map(v => ({ value: v }))} placeholder="All sources" clearLabel="All sources" clearValue="all" searchPlaceholder="Search…" testId="filter-source" />
        <Combobox className="w-[170px]" value={destination} onChange={setDestination} options={options.destinations.map(v => ({ value: v }))} placeholder="All destinations" clearLabel="All destinations" clearValue="all" searchPlaceholder="Search…" testId="filter-destination" />
        {can("leads.view_all") && (
          <Combobox className="w-[160px]" value={owner} onChange={setOwner} options={options.owners.map(o => ({ value: o.id, label: o.name }))} placeholder="Everyone's" clearLabel="Everyone's" clearValue="all" searchPlaceholder="Search consultant…" testId="filter-owner" />
        )}
        <LeadDateFilter value={dates} onApply={setDates} />
        {activeFilters > 0 && (
          <Button variant="ghost" size="sm" onClick={clearFilters} data-testid="clear-filters">
            <X size={14} /> Clear filters
          </Button>
        )}
      </div>

      <Card className="rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <Table className="text-[13px]">
            <TableHeader>
              <TableRow>
                <TableHead className="h-9 px-3 text-xs">Lead</TableHead>
                <TableHead className="h-9 px-3 text-xs">Phone</TableHead>
                <TableHead className="h-9 px-3 text-xs">Destination</TableHead>
                <TableHead className="h-9 px-3 text-xs">Travel</TableHead>
                <TableHead className="h-9 px-3 text-xs">Budget</TableHead>
                <TableHead className="h-9 px-3 text-xs">Created</TableHead>
                <TableHead className="h-9 px-3 text-xs">Stage</TableHead>
                <TableHead className="h-9 px-3 text-xs">Owner</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={8} className="text-center text-muted-foreground py-8">Loading…</TableCell></TableRow>}
              {loaded && leads.length === 0 && (
                <TableRow><TableCell colSpan={8} className="text-center text-muted-foreground py-12" data-testid="leads-empty">
                  {activeFilters > 0 || stage !== "all" ? "No leads match. Try another stage or clear the filters." : "No leads yet. Create one to get started."}
                </TableCell></TableRow>
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
                  <TableCell className="px-3 py-1.5">
                    <Link href={`/leads/${l.lead_id}`} className="block max-w-[200px] truncate font-medium leading-tight hover:underline" data-testid={`lead-link-${l.lead_id}`}>{l.customer_name}</Link>
                    <span className="font-mono text-[11px] text-muted-foreground">{l.lead_id}</span>
                  </TableCell>
                  <TableCell className="px-3 py-1.5 text-muted-foreground" onClick={(e) => e.stopPropagation()}>
                    <div className="flex items-center gap-1">
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
                  <TableCell className="px-3 py-1.5"><span className="block max-w-[170px] truncate" title={l.destination}>{l.destination}</span></TableCell>
                  <TableCell className="px-3 py-1.5 whitespace-nowrap" data-testid={`lead-travel-${l.lead_id}`}>
                    <div className="text-xs">{(l.travel_date || "").slice(0, 10) || <span className="text-muted-foreground">Not set</span>}</div>
                    {tripLine(l) && <div className="text-[11px] text-muted-foreground">{tripLine(l)}</div>}
                  </TableCell>
                  <TableCell className="px-3 py-1.5 text-xs" data-testid={`lead-budget-${l.lead_id}`}>
                    {l.budget?.trim() ? <span className="block max-w-[130px] truncate" title={budgetText(l.budget)}>{budgetText(l.budget)}</span> : <span className="text-muted-foreground">—</span>}
                  </TableCell>
                  <TableCell className="px-3 py-1.5 text-muted-foreground text-xs whitespace-nowrap" title={l.created_at ? new Date(l.created_at).toLocaleString("en-IN", { timeZone: "Asia/Kolkata" }) : ""} data-testid={`lead-created-${l.lead_id}`}>
                    {l.created_at ? new Date(l.created_at).toLocaleDateString("en-IN", { timeZone: "Asia/Kolkata", day: "numeric", month: "short", year: "numeric" }) : ""}
                    {l.lead_source && <div className="max-w-[110px] truncate text-[11px]" title={`Source: ${l.lead_source}`}>{l.lead_source}</div>}
                  </TableCell>
                  <TableCell className="px-3 py-1.5">
                    {canMove ? (
                      <Select value={l.lead_status} onValueChange={(v) => { if (v !== l.lead_status) void moveLead(l.lead_id, v); }}>
                        <SelectTrigger
                          className="h-auto w-auto gap-1 rounded border-0 bg-transparent p-0 shadow-none focus:ring-1 [&>svg]:h-3 [&>svg]:w-3 [&>svg]:opacity-50"
                          aria-label={`Change stage of ${l.lead_id}`} data-testid={`stage-select-${l.lead_id}`}
                        >
                          <StatusPill status={l.lead_status} />
                        </SelectTrigger>
                        <SelectContent>
                          {STAGES.map((st) => (
                            <SelectGroup key={st.key}>
                              <SelectLabel>{st.label}</SelectLabel>
                              {st.statuses.map((s) => <SelectItem key={s} value={s} data-testid={`stage-opt-${s.toLowerCase().replace(/\s+/g, "-")}`}>{s}</SelectItem>)}
                            </SelectGroup>
                          ))}
                        </SelectContent>
                      </Select>
                    ) : <StatusPill status={l.lead_status} />}
                  </TableCell>
                  <TableCell className="px-3 py-1.5 text-xs text-muted-foreground whitespace-nowrap">{l.staff_name}</TableCell>
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
