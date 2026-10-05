"use client";

import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { toast } from "sonner";
import type { SecurityLogEntry } from "@/types";
import { Shield, Search, RefreshCw, Download } from "lucide-react";

const EVENT_STYLES: Record<string, string> = {
  login_success: "bg-emerald-100 text-emerald-800",
  login_google: "bg-emerald-100 text-emerald-800",
  login_failed: "bg-amber-100 text-amber-800",
  login_locked: "bg-red-100 text-red-800",
  login_blocked_locked: "bg-red-100 text-red-800",
  logout: "bg-gray-100 text-gray-800",
  password_changed: "bg-blue-100 text-blue-800",
  password_reset_requested: "bg-blue-100 text-blue-800",
  password_reset_completed: "bg-blue-100 text-blue-800",
  admin_force_reset: "bg-purple-100 text-purple-800",
  admin_status_change: "bg-purple-100 text-purple-800",
  admin_session_terminated: "bg-purple-100 text-purple-800",
  admin_unlock: "bg-purple-100 text-purple-800",
  session_revoked: "bg-gray-100 text-gray-800",
};

const EVENT_OPTIONS = [
  "", "login_success", "login_failed", "login_locked", "logout",
  "password_changed", "password_reset_completed", "password_reset_requested",
  "admin_force_reset", "admin_status_change", "admin_session_terminated",
  "admin_unlock", "session_revoked",
];

const fmt = (iso?: string | null) => {
  if (!iso) return "—";
  try { return new Date(iso).toLocaleString("en-IN", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }); }
  catch { return iso.slice(0, 16); }
};

export default function SecurityLog() {
  const [rows, setRows] = useState<SecurityLogEntry[]>([]);
  const [event, setEvent] = useState("");
  const [userEmail, setUserEmail] = useState("");
  const [busy, setBusy] = useState(false);

  const load = async () => {
    setBusy(true);
    try {
      const params: Record<string, string> = {};
      if (event) params.event = event;
      if (userEmail.trim()) params.user_email = userEmail.trim();
      const { data } = await api.get<SecurityLogEntry[]>("/security-audit-log", { params });
      setRows(data);
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, []);

  const exportCSV = () => {
    const headers = ["timestamp", "user_email", "event", "ip", "user_agent", "extra"];
    const csv = [headers.join(",")].concat(
      rows.map(r => headers.map(h => `"${String(r[h] ?? "").replace(/"/g, '""')}"`).join(","))
    ).join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a"); a.href = url;
    a.download = `security_log_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click(); URL.revokeObjectURL(url);
  };

  return (
    <div className="space-y-6" data-testid="security-log-page">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="label-caps">Admin · Security</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1 flex items-center gap-2">
            <Shield size={28} /> Security Audit Log
          </h1>
          <p className="text-sm text-muted-foreground mt-2">Every login, failed login, password change, admin action, and session revocation.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={load} data-testid="sl-refresh"><RefreshCw size={14} className={busy ? "animate-spin" : ""} /> Refresh</Button>
          <Button variant="outline" onClick={exportCSV} data-testid="sl-export"><Download size={14} /> CSV</Button>
        </div>
      </div>

      <Card className="p-4 rounded-lg">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label className="label-caps">Event</label>
            <Select value={event || "__all__"} onValueChange={(v) => setEvent(v === "__all__" ? "" : v)}>
              <SelectTrigger className="mt-1.5" data-testid="sl-event"><SelectValue placeholder="All events" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">All events</SelectItem>
                {EVENT_OPTIONS.filter(Boolean).map(e => <SelectItem key={e} value={e}>{e.replaceAll("_", " ")}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div>
            <label className="label-caps">User email</label>
            <Input className="mt-1.5" value={userEmail} onChange={e => setUserEmail(e.target.value)}
                   placeholder="sales@indianrounds.com" data-testid="sl-user" />
          </div>
          <div className="flex items-end">
            <Button onClick={load} disabled={busy} className="w-full h-10" style={{ background: "#0A0A0A" }} data-testid="sl-filter">
              <Search size={14} /> Filter
            </Button>
          </div>
        </div>
      </Card>

      <Card className="rounded-lg overflow-hidden">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>When</TableHead>
                <TableHead>User</TableHead>
                <TableHead>Event</TableHead>
                <TableHead>IP</TableHead>
                <TableHead>Device</TableHead>
                <TableHead>Details</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.length === 0 && (
                <TableRow><TableCell colSpan={6} className="text-center text-sm text-muted-foreground py-8">No events match your filter</TableCell></TableRow>
              )}
              {rows.map((r) => (
                <TableRow key={r.id} data-testid={`sl-row-${r.id}`}>
                  <TableCell className="text-xs font-mono">{fmt(r.timestamp)}</TableCell>
                  <TableCell>
                    <div className="font-medium text-sm">{r.user_name || "—"}</div>
                    <div className="text-xs text-muted-foreground">{r.user_email}</div>
                  </TableCell>
                  <TableCell>
                    <span className={`inline-flex px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${EVENT_STYLES[r.event] || "bg-gray-100"}`}>
                      {r.event}
                    </span>
                  </TableCell>
                  <TableCell className="text-xs font-mono">{r.ip || "—"}</TableCell>
                  <TableCell className="text-xs text-muted-foreground max-w-[220px] truncate" title={r.user_agent}>{r.user_agent || "—"}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{r.extra && Object.keys(r.extra).length ? JSON.stringify(r.extra) : "—"}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </Card>
    </div>
  );
}
