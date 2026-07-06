import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { toast } from "sonner";

export default function ActivityLog() {
  const [rows, setRows] = useState([]);

  useEffect(() => {
    (async () => {
      try { const { data } = await api.get("/activity"); setRows(data); }
      catch (e) { toast.error(formatApiError(e)); }
    })();
  }, []);

  return (
    <div className="space-y-6" data-testid="activity-page">
      <div>
        <div className="label-caps">Audit trail</div>
        <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Activity Log</h1>
      </div>

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
            <TableBody>
              {rows.length === 0 && <TableRow><TableCell colSpan={5} className="text-center text-muted-foreground py-8">No activity yet</TableCell></TableRow>}
              {rows.map(r => (
                <TableRow key={r.id}>
                  <TableCell className="font-mono text-xs">{(r.timestamp || "").slice(0, 19).replace("T", " ")}</TableCell>
                  <TableCell className="font-medium">{r.user_name || r.user_email}</TableCell>
                  <TableCell><span className="label-caps px-2 py-1 rounded bg-secondary">{r.action}</span></TableCell>
                  <TableCell>{r.entity} {r.entity_id && <span className="text-xs text-muted-foreground">({r.entity_id})</span>}</TableCell>
                  <TableCell className="text-xs text-muted-foreground max-w-md truncate">
                    {r.new_value ? JSON.stringify(r.new_value).slice(0, 120) : "—"}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </Card>
    </div>
  );
}
