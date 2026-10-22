"use client";

import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { User } from "@/types";

interface Workload { leads: number; activities: number; trip_tasks: number; deals: number; allocations: number }
const NOBODY = "none";

/** Someone has left: they can no longer sign in, and their open work can be handed to a colleague in the same step. */
export default function DeactivateUserDialog({ person, people, onClose, onDone }: { person: User | null; people: User[]; onClose: () => void; onDone: () => void }) {
  const [w, setW] = useState<Workload | null>(null);
  const [heir, setHeir] = useState(NOBODY);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!person) return;
    setW(null); setHeir(NOBODY); setReason(""); setBusy(false);
    api.get<Workload>(`/users/${person.id}/workload`).then((r) => setW(r.data)).catch((e) => toast.error(formatApiError(e)));
  }, [person]);

  // colleagues who can own leads: active, not the person leaving
  const heirs = people.filter((u) => u.id !== person?.id && u.active !== false && (u.account_status ?? "active") === "active" && u.assignable !== false && (!!u.consultant_code || u.role === "sales" || u.role === "admin" || u.role === "super_admin"));
  const open = w ? w.leads + w.activities + w.trip_tasks + w.deals : 0;

  const submit = async () => {
    if (!person) return;
    setBusy(true);
    try {
      const { data } = await api.post<{ moved: { leads: number; activities: number; trip_tasks: number; deals: number } }>(`/users/${person.id}/deactivate`, { ...(heir !== NOBODY ? { reassign_to: heir } : {}), reason: reason.trim() || undefined });
      const m = data.moved;
      toast.success(heir !== NOBODY ? `${person.name} deactivated. Handed over ${m.leads} lead${m.leads === 1 ? "" : "s"}, ${m.activities} activit${m.activities === 1 ? "y" : "ies"}, ${m.trip_tasks} trip item${m.trip_tasks === 1 ? "" : "s"} and ${m.deals} deal${m.deals === 1 ? "" : "s"}.` : `${person.name} deactivated.`);
      onDone(); onClose();
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  return (
    <Dialog open={!!person} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-w-md" data-testid="deactivate-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">Deactivate {person?.name}</DialogTitle>
          <DialogDescription>They will be signed out at once and cannot sign in again. Their history stays on every record. You can reactivate them later.</DialogDescription>
        </DialogHeader>
        {!w ? <div className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 size={14} className="animate-spin" /> Checking their open work…</div> : (
          <div className="space-y-3">
            <div className="rounded-md border border-border p-3 text-sm" data-testid="deactivate-workload">
              <div className="label-caps mb-1">Still with them</div>
              {open === 0 && w.allocations === 0 ? <p className="text-muted-foreground">Nothing open.</p> : (
                <ul className="space-y-0.5">
                  <li>{w.leads} open lead{w.leads === 1 ? "" : "s"} <span className="text-xs text-muted-foreground">(New, In Progress, Quote Sent)</span></li>
                  <li>{w.activities} open activit{w.activities === 1 ? "y" : "ies"}</li>
                  <li>{w.trip_tasks} open trip checklist item{w.trip_tasks === 1 ? "" : "s"}</li>
                  <li>{w.deals} open deal{w.deals === 1 ? "" : "s"}</li>
                  {w.allocations > 0 && <li>{w.allocations} destination allocation{w.allocations === 1 ? "" : "s"} <span className="text-xs text-muted-foreground">(removed, so new leads stop going to them)</span></li>}
                </ul>
              )}
              <p className="mt-1.5 text-xs text-muted-foreground">Booked and completed trips and archived leads stay with them for the record.</p>
            </div>
            {open > 0 && (
              <div>
                <Label className="label-caps">Hand their open work to</Label>
                <Select value={heir} onValueChange={setHeir}>
                  <SelectTrigger className="mt-1.5" data-testid="deactivate-heir"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NOBODY}>Leave it with them for now</SelectItem>
                    {heirs.map((u) => <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
            )}
            <div>
              <Label className="label-caps">Note (optional)</Label>
              <Input className="mt-1.5" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} placeholder="e.g. Left the company on 31 Oct" data-testid="deactivate-reason" />
            </div>
          </div>
        )}
        <DialogFooter className="gap-2 sm:gap-2">
          <Button variant="outline" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="destructive" onClick={submit} disabled={busy || !w} data-testid="deactivate-confirm">{busy ? "Deactivating…" : "Deactivate"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
