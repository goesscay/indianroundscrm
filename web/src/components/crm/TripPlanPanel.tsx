"use client";

import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { AlertTriangle, CalendarCheck, Loader2, Plane, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import TripDocumentsPanel from "./TripDocumentsPanel";
import { INR, fmtDate } from "./shared";
import { CATEGORIES, PaymentChip, ProgressBar, TASK_STATUS, TRIP_TYPES, daysLabel, type TripDetail, type TripTask } from "./tripShared";

/** Payment progress and the operations checklist of a deal. Shown on the deal page. */
export default function TripPlanPanel({ dealId, onChanged }: { dealId: string; onChanged?: () => void }) {
  const { can } = useCurrentUser();
  const canManage = can("trips.manage");
  const canConfirm = can("trips.confirm");
  const [t, setT] = useState<TripDetail | null>(null);
  const [error, setError] = useState("");
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [type, setType] = useState("domestic");
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState<string | null>(null); // task whose notes are open
  const [adding, setAdding] = useState(false);
  const [newTask, setNewTask] = useState({ title: "", category: "other", due_date: "", critical: false });

  const load = useCallback(async () => {
    try { const { data } = await api.get<TripDetail>(`/deals/${dealId}/trip`); setT(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [dealId]);
  useEffect(() => { void load(); }, [load]);

  const patch = async (task: TripTask, body: Record<string, unknown>) => {
    try {
      const { data } = await api.patch<TripTask>(`/trip-tasks/${task.id}`, body);
      setT((cur) => (cur ? { ...cur, tasks: cur.tasks.map((x) => (x.id === task.id ? data : x)) } : cur));
      void load(); onChanged?.();
    } catch (e) { toast.error(formatApiError(e)); }
  };
  const remove = async (task: TripTask) => {
    if (!window.confirm(`Remove "${task.title}" from this trip?`)) return;
    try { await api.delete(`/trip-tasks/${task.id}`); void load(); } catch (e) { toast.error(formatApiError(e)); }
  };
  const confirm = async () => {
    setBusy(true);
    try { const { data } = await api.post<TripDetail>(`/deals/${dealId}/trip/confirm`, { trip_type: type }); setT(data); setConfirmOpen(false); toast.success("Trip confirmed. The plan is ready."); onChanged?.(); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };
  const addTask = async (e: FormEvent) => {
    e.preventDefault();
    if (!newTask.title.trim()) return;
    try {
      await api.post(`/deals/${dealId}/trip/tasks`, { title: newTask.title, category: newTask.category, critical: newTask.critical, ...(newTask.due_date ? { due_date: newTask.due_date } : {}) });
      setNewTask({ title: "", category: "other", due_date: "", critical: false }); setAdding(false); void load();
    } catch (err) { toast.error(formatApiError(err)); }
  };

  const groups = useMemo(() => {
    const g: Record<string, TripTask[]> = {};
    for (const task of t?.tasks ?? []) (g[task.category] ||= []).push(task);
    return CATEGORIES.filter((c) => g[c.value]?.length).map((c) => ({ ...c, tasks: g[c.value] }));
  }, [t]);

  if (error) return <Card className="rounded-lg p-5 text-sm text-destructive">{error}</Card>;
  if (!t) return <Card className="rounded-lg p-5 text-sm text-muted-foreground"><Loader2 size={14} className="mr-2 inline animate-spin" />Loading trip plan…</Card>;

  const p = t.payment;
  return (
    <>
    <Card className="rounded-lg p-5" data-testid="trip-plan">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="flex items-center gap-2 font-display text-lg font-bold"><Plane size={16} className="text-muted-foreground" /> Trip plan</h3>
        <span className="text-xs text-muted-foreground">{daysLabel(t.days_left)}{t.trip_type ? ` · ${TRIP_TYPES.find((x) => x.value === t.trip_type)?.label ?? t.trip_type}` : ""}</span>
      </div>

      {/* money */}
      <div className="rounded-md border border-border p-3" data-testid="trip-payment">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="text-sm font-semibold">Payment <PaymentChip p={p} /> {p.advance_received && p.status !== "paid" && <span className="ml-1 text-xs font-normal text-muted-foreground">advance received</span>}</div>
          <div className="text-xs text-muted-foreground">{p.invoices === 0 ? "No invoice yet" : `${p.invoices} invoice${p.invoices === 1 ? "" : "s"}`}</div>
        </div>
        <div className="mt-2"><ProgressBar percent={p.percent} /></div>
        <div className="mt-2 grid grid-cols-3 gap-2 text-sm">
          <div><div className="label-caps">Trip value</div><div className="font-semibold" data-testid="trip-invoiced">{INR(p.invoiced)}</div></div>
          <div><div className="label-caps">Paid</div><div className="font-semibold text-emerald-700 dark:text-emerald-400" data-testid="trip-paid">{INR(p.paid)}</div></div>
          <div><div className="label-caps">Balance</div><div className={cn("font-semibold", p.balance > 0 && "text-red-600 dark:text-red-400")} data-testid="trip-balance">{INR(p.balance)}</div></div>
        </div>
        {p.balance > 0 && p.next_due && <div className="mt-1 text-xs text-muted-foreground">Next payment due {fmtDate(p.next_due)}{p.overdue ? " (overdue)" : ""}</div>}
      </div>

      {!t.confirmed ? (
        <div className="mt-3 rounded-md border border-dashed border-border p-4 text-sm" data-testid="trip-unconfirmed">
          <p className="text-muted-foreground">The checklist is created when the trip is confirmed. This happens automatically once {t.advance_percent}% of the trip is paid{canConfirm ? ", or you can confirm it now." : "."}</p>
          {canConfirm && <Button className="mt-3" onClick={() => setConfirmOpen(true)} data-testid="trip-confirm-btn"><CalendarCheck size={15} /> Confirm trip</Button>}
        </div>
      ) : (
        <>
          <div className="mt-4" data-testid="trip-progress">
            <div className="mb-1 flex items-center justify-between text-sm">
              <span className="font-semibold">{t.progress.done} of {t.progress.total} done</span>
              <span className="text-xs text-muted-foreground">
                {t.progress.overdue > 0 && <span className="mr-2 inline-flex items-center gap-1 font-medium text-red-600 dark:text-red-400"><AlertTriangle size={12} />{t.progress.overdue} overdue</span>}
                {t.progress.critical_open > 0 ? `${t.progress.critical_open} critical open` : "All critical items done"}
              </span>
            </div>
            <ProgressBar percent={t.progress.percent} tone={t.progress.overdue > 0 ? "warn" : "default"} />
          </div>

          <div className="mt-4 space-y-4">
            {groups.map((g) => (
              <section key={g.value}>
                <h4 className="label-caps mb-1">{g.label}</h4>
                <ul className="divide-y divide-border rounded-md border border-border">
                  {g.tasks.map((task) => (
                    <li key={task.id} className="p-2.5 text-sm" data-testid={`trip-task-${task.id}`}>
                      <div className="flex items-start gap-2.5">
                        <input type="checkbox" className="mt-1 h-4 w-4 shrink-0 accent-black dark:accent-white" checked={task.status === "done"} disabled={!canManage}
                               onChange={(e) => patch(task, { status: e.target.checked ? "done" : "pending" })} aria-label={`Mark "${task.title}" done`} data-testid={`trip-done-${task.id}`} />
                        <div className="min-w-0 flex-1">
                          <button type="button" className={cn("text-left font-medium", (task.status === "done" || task.status === "na") && "text-muted-foreground line-through")} onClick={() => setOpen(open === task.id ? null : task.id)}>
                            {task.title}
                          </button>
                          {task.critical && <span className="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-red-800 dark:bg-red-950/60 dark:text-red-300">Critical</span>}
                          <div className="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground">
                            {task.due_date && <span className={cn(task.overdue && "font-semibold text-red-600 dark:text-red-400")}>Due {fmtDate(task.due_date)}{task.overdue ? " · overdue" : ""}</span>}
                            {task.assigned_to && <span>{task.assigned_to.name}</span>}
                            {task.status === "in_progress" && <span className="font-medium text-amber-700 dark:text-amber-300">In progress</span>}
                            {task.status === "na" && <span>Not needed</span>}
                            {task.notes && <span className="truncate italic">“{task.notes}”</span>}
                          </div>
                        </div>
                        {canManage && (
                          <button type="button" onClick={() => remove(task)} aria-label={`Remove ${task.title}`} className="rounded p-1 text-muted-foreground hover:text-destructive"><Trash2 size={13} /></button>
                        )}
                      </div>
                      {open === task.id && canManage && (
                        <div className="mt-2 grid gap-2 pl-6 sm:grid-cols-3">
                          <div>
                            <Label className="label-caps">Status</Label>
                            <Select value={task.status} onValueChange={(v) => patch(task, { status: v })}>
                              <SelectTrigger className="mt-1 h-8"><SelectValue /></SelectTrigger>
                              <SelectContent>{TASK_STATUS.map((s) => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}</SelectContent>
                            </Select>
                          </div>
                          <div>
                            <Label className="label-caps">Assigned to</Label>
                            <Select value={task.assigned_to?.id ?? "none"} onValueChange={(v) => patch(task, { assigned_to: v === "none" ? null : v })}>
                              <SelectTrigger className="mt-1 h-8"><SelectValue placeholder="Unassigned" /></SelectTrigger>
                              <SelectContent><SelectItem value="none">Unassigned</SelectItem>{t.assignees.map((u) => <SelectItem key={u.id} value={u.id}>{u.name}</SelectItem>)}</SelectContent>
                            </Select>
                          </div>
                          <div>
                            <Label className="label-caps">Due date</Label>
                            <Input type="date" className="mt-1 h-8" defaultValue={task.due_date ?? ""} onBlur={(e) => e.target.value !== (task.due_date ?? "") && patch(task, { due_date: e.target.value || null })} />
                          </div>
                          <div className="sm:col-span-3">
                            <Label className="label-caps">Notes</Label>
                            <Textarea rows={2} className="mt-1 min-h-0 text-sm" defaultValue={task.notes ?? ""} placeholder="Booking reference, supplier, anything the team should know"
                                      onBlur={(e) => e.target.value !== (task.notes ?? "") && patch(task, { notes: e.target.value })} />
                          </div>
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>

          {canManage && (adding ? (
            <form onSubmit={addTask} className="mt-4 grid gap-2 rounded-md border border-border p-3 sm:grid-cols-[1fr_9rem_9rem_auto]" data-testid="trip-add-form">
              <Input value={newTask.title} onChange={(e) => setNewTask({ ...newTask, title: e.target.value })} placeholder="What needs doing?" autoFocus aria-label="New item" />
              <Select value={newTask.category} onValueChange={(v) => setNewTask({ ...newTask, category: v })}>
                <SelectTrigger aria-label="Category"><SelectValue /></SelectTrigger>
                <SelectContent>{CATEGORIES.map((c) => <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>)}</SelectContent>
              </Select>
              <Input type="date" value={newTask.due_date} onChange={(e) => setNewTask({ ...newTask, due_date: e.target.value })} aria-label="Due date" />
              <div className="flex items-center gap-2">
                <label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={newTask.critical} onChange={(e) => setNewTask({ ...newTask, critical: e.target.checked })} className="accent-black dark:accent-white" /> Critical</label>
                <Button type="submit" size="sm">Add</Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setAdding(false)}>Cancel</Button>
              </div>
            </form>
          ) : (
            <Button variant="outline" size="sm" className="mt-4" onClick={() => setAdding(true)} data-testid="trip-add-btn"><Plus size={14} /> Add item</Button>
          ))}
        </>
      )}

      <Dialog open={confirmOpen} onOpenChange={(o) => !busy && setConfirmOpen(o)}>
        <DialogContent className="max-w-md" data-testid="trip-confirm-dialog">
          <DialogHeader>
            <DialogTitle className="font-display text-2xl font-black">Confirm this trip</DialogTitle>
            <DialogDescription>This books the deal and creates the checklist, with due dates counted back from the travel date{t.deal.travel_date ? ` (${fmtDate(t.deal.travel_date)})` : ". Add a travel date on the deal so the due dates can be set"}.</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            {TRIP_TYPES.map((x) => (
              <label key={x.value} className={cn("flex cursor-pointer items-start gap-3 rounded-md border p-3", type === x.value ? "border-foreground" : "border-border")}>
                <input type="radio" name="trip-type" className="mt-1 accent-black dark:accent-white" checked={type === x.value} onChange={() => setType(x.value)} />
                <span><span className="block text-sm font-semibold">{x.label}</span><span className="block text-xs text-muted-foreground">{x.hint}</span></span>
              </label>
            ))}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmOpen(false)} disabled={busy}>Cancel</Button>
            <Button onClick={confirm} disabled={busy} data-testid="trip-confirm-submit">{busy ? "Confirming…" : "Confirm trip"}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
    {t.confirmed && can("trip_docs.view") && <TripDocumentsPanel dealId={dealId} onChanged={() => { void load(); onChanged?.(); }} />}
    </>
  );
}
