"use client";

import { useCallback, useEffect, useState } from "react";
import { ListChecks, Plus, StickyNote, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import ActivityDialog from "@/components/activities/ActivityDialog";
import { DoneToggle, canEditActivity, dueClass, formatDue, typeMeta } from "@/components/activities/shared";
import { cn } from "@/lib/utils";
import type { Activity, CrmNote } from "@/types";

/** Notes card for a customer or a deal. `base` is "/customers/12" or "/deals/7". */
export function RecordNotes({ base, canWrite }: { base: string; canWrite: boolean }) {
  const { user, can } = useCurrentUser();
  const [notes, setNotes] = useState<CrmNote[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [txt, setTxt] = useState("");
  const [busy, setBusy] = useState(false);
  const lm = useLoadMore(notes, 8, base);

  const load = useCallback(async () => {
    try { const { data } = await api.get<CrmNote[]>(`${base}/notes`); setNotes(data); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setLoaded(true); }
  }, [base]);
  useEffect(() => { void load(); }, [load]);

  const add = async () => {
    if (!txt.trim()) return;
    setBusy(true);
    try { await api.post(`${base}/notes`, { note: txt.trim() }); setTxt(""); await load(); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };
  const remove = async (id: string) => {
    if (!window.confirm("Delete this note?")) return;
    try { await api.delete(`/crm-notes/${id}`); await load(); } catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <Card className="p-5 rounded-lg" data-testid="record-notes">
      <div className="mb-3 flex items-center gap-2">
        <StickyNote size={16} className="text-muted-foreground" />
        <h3 className="font-display font-bold">Notes</h3>
        {loaded && <span className="text-xs text-muted-foreground">({notes.length})</span>}
      </div>
      {canWrite && (
        <div className="mb-3 space-y-2">
          <Textarea rows={2} value={txt} onChange={(e) => setTxt(e.target.value)} placeholder="Add a note…" data-testid="note-input" />
          <Button size="sm" onClick={add} disabled={busy || !txt.trim()} data-testid="note-add">Add note</Button>
        </div>
      )}
      {loaded && notes.length === 0 && <p className="text-sm text-muted-foreground">No notes yet.</p>}
      <ul className="divide-y divide-border">
        {lm.visible.map((n) => (
          <li key={n.id} className="py-2.5 text-sm" data-testid={`note-${n.id}`}>
            <p className="whitespace-pre-wrap">{n.note}</p>
            <div className="mt-1 flex items-center justify-between text-xs text-muted-foreground">
              <span>{n.created_by_name || "Someone"} · {new Date(n.created_at).toLocaleString(undefined, { day: "numeric", month: "short", hour: "numeric", minute: "2-digit" })}</span>
              {(canWrite && (String(n.created_by_id) === String(user.id) || can("customers.edit", "deals.edit"))) && (
                <button type="button" onClick={() => remove(n.id)} aria-label="Delete note" className="rounded p-1 hover:bg-accent"><Trash2 size={13} /></button>
              )}
            </div>
          </li>
        ))}
      </ul>
      <LoadMore lm={lm} />
    </Card>
  );
}

/** Activities card for a customer or deal. `link` is what new activities attach to. */
export function RecordActivities({ base, link }: { base: string; link: { customer_id?: string; deal_id?: string } }) {
  const { user, can } = useCurrentUser();
  const myId = user.id ? String(user.id) : "";
  const canCreate = can("activities.create", "activities.manage");
  const [items, setItems] = useState<Activity[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState("");
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Activity | null>(null);
  const lm = useLoadMore(items, 8, base);

  const load = useCallback(async () => {
    try { const { data } = await api.get<Activity[]>(`${base}/activities`); setItems(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
    finally { setLoaded(true); }
  }, [base]);
  useEffect(() => { void load(); }, [load]);

  const toggle = async (a: Activity) => {
    try { await api.patch(`/activities/${a.id}`, { done: !a.done }); toast.success(a.done ? "Reopened" : "Marked as done"); }
    catch (e) { toast.error(formatApiError(e)); }
    await load();
  };

  return (
    <Card className="p-5 rounded-lg" data-testid="record-activities">
      <div className="mb-3 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <ListChecks size={16} className="text-muted-foreground" />
          <h3 className="font-display font-bold">Activities</h3>
          {loaded && <span className="text-xs text-muted-foreground">({items.length})</span>}
        </div>
        {canCreate && (
          <Button size="sm" variant="outline" onClick={() => { setEditing(null); setOpen(true); }} data-testid="record-activity-add"><Plus size={14} /> Add activity</Button>
        )}
      </div>
      {error && <div className="text-sm text-destructive">{error}</div>}
      {loaded && !error && items.length === 0 && <p className="py-2 text-sm text-muted-foreground">No calls, meetings or tasks yet.</p>}
      <ul className="divide-y divide-border">
        {lm.visible.map((a) => {
          const m = typeMeta(a.type); const Icon = m.icon;
          return (
            <li key={a.id} className="flex items-start gap-3 py-2.5">
              <div className="pt-0.5"><DoneToggle done={a.done} title={a.title} onToggle={() => toggle(a)} size={20} disabled={!canEditActivity(can, myId, a)} testId={`record-activity-done-${a.id}`} /></div>
              <button type="button" onClick={() => { setEditing(a); setOpen(true); }} className="min-w-0 flex-1 text-left focus-visible:outline-none focus-visible:underline">
                <div className={cn("truncate text-sm font-medium", a.done && "line-through text-muted-foreground")}>{a.title}</div>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
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
      <ActivityDialog open={open} onOpenChange={setOpen} activity={editing} defaults={link} onChanged={() => void load()} />
    </Card>
  );
}
