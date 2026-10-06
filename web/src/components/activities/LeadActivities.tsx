"use client";

import { useCallback, useEffect, useState } from "react";
import { ListChecks, Plus } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import { cn } from "@/lib/utils";
import type { Activity } from "@/types";
import ActivityDialog from "./ActivityDialog";
import { DoneToggle, canEditActivity, dueClass, formatDue, typeMeta } from "./shared";

/** Compact "Activities" card for the lead detail page. */
/** `embedded` drops the card chrome and heading so it can sit inside a tab; `onCount` reports the number of activities. */
export default function LeadActivities({ leadId, customerName, embedded, onCount }: { leadId: string; customerName: string; embedded?: boolean; onCount?: (n: number) => void }) {
  const { user, can } = useCurrentUser();
  const myId = user.id ? String(user.id) : "";
  const canCreate = can("activities.create", "activities.manage");
  const [items, setItems] = useState<Activity[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState("");
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Activity | null>(null);
  const [busy, setBusy] = useState<Record<string, boolean>>({});

  const load = useCallback(async () => {
    try {
      const { data } = await api.get<Activity[]>(`/leads/${leadId}/activities`);
      // Open first (soonest due first, undated last), then done (most recent first).
      const openList = data.filter((a) => !a.done).sort((x, y) => (x.due_at ?? "9999").localeCompare(y.due_at ?? "9999"));
      const doneList = data.filter((a) => a.done).sort((x, y) => (y.completed_at ?? "").localeCompare(x.completed_at ?? ""));
      setItems([...openList, ...doneList]); setError(""); onCount?.(openList.length);
    } catch (e) { setError(formatApiError(e)); }
    finally { setLoaded(true); }
  }, [leadId, onCount]);

  useEffect(() => { void load(); }, [load]);

  const lm = useLoadMore(items, 8, leadId);

  const toggle = async (a: Activity) => {
    setBusy((b) => ({ ...b, [a.id]: true }));
    // optimistic
    setItems((list) => list.map((x) => (x.id === a.id ? { ...x, done: !a.done } : x)));
    try {
      await api.patch<Activity>(`/activities/${a.id}`, { done: !a.done });
      toast.success(a.done ? "Reopened" : "Marked as done");
      await load();
    } catch (e) {
      toast.error(formatApiError(e));
      await load();
    } finally {
      setBusy((b) => { const c = { ...b }; delete c[a.id]; return c; });
    }
  };

  return (
    <Wrap embedded={embedded}>
      <div className={`flex items-center gap-3 mb-2 ${embedded ? "justify-between" : "justify-between"}`}>
        {embedded ? <span className="text-xs text-muted-foreground">{loaded ? `${items.filter((a) => !a.done).length} open · ${items.length} total` : ""}</span> : (
        <div className="flex items-center gap-2">
          <ListChecks size={16} className="text-muted-foreground" />
          <h3 className="font-display text-base font-bold">Activities</h3>
          {loaded && <span className="text-xs text-muted-foreground">({items.length})</span>}
        </div>)}
        {canCreate && (
          <Button size="sm" variant="outline" onClick={() => { setEditing(null); setOpen(true); }} data-testid="lead-activity-add">
            <Plus size={14} /> Add activity
          </Button>
        )}
      </div>

      {error && <div className="text-sm text-destructive">{error}</div>}
      {loaded && !error && items.length === 0 && <div className="text-sm text-muted-foreground py-2">No activities for this lead yet.</div>}

      <ul className="divide-y divide-border">
        {lm.visible.map((a) => {
          const m = typeMeta(a.type);
          const Icon = m.icon;
          return (
            <li key={a.id} className="flex items-start gap-3 py-2.5" data-testid={`lead-activity-${a.id}`}>
              <div className="pt-0.5">
                <DoneToggle done={a.done} title={a.title} onToggle={() => toggle(a)} size={20}
                            disabled={busy[a.id] || !canEditActivity(can, myId, a)} testId={`lead-activity-done-${a.id}`} />
              </div>
              <button type="button" onClick={() => { setEditing(a); setOpen(true); }} className="min-w-0 flex-1 text-left focus-visible:outline-none focus-visible:underline">
                <div className={cn("text-sm font-medium truncate", a.done && "line-through text-muted-foreground")}>{a.title}</div>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                  <span className="inline-flex items-center gap-1"><Icon size={11} aria-hidden /> {m.label}</span>
                  <span className={dueClass(a)}>{formatDue(a.due_at)}</span>
                  <span>{a.assigned_to?.name ?? "Nobody"}</span>
                </div>
              </button>
            </li>
          );
        })}
      </ul>
      <LoadMore lm={lm} />

      <ActivityDialog
        open={open} onOpenChange={setOpen} activity={editing}
        defaults={{ lead: { lead_id: leadId, customer_name: customerName } }}
        onChanged={() => void load()}
      />
    </Wrap>
  );
}

function Wrap({ embedded, children }: { embedded?: boolean; children: React.ReactNode }) {
  return embedded
    ? <div data-testid="lead-activities">{children}</div>
    : <Card className="p-4 rounded-lg" data-testid="lead-activities">{children}</Card>;
}
