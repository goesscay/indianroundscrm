"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Loader2, Search, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { Activity, ActivityInput, ActivityOptions, ActivityPriority, ActivityType, Lead } from "@/types";
import { ACTIVITY_TYPES, PRIORITIES, canEditActivity, fromLocalInput, toLocalInput } from "./shared";

export type ActivityChange = { kind: "created" | "updated" | "deleted"; activity: Activity };

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Edit this activity; omit for create mode. */
  activity?: Activity | null;
  /** Create-mode prefill. `due_local` is a datetime-local string ("YYYY-MM-DDTHH:mm"). */
  defaults?: { due_local?: string; lead?: { lead_id: string; customer_name: string } | null };
  onChanged: (change: ActivityChange) => void;
}

const NOBODY = "nobody";

export default function ActivityDialog({ open, onOpenChange, activity, defaults, onChanged }: Props) {
  const { user, can } = useCurrentUser();
  const canManage = can("activities.manage");
  const editing = !!activity;

  const [type, setType] = useState<ActivityType>("task");
  const [priority, setPriority] = useState<ActivityPriority>("medium");
  const [title, setTitle] = useState("");
  const [due, setDue] = useState("");
  const [assignee, setAssignee] = useState<string>(NOBODY);
  const [lead, setLead] = useState<{ lead_id: string; customer_name: string } | null>(null);
  const [details, setDetails] = useState("");
  const [done, setDone] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [options, setOptions] = useState<ActivityOptions | null>(null);

  // lead picker
  const [leadQ, setLeadQ] = useState("");
  const [leadResults, setLeadResults] = useState<Lead[]>([]);
  const [leadLoading, setLeadLoading] = useState(false);
  const leadReq = useRef(0);

  const myId = user.id ? String(user.id) : "";

  // Reset the form whenever the dialog opens.
  useEffect(() => {
    if (!open) return;
    setError(""); setSaving(false); setLeadQ(""); setLeadResults([]);
    if (activity) {
      setType(activity.type); setPriority(activity.priority); setTitle(activity.title);
      setDue(toLocalInput(activity.due_at)); setAssignee(activity.assigned_to?.id ?? NOBODY);
      setLead(activity.lead); setDetails(activity.details ?? ""); setDone(activity.done);
    } else {
      setType("task"); setPriority("medium"); setTitle(""); setDue(defaults?.due_local ?? "");
      setAssignee(myId || NOBODY); setLead(defaults?.lead ?? null); setDetails(""); setDone(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, activity?.id]);

  useEffect(() => {
    if (!open || options) return;
    api.get<ActivityOptions>("/activities/options").then((r) => setOptions(r.data)).catch(() => { /* assignee list stays empty */ });
  }, [open, options]);

  // Debounced lead search.
  useEffect(() => {
    if (!open || lead || !leadQ.trim()) { setLeadResults([]); setLeadLoading(false); return; }
    setLeadLoading(true);
    const id = ++leadReq.current;
    const t = setTimeout(() => {
      api.get<{ items: Lead[] }>("/leads", { params: { page: 1, per_page: 10, q: leadQ.trim() } })
        .then((r) => { if (id === leadReq.current) setLeadResults(r.data.items ?? []); })
        .catch(() => { if (id === leadReq.current) setLeadResults([]); })
        .finally(() => { if (id === leadReq.current) setLeadLoading(false); });
    }, 300);
    return () => clearTimeout(t);
  }, [leadQ, lead, open]);

  const canEdit = !activity || canEditActivity(can, myId, activity);
  const canDelete = editing && (canManage || (can("activities.create") && activity?.created_by?.id === myId));

  // Assignee choices: managers see everyone the API allows; others only themselves.
  const users = options?.users ?? [];
  const assigneeChoices = canManage ? users : users.filter((u) => u.id === myId);
  const hasCurrent = assignee === NOBODY || assigneeChoices.some((u) => u.id === assignee);
  const extraAssignee = !hasCurrent && activity?.assigned_to?.id === assignee ? activity.assigned_to : null;
  // Before the options load, keep the selected value renderable.
  const selfFallback = !options && assignee === myId && myId ? { id: myId, name: user.name || user.email } : null;

  const buildPayload = (): ActivityInput => ({
    type, priority, title: title.trim(), details: details.trim() || null,
    due_at: fromLocalInput(due), assigned_to: assignee === NOBODY ? null : assignee,
    lead_id: lead?.lead_id ?? null,
  });

  const save = async (e?: FormEvent) => {
    e?.preventDefault();
    if (!title.trim()) { setError("Please enter what needs to be done."); return; }
    if (due && !fromLocalInput(due)) { setError("Enter a valid due date."); return; }
    setSaving(true); setError("");
    try {
      if (activity) {
        const payload: ActivityInput = { ...buildPayload(), done };
        const { data } = await api.patch<Activity>(`/activities/${activity.id}`, payload);
        toast.success("Activity updated");
        onChanged({ kind: "updated", activity: data });
      } else {
        const { data } = await api.post<Activity>("/activities", buildPayload());
        toast.success("Activity added");
        onChanged({ kind: "created", activity: data });
      }
      onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  const remove = async () => {
    if (!activity) return;
    if (!window.confirm(`Delete "${activity.title}"? This cannot be undone.`)) return;
    setSaving(true); setError("");
    try {
      await api.delete(`/activities/${activity.id}`);
      toast.success("Activity deleted");
      onChanged({ kind: "deleted", activity });
      onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  const fieldsDisabled = saving || !canEdit;

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!saving) onOpenChange(o); }}>
      <DialogContent className="sm:max-w-xl max-h-[92vh] overflow-y-auto" data-testid="activity-dialog">
        <DialogHeader>
          <DialogTitle className="font-display">{editing ? "Edit activity" : "New activity"}</DialogTitle>
          <DialogDescription>
            {editing ? (canEdit ? "Update the details, or mark it done." : "You can view this activity but not change it.") : "Log a task, call or meeting and assign it."}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={save} className="space-y-4" data-testid="activity-form">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <Label htmlFor="act-type" className="label-caps">Type</Label>
              <Select value={type} onValueChange={(v) => setType(v as ActivityType)} disabled={fieldsDisabled}>
                <SelectTrigger id="act-type" className="mt-1.5" data-testid="activity-type"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {ACTIVITY_TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label htmlFor="act-priority" className="label-caps">Priority</Label>
              <Select value={priority} onValueChange={(v) => setPriority(v as ActivityPriority)} disabled={fieldsDisabled}>
                <SelectTrigger id="act-priority" className="mt-1.5" data-testid="activity-priority"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {PRIORITIES.map((p) => <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div>
            <Label htmlFor="act-title" className="label-caps">What <span className="text-destructive">*</span></Label>
            <Input id="act-title" className="mt-1.5" value={title} onChange={(e) => setTitle(e.target.value)}
                   placeholder="e.g. Call the customer about the quote" maxLength={200} required autoFocus
                   disabled={fieldsDisabled} data-testid="activity-title" />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <Label htmlFor="act-due" className="label-caps">Due</Label>
              <Input id="act-due" type="datetime-local" className="mt-1.5" value={due} onChange={(e) => setDue(e.target.value)}
                     disabled={fieldsDisabled} data-testid="activity-due" />
            </div>
            <div>
              <Label htmlFor="act-assignee" className="label-caps">Assigned to</Label>
              <Select value={assignee} onValueChange={setAssignee} disabled={fieldsDisabled}>
                <SelectTrigger id="act-assignee" className="mt-1.5" data-testid="activity-assignee"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {(canManage || assignee === NOBODY) && <SelectItem value={NOBODY}>Nobody</SelectItem>}
                  {extraAssignee && <SelectItem value={extraAssignee.id}>{extraAssignee.name}</SelectItem>}
                  {selfFallback && <SelectItem value={selfFallback.id}>{selfFallback.name}</SelectItem>}
                  {assigneeChoices.map((u) => <SelectItem key={u.id} value={u.id}>{u.name}{u.id === myId ? " (me)" : ""}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div>
            <Label className="label-caps">About</Label>
            {lead ? (
              <div className="mt-1.5 flex items-center justify-between gap-2 rounded-md border border-input px-3 h-9 text-sm" data-testid="activity-lead-selected">
                <span className="truncate"><span className="text-muted-foreground">Lead:</span> <span className="font-medium">{lead.customer_name}</span> <span className="font-mono text-xs text-muted-foreground">{lead.lead_id}</span></span>
                {!fieldsDisabled && (
                  <button type="button" onClick={() => setLead(null)} aria-label="Clear lead" className="rounded p-0.5 text-muted-foreground hover:text-foreground hover:bg-accent" data-testid="activity-lead-clear">
                    <X size={14} />
                  </button>
                )}
              </div>
            ) : (
              <div className="relative mt-1.5">
                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <Input value={leadQ} onChange={(e) => setLeadQ(e.target.value)} className="pl-8" disabled={fieldsDisabled}
                       placeholder="Nothing in particular. Search a lead to link…" aria-label="Search leads" data-testid="activity-lead-search" />
                {leadQ.trim() && (
                  <div className="absolute z-20 left-0 right-0 mt-1 max-h-48 overflow-y-auto rounded-md border border-border bg-popover text-popover-foreground shadow-md" data-testid="activity-lead-results">
                    {leadLoading && <div className="px-3 py-2 text-xs text-muted-foreground">Searching…</div>}
                    {!leadLoading && leadResults.length === 0 && <div className="px-3 py-2 text-xs text-muted-foreground">No leads found.</div>}
                    {leadResults.map((l) => (
                      <button key={l.lead_id} type="button" onClick={() => { setLead({ lead_id: l.lead_id, customer_name: l.customer_name }); setLeadQ(""); }}
                              className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-accent" data-testid={`activity-lead-opt-${l.lead_id}`}>
                        <span className="truncate font-medium">{l.customer_name}</span>
                        <span className="font-mono text-xs text-muted-foreground">{l.lead_id}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>

          <div>
            <Label htmlFor="act-details" className="label-caps">Details</Label>
            <Textarea id="act-details" className="mt-1.5" rows={3} value={details} onChange={(e) => setDetails(e.target.value)}
                      disabled={fieldsDisabled} placeholder="Optional notes" data-testid="activity-details" />
          </div>

          {editing && canEdit && (
            <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
              <input type="checkbox" checked={done} onChange={(e) => setDone(e.target.checked)} disabled={saving}
                     className="h-4 w-4 accent-emerald-600" data-testid="activity-done-toggle" />
              {done ? "Done (untick to reopen)" : "Mark as done"}
              {activity?.done && activity.completed_by && <span className="text-xs text-muted-foreground">by {activity.completed_by.name}</span>}
            </label>
          )}

          {error && <div className="text-sm text-destructive" role="alert" data-testid="activity-error">{error}</div>}

          <DialogFooter className="gap-2 sm:gap-0 sm:justify-between">
            <div>
              {canDelete && (
                <Button type="button" variant="outline" onClick={remove} disabled={saving}
                        className="text-destructive hover:text-destructive" data-testid="activity-delete">
                  <Trash2 size={14} /> Delete
                </Button>
              )}
            </div>
            <div className="flex flex-col-reverse sm:flex-row gap-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving} data-testid="activity-cancel">Cancel</Button>
              {canEdit && (
                <Button type="submit" disabled={saving} data-testid="activity-save">
                  {saving && <Loader2 size={14} className="animate-spin" />}
                  {editing ? "Save changes" : "Add activity"}
                </Button>
              )}
            </div>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
