"use client";

import { useRouter } from "next/navigation";
import { Suspense, useEffect, useMemo, useState } from "react";
import { Download, Search, X } from "lucide-react";
import { fetchAll, usePagedList } from "@/lib/usePagedList";
import { formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import CustomerDialog from "@/components/crm/CustomerDialog";
import { CUSTOMER_TYPES, INR, customerTypeLabel, fmtDate, useCrmOptions } from "@/components/crm/shared";
import type { Customer } from "@/types";

function CustomerList() {
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [type, setType] = useState("all");
  const [status, setStatus] = useState("all");
  const [owner, setOwner] = useState("all");
  const [open, setOpen] = useState(false);
  const [tick, setTick] = useState(0);
  const [exporting, setExporting] = useState(false);
  const [exportErr, setExportErr] = useState("");
  const router = useRouter();
  const { can } = useCurrentUser();
  const options = useCrmOptions();

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (type !== "all") p.type = type;
    if (status !== "all") p.status = status;
    if (owner !== "all") p.owner = owner;
    return p;
  }, [applied, type, status, owner]);
  const { rows, loading, loaded, error, paginationProps } = usePagedList<Customer>("/customers", params, tick);

  useEffect(() => {
    const t = setTimeout(() => setApplied(q), 300);
    return () => clearTimeout(t);
  }, [q]);

  const active = (q.trim() ? 1 : 0) + [type, status, owner].filter((v) => v !== "all").length;
  const clear = () => { setQ(""); setApplied(""); setType("all"); setStatus("all"); setOwner("all"); };

  const exportCsv = async () => {
    setExporting(true); setExportErr("");
    try {
      const all = await fetchAll<Customer>("/customers", params);
      const headers = ["Customer No", "Name", "Type", "Status", "Company", "Phone", "E-mail", "City", "State", "Deals", "Open pipeline", "Won value", "Owner", "Added"];
      const body = all.map((c) => [c.customer_no, c.name, c.type, c.status, c.company, c.phone, c.email, c.city, c.state, c.deals_count, c.open_pipeline, c.won_value, c.owner?.name, (c.created_at || "").slice(0, 10)]);
      const csv = [headers, ...body].map((r) => r.map((v) => `"${(v ?? "").toString().replace(/"/g, '""')}"`).join(",")).join("\n");
      const a = document.createElement("a");
      a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
      a.download = `customers-${Date.now()}.csv`; a.click();
    } catch (e) { setExportErr(formatApiError(e)); }
    finally { setExporting(false); }
  };

  return (
    <div className="space-y-6" data-testid="customers-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="label-caps">Sales</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Customers</h1>
          <p className="mt-1 text-sm text-muted-foreground">The people and companies you sell trips to, with everything about them in one place.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={exportCsv} disabled={exporting} data-testid="export-customers"><Download size={16} /> Export CSV</Button>
          {can("customers.create") && <Button onClick={() => setOpen(true)} data-testid="new-customer-btn">+ New customer</Button>}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3" data-testid="customers-filters">
        <div className="relative min-w-[220px] max-w-[340px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === "Enter" && setApplied(q)}
                 placeholder="Search name, phone, e-mail, number…" className="h-10 pl-9" data-testid="customer-search" />
        </div>
        <Select value={type} onValueChange={setType}>
          <SelectTrigger className="h-10 w-[150px]" data-testid="filter-customer-type"><SelectValue placeholder="All types" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All types</SelectItem>
            {CUSTOMER_TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
          </SelectContent>
        </Select>
        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="h-10 w-[150px]" data-testid="filter-customer-status"><SelectValue placeholder="All statuses" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
          </SelectContent>
        </Select>
        {can("customers.view_all") && (
          <Select value={owner} onValueChange={setOwner}>
            <SelectTrigger className="h-10 w-[170px]" data-testid="filter-customer-owner"><SelectValue placeholder="Everyone's" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Everyone&apos;s</SelectItem>
              <SelectItem value="unassigned">Unassigned</SelectItem>
              {(options?.owners ?? []).map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}
            </SelectContent>
          </Select>
        )}
        {active > 0 && <Button variant="ghost" size="sm" onClick={clear}><X size={14} /> Clear filters</Button>}
      </div>

      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Customer</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Phone</TableHead>
                <TableHead>City</TableHead>
                <TableHead className="text-right">Deals</TableHead>
                <TableHead className="text-right">Open pipeline</TableHead>
                <TableHead className="text-right">Won</TableHead>
                <TableHead>Owner</TableHead>
                <TableHead>Added</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={9} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && (
                <TableRow><TableCell colSpan={9} className="py-12 text-center text-muted-foreground">
                  {active > 0 ? "No customers match these filters." : "No customers yet. Convert a lead or add a customer to get started."}
                </TableCell></TableRow>
              )}
              {rows.map((c) => (
                <TableRow key={c.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => router.push(`/customers/${c.id}`)} data-testid={`customer-row-${c.id}`}>
                  <TableCell>
                    <div className="font-medium">{c.name}</div>
                    <div className="font-mono text-xs text-muted-foreground">{c.customer_no}{c.company ? ` · ${c.company}` : ""}{c.status === "inactive" ? " · inactive" : ""}</div>
                  </TableCell>
                  <TableCell className="text-sm">{customerTypeLabel(c.type)}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{c.phone || "—"}</TableCell>
                  <TableCell className="text-sm">{c.city || "—"}</TableCell>
                  <TableCell className="text-right">{c.deals_count}</TableCell>
                  <TableCell className="text-right">{c.open_pipeline ? INR(c.open_pipeline) : "—"}</TableCell>
                  <TableCell className="text-right font-semibold">{c.won_value ? INR(c.won_value) : "—"}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{c.owner?.name ?? "Unassigned"}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{fmtDate(c.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
      {(error || exportErr) && <div className="text-sm text-destructive">{error || exportErr}</div>}

      <CustomerDialog open={open} onOpenChange={setOpen} onSaved={(c) => { setTick((t) => t + 1); router.push(`/customers/${c.id}`); }} />
    </div>
  );
}

export default function CustomersPage() {
  return <Suspense fallback={null}><CustomerList /></Suspense>;
}
