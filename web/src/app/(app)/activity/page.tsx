"use client";

import { Suspense, useEffect, useState, useMemo } from "react";
import { api, formatApiError } from "@/lib/api";
import { fetchAll, usePagedList } from "@/lib/usePagedList";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { toast } from "sonner";
import { Download, Filter, X } from "lucide-react";
import type { ActivityEntry, ActivityFacets } from "@/types";

const ACTION_COLOR: Record<string, string> = {
  create_lead: "bg-emerald-100 text-emerald-800 dark:text-emerald-300 dark:bg-emerald-950/60 dark:text-emerald-300",
  update_lead: "bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300",
  reassign_lead: "bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300",
  delete_lead: "bg-red-100 text-red-800 dark:text-red-300 dark:bg-red-950/60 dark:text-red-300",
  create_user: "bg-emerald-100 text-emerald-800 dark:text-emerald-300 dark:bg-emerald-950/60 dark:text-emerald-300",
  update_user: "bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300",
  delete_user: "bg-red-100 text-red-800 dark:text-red-300 dark:bg-red-950/60 dark:text-red-300",
  send_supplier_mail: "bg-cyan-100 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300",
  update_supplier_response: "bg-teal-100 text-teal-800 dark:bg-teal-950/60 dark:text-teal-300",
  set_allocation: "bg-indigo-100 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300",
  add_master: "bg-slate-100 text-slate-800 dark:bg-neutral-800 dark:text-neutral-300",
};

function ActivityLog() {
  const [facets, setFacets] = useState<ActivityFacets & { users: string[] }>({ actions: [], entities: [], users: [] });
  const [f, setF] = useState({ user_email: "all", action: "all", entity: "all", date_from: "", date_to: "", q: "" });
  const [appliedQ, setAppliedQ] = useState("");

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    Object.entries({ ...f, q: appliedQ }).forEach(([k, v]) => { if (v && v !== "all") p[k] = v; });
    return p;
  }, [f.user_email, f.action, f.entity, f.date_from, f.date_to, appliedQ]); // eslint-disable-line react-hooks/exhaustive-deps
  const { rows, total, loading, loaded, error, paginationProps } = usePagedList<ActivityEntry>("/activity", params);
  useEffect(() => { if (error) toast.error(error); }, [error]);
  const load = () => setAppliedQ(f.q);

  useEffect(() => {
    (async () => {
      try { const { data } = await api.get<ActivityFacets>("/activity/facets"); setFacets({ ...data, users: data.users ?? [] }); }
      catch { /* ignore */ }
    })();
  }, []);

  const clearFilters = () => { setF({ user_email: "all", action: "all", entity: "all", date_from: "", date_to: "", q: "" }); setAppliedQ(""); };

  const exportCsv = async () => {
    let all: ActivityEntry[] = [];
    try { all = await fetchAll<ActivityEntry>("/activity", params); }
    catch (e) { toast.error(formatApiError(e)); return; }
    const headers = ["Timestamp", "User", "Action", "Entity", "Entity ID", "Details"];
    const data = all.map(r => [
      (r.timestamp || "").slice(0, 19).replace("T", " "),
      r.user_name || r.user_email || "",
      r.action || "",
      r.entity || "",
      r.entity_id || "",
      r.new_value ? JSON.stringify(r.new_value) : "",
    ]);
    const csv = [headers, ...data].map(row => row.map(v => `"${String(v ?? "").replace(/"/g, '""')}"`).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = `activity-log-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    toast.success(`Exported ${all.length} rows`);
  };

  const activeFilters = useMemo(() => {
    return Object.entries(f).filter(([, v]) => v && v !== "all").length;
  }, [f]);

  return (
    <div className="space-y-6" data-testid="activity-page">
      <div className="flex items-end justify-between flex-wrap gap-4">
        <div>
          <div className="label-caps">Audit trail</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Activity Log</h1>
          <p className="text-sm text-muted-foreground mt-2">{total.toLocaleString("en-IN")} record{total !== 1 ? "s" : ""} · every action across the CRM is logged</p>
        </div>
        <div className="flex gap-2">
          {activeFilters > 0 && (
            <Button variant="outline" onClick={clearFilters} data-testid="clear-filters">
              <X size={14} /> Clear filters ({activeFilters})
            </Button>
          )}
          <Button variant="outline" onClick={exportCsv} disabled={total === 0} data-testid="export-activity-csv">
            <Download size={14} /> Export CSV
          </Button>
        </div>
      </div>

      <Card className="p-4 rounded-lg" data-testid="activity-filters">
        <div className="flex items-center gap-2 mb-3">
          <Filter size={14} className="text-muted-foreground" />
          <span className="label-caps">Filters</span>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-6 gap-3">
          <div>
            <Label className="label-caps">User</Label>
            <Select value={f.user_email} onValueChange={(v) => setF({ ...f, user_email: v })}>
              <SelectTrigger className="mt-1.5 h-9" data-testid="fil-user"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All users</SelectItem>
                {facets.users.map(u => <SelectItem key={u} value={u}>{u}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label className="label-caps">Action</Label>
            <Select value={f.action} onValueChange={(v) => setF({ ...f, action: v })}>
              <SelectTrigger className="mt-1.5 h-9" data-testid="fil-action"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All actions</SelectItem>
                {facets.actions.map(a => <SelectItem key={a} value={a}>{a}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label className="label-caps">Entity</Label>
            <Select value={f.entity} onValueChange={(v) => setF({ ...f, entity: v })}>
              <SelectTrigger className="mt-1.5 h-9" data-testid="fil-entity"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All entities</SelectItem>
                {facets.entities.map(e => <SelectItem key={e} value={e}>{e}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label className="label-caps">From</Label>
            <Input type="date" className="mt-1.5 h-9" value={f.date_from} onChange={(e) => setF({ ...f, date_from: e.target.value })} data-testid="fil-from" />
          </div>
          <div>
            <Label className="label-caps">To</Label>
            <Input type="date" className="mt-1.5 h-9" value={f.date_to} onChange={(e) => setF({ ...f, date_to: e.target.value })} data-testid="fil-to" />
          </div>
          <div>
            <Label className="label-caps">Search</Label>
            <div className="flex gap-1 mt-1.5">
              <Input placeholder="lead ID / user name" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })}
                onKeyDown={(e) => e.key === "Enter" && load()} className="h-9" data-testid="fil-q" />
              <Button onClick={load} size="sm" className="h-9" data-testid="fil-search">Go</Button>
            </div>
          </div>
        </div>
      </Card>

      <Card className="rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Timestamp</TableHead>
                <TableHead>User</TableHead>
                <TableHead>Action</TableHead>
                <TableHead>Entity</TableHead>
                <TableHead>Details</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground py-8">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && (
                <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground py-12">
                  No activity matches these filters.
                </TableCell></TableRow>
              )}
              {rows.map(r => (
                <TableRow key={r.id} data-testid={`activity-row-${r.id}`}>
                  <TableCell className="font-mono text-xs whitespace-nowrap">{(r.timestamp || "").slice(0, 19).replace("T", " ")}</TableCell>
                  <TableCell className="font-medium">{r.user_name || r.user_email}</TableCell>
                  <TableCell>
                    <span className={`label-caps px-2 py-1 rounded ${ACTION_COLOR[r.action] || "bg-secondary"}`}>
                      {r.action}
                    </span>
                  </TableCell>
                  <TableCell>{r.entity} {r.entity_id && <span className="text-xs text-muted-foreground font-mono">({r.entity_id})</span>}</TableCell>
                  <TableCell className="text-xs text-muted-foreground max-w-md truncate" title={r.new_value ? JSON.stringify(r.new_value) : "—"}>
                    {r.new_value ? JSON.stringify(r.new_value).slice(0, 120) : "—"}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
    </div>
  );
}

export default function ActivityPage() {
  return <Suspense fallback={null}><ActivityLog /></Suspense>;
}
