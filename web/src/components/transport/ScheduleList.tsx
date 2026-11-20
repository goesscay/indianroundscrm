"use client";

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { AlertTriangle, Copy, Download, Loader2, MoreHorizontal, Pencil, Plane, Search, Train, Trash2, Upload } from "lucide-react";
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
import { fmtDate } from "@/components/crm/shared";
import { cn } from "@/lib/utils";
import TransportTabs from "@/components/transport/TransportTabs";
import type { DayKey, ScheduleKind, TransportSchedule } from "@/types";

export const DAYS: { k: DayKey; l: string }[] = [{ k: "mon", l: "Mon" }, { k: "tue", l: "Tue" }, { k: "wed", l: "Wed" }, { k: "thu", l: "Thu" }, { k: "fri", l: "Fri" }, { k: "sat", l: "Sat" }, { k: "sun", l: "Sun" }];
const ALL_DAYS = DAYS.map((d) => d.k);
const place = (name: string, code: string | null) => (code ? `${name} (${code})` : name);
const timeOf = (s: TransportSchedule) => [s.departs, s.arrives].filter(Boolean).join(" → ") + (s.arrives && s.arrives_next_day ? " (+1)" : "");

interface Form { title: string; from_name: string; from_code: string; to_name: string; to_code: string; serves: string; departs: string; arrives: string; arrives_next_day: boolean; days: DayKey[]; classes: string; note: string }
const EMPTY: Form = { title: "", from_name: "", from_code: "", to_name: "", to_code: "", serves: "", departs: "", arrives: "", arrives_next_day: false, days: ALL_DAYS, classes: "", note: "" };

function ScheduleDialog({ kind, open, item, onClose, onSaved }: { kind: ScheduleKind; open: boolean; item: TransportSchedule | null; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState<Form>(EMPTY);
  const [busy, setBusy] = useState(false);
  const noun = kind === "flight" ? "flight" : "train";
  useEffect(() => {
    if (open) setF(item ? { title: item.title, from_name: item.from_name, from_code: item.from_code ?? "", to_name: item.to_name, to_code: item.to_code ?? "", serves: item.serves ?? "", departs: item.departs ?? "", arrives: item.arrives ?? "", arrives_next_day: item.arrives_next_day, days: item.days, classes: item.classes ?? "", note: item.note ?? "" } : EMPTY);
  }, [open, item]);
  const save = async (e: FormEvent, again: boolean) => {
    e.preventDefault(); setBusy(true);
    const body = { kind, title: f.title, from_name: f.from_name, from_code: f.from_code || null, to_name: f.to_name, to_code: f.to_code || null, serves: f.serves || null, departs: f.departs || null, arrives: f.arrives || null,
      arrives_next_day: f.arrives_next_day, days: f.days, classes: f.classes || null, note: f.note || null };
    try {
      if (item) await api.patch(`/transport/schedules/${item.id}`, body); else await api.post("/transport/schedules", body);
      toast.success(`${noun === "flight" ? "Flight" : "Train"} saved`); onSaved();
      if (again && !item) setF({ ...EMPTY }); else onClose();
    } catch (err) { toast.error(formatApiError(err)); } finally { setBusy(false); }
  };
  const toggle = (d: DayKey) => setF((c) => ({ ...c, days: c.days.includes(d) ? c.days.filter((x) => x !== d) : [...c.days, d] }));
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto" data-testid="schedule-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">{item ? `Edit ${noun}` : `Add ${noun}`}</DialogTitle>
          <DialogDescription>Information only. Details are shown to customers as suggestions. There is no price field.</DialogDescription>
        </DialogHeader>
        <form onSubmit={(e) => save(e, false)} className="space-y-3">
          <div><Label className="label-caps">{kind === "flight" ? "Airline and flight number" : "Train name and number"}</Label><Input className="mt-1.5" value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} required placeholder={kind === "flight" ? "Air India AI 123" : "Shatabdi 12011"} data-testid="schedule-title" /></div>
          <div className="grid grid-cols-[1fr_90px] gap-3">
            <div><Label className="label-caps">From</Label><Input className="mt-1.5" value={f.from_name} onChange={(e) => setF({ ...f, from_name: e.target.value })} required placeholder={kind === "flight" ? "Delhi" : "New Delhi"} data-testid="schedule-from" /></div>
            <div><Label className="label-caps">{kind === "flight" ? "Airport code" : "Station code"}</Label><Input className="mt-1.5" value={f.from_code} maxLength={12} onChange={(e) => setF({ ...f, from_code: e.target.value.toUpperCase() })} placeholder={kind === "flight" ? "DEL" : "NDLS"} /></div>
            <div><Label className="label-caps">To</Label><Input className="mt-1.5" value={f.to_name} onChange={(e) => setF({ ...f, to_name: e.target.value })} required placeholder={kind === "flight" ? "Kullu-Manali" : "Kalka"} data-testid="schedule-to" /></div>
            <div><Label className="label-caps">{kind === "flight" ? "Airport code" : "Station code"}</Label><Input className="mt-1.5" value={f.to_code} maxLength={12} onChange={(e) => setF({ ...f, to_code: e.target.value.toUpperCase() })} placeholder={kind === "flight" ? "KUU" : "KLK"} /></div>
          </div>
          <div><Label className="label-caps">Also reaches (if it ends near, not at, these places)</Label><Input className="mt-1.5" value={f.serves} onChange={(e) => setF({ ...f, serves: e.target.value })} placeholder="Manali, Shimla" data-testid="schedule-serves" />
            <p className="mt-1 text-xs text-muted-foreground">Quotations for these places suggest this {noun}. The note below tells the customer how to continue.</p></div>
          <div className="grid grid-cols-3 gap-3">
            <div><Label className="label-caps">Departs</Label><Input className="mt-1.5" type="time" value={f.departs} onChange={(e) => setF({ ...f, departs: e.target.value })} data-testid="schedule-departs" /></div>
            <div><Label className="label-caps">Arrives</Label><Input className="mt-1.5" type="time" value={f.arrives} onChange={(e) => setF({ ...f, arrives: e.target.value })} data-testid="schedule-arrives" /></div>
            <div><Label className="label-caps">{kind === "flight" ? "Class" : "Classes available"}</Label><Input className="mt-1.5" value={f.classes} onChange={(e) => setF({ ...f, classes: e.target.value })} placeholder={kind === "flight" ? "Economy" : "CC, EC"} /></div>
          </div>
          <label className="flex cursor-pointer items-center gap-2 text-sm"><input type="checkbox" checked={f.arrives_next_day} onChange={(e) => setF({ ...f, arrives_next_day: e.target.checked })} className="accent-black dark:accent-white" /> Arrives the next day</label>
          <div>
            <Label className="label-caps">Runs on</Label>
            <div className="mt-1.5 flex flex-wrap gap-1.5" data-testid="schedule-days">
              {DAYS.map((d) => <button key={d.k} type="button" onClick={() => toggle(d.k)} aria-pressed={f.days.includes(d.k)} className={cn("rounded-md border px-3 py-1.5 text-sm", f.days.includes(d.k) ? "border-foreground bg-foreground text-background" : "border-border text-muted-foreground hover:bg-accent")} data-testid={`day-${d.k}`}>{d.l}</button>)}
              <button type="button" className="px-2 text-xs underline" onClick={() => setF({ ...f, days: ALL_DAYS })}>Every day</button>
            </div>
          </div>
          <div><Label className="label-caps">Note (optional)</Label><Textarea className="mt-1.5" rows={2} value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} placeholder="Arrives at Kalka. Taxi or bus onward to Shimla or Manali" /></div>
          <p className="text-xs text-muted-foreground">Details only. Nothing here is calculated or priced.</p>
          <DialogFooter className="gap-2 sm:gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={busy}>Close</Button>
            {!item && <Button type="button" variant="outline" disabled={busy || f.days.length === 0} onClick={(e) => save(e as unknown as FormEvent, true)} data-testid="schedule-save-another">Save and add another</Button>}
            <Button type="submit" disabled={busy || f.days.length === 0} data-testid="schedule-save">{busy ? "Saving…" : "Save"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

interface ImportResult { dry_run: boolean; new: number; updated: number; errors: { row: number; error: string }[] }

function ImportDialog({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [res, setRes] = useState<ImportResult | null>(null);
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => { if (open) { setFile(null); setRes(null); setBusy(false); } }, [open]);
  const template = async () => {
    try { const r = await api.get("/transport/schedules/import-template", { responseType: "blob" }); const a = document.createElement("a"); a.href = URL.createObjectURL(r.data as Blob); a.download = "flights-trains-template.csv"; a.click(); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  const run = async (dry: boolean) => {
    if (!file) return; setBusy(true);
    try {
      const fd = new FormData(); fd.append("file", file); fd.append("dry_run", dry ? "1" : "0");
      const { data } = await api.post<ImportResult>("/transport/schedules/import", fd); setRes(data);
      if (!dry) { toast.success(`Imported: ${data.new} new and ${data.updated} updated`); onDone(); onClose(); }
    } catch (e) { toast.error(formatApiError(e)); } finally { setBusy(false); }
  };
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-xl overflow-y-auto" data-testid="schedule-import-dialog">
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">Import flights and trains</DialogTitle><DialogDescription>One row per flight or train. Rows already in the list are updated, new ones are added. Nothing is saved until you press Import.</DialogDescription></DialogHeader>
        <div className="space-y-3 text-sm">
          <Button type="button" variant="outline" size="sm" onClick={template} data-testid="schedule-template"><Download size={14} /> Download the template (CSV)</Button>
          <p className="text-xs text-muted-foreground">Type is flight or train. Days: Daily, Daily except Sun, or a list like Mon, Wed, Fri. Times like 07:40. There is no price column. Save an Excel sheet as .xlsx or .csv.</p>
          <div>
            <input ref={input} type="file" accept=".csv,.xlsx" className="hidden" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setRes(null); }} data-testid="schedule-import-file" />
            <Button type="button" variant="outline" onClick={() => input.current?.click()}><Upload size={14} /> {file ? file.name : "Choose the file"}</Button>
          </div>
          {res && (
            <div className="rounded-md border border-border p-3" data-testid="schedule-import-result">
              <div className="font-medium">{res.dry_run ? "Check: nothing has been saved yet" : "Done"}</div>
              <div className="mt-1 text-xs text-muted-foreground">{res.new} new · {res.updated} to update · {res.errors.length} row{res.errors.length === 1 ? "" : "s"} with a problem</div>
              {res.errors.length > 0 && <ul className="mt-2 max-h-36 list-disc space-y-0.5 overflow-y-auto pl-5 text-xs text-red-700 dark:text-red-400">{res.errors.map((e, i) => <li key={i}>Row {e.row}: {e.error}</li>)}</ul>}
            </div>
          )}
        </div>
        <DialogFooter className="gap-2 sm:gap-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button type="button" variant="outline" onClick={() => run(true)} disabled={!file || busy} data-testid="schedule-import-check">{busy ? <Loader2 size={14} className="animate-spin" /> : null} Check first</Button>
          <Button type="button" onClick={() => run(false)} disabled={!file || busy || (res?.dry_run === true && res.new + res.updated === 0)} data-testid="schedule-import-go">Import</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default function ScheduleList({ kind }: { kind: ScheduleKind }) {
  const { can } = useCurrentUser();
  const manage = can("transport.manage");
  const flight = kind === "flight";
  const Icon = flight ? Plane : Train;
  const [rows, setRows] = useState<TransportSchedule[] | null>(null);
  const [routes, setRoutes] = useState<string[]>([]);
  const [q, setQ] = useState("");
  const [route, setRoute] = useState("all");
  const [stale, setStale] = useState(false);
  const [showOff, setShowOff] = useState(false);
  const [dlg, setDlg] = useState<{ open: boolean; item: TransportSchedule | null }>({ open: false, item: null });
  const [importing, setImporting] = useState(false);
  const [menu, setMenu] = useState<string | null>(null);

  const load = useCallback(() => {
    api.get<TransportSchedule[]>("/transport/schedules", { params: { kind, active: showOff ? "0" : "1" } }).then((r) => setRows(r.data)).catch((e) => toast.error(formatApiError(e)));
    api.get<string[]>("/transport/schedules/routes", { params: { kind } }).then((r) => setRoutes(r.data)).catch(() => setRoutes([]));
  }, [kind, showOff]);
  useEffect(() => { load(); }, [load]);

  const needs = useMemo(() => (rows ?? []).filter((r) => r.needs_update).length, [rows]);
  const shown = useMemo(() => (rows ?? []).filter((r) => {
    const t = q.trim().toLowerCase();
    return (route === "all" || `${r.from_name} → ${r.to_name}` === route) && (!stale || r.needs_update)
      && (!t || [r.title, r.from_name, r.to_name, r.from_code, r.to_code, r.serves].some((x) => (x ?? "").toLowerCase().includes(t)));
  }), [rows, q, route, stale]);

  const del = async (s: TransportSchedule) => { if (!window.confirm(`Delete ${s.title}?`)) return; try { await api.delete(`/transport/schedules/${s.id}`); load(); } catch (e) { toast.error(formatApiError(e)); } };
  const dup = async (s: TransportSchedule) => { try { await api.post(`/transport/schedules/${s.id}/duplicate`); toast.success("Duplicated. Edit the copy."); load(); } catch (e) { toast.error(formatApiError(e)); } };

  return (
    <div className="space-y-4" data-testid={`${kind}s-page`}>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="label-caps">Operations</div>
          <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Transport</h1>
        </div>
        {manage && (
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => setImporting(true)} data-testid="schedule-import-btn"><Upload size={15} /> Import from Excel</Button>
            <Button onClick={() => setDlg({ open: true, item: null })} data-testid="schedule-add-btn">+ Add {kind}</Button>
          </div>
        )}
      </div>
      <TransportTabs active={flight ? "flights" : "trains"} />

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[220px] max-w-[320px] flex-1">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={flight ? "Search route or airline" : "Search route or train name"} className="h-9 pl-9" data-testid="schedule-search" />
        </div>
        <Select value={route} onValueChange={setRoute}>
          <SelectTrigger className="h-9 w-[230px]" data-testid="schedule-route-filter"><SelectValue placeholder="All routes" /></SelectTrigger>
          <SelectContent><SelectItem value="all">All routes</SelectItem>{routes.map((r) => <SelectItem key={r} value={r}>{r}</SelectItem>)}</SelectContent>
        </Select>
        <button type="button" onClick={() => setStale((s) => !s)} aria-pressed={stale} className={cn("flex h-9 items-center gap-1.5 rounded-md border px-3 text-sm", stale ? "border-amber-500 bg-amber-500/10" : "border-border text-muted-foreground")} data-testid="schedule-needs-update">
          <AlertTriangle size={14} /> Needs update ({needs})
        </button>
        {manage && <label className="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground"><input type="checkbox" checked={showOff} onChange={(e) => setShowOff(e.target.checked)} className="accent-black dark:accent-white" /> Show switched-off</label>}
        <span className="text-xs text-muted-foreground">{shown.length} {kind}{shown.length === 1 ? "" : "s"}</span>
      </div>

      {rows === null && <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>}
      {rows && shown.length === 0 && <Card className="rounded-lg p-10 text-center text-sm text-muted-foreground" data-testid="schedules-empty">{rows.length === 0 ? (manage ? `No ${kind}s yet. Add one, or import your list from Excel.` : `No ${kind}s yet. Operations adds them.`) : `No ${kind}s match these filters.`}</Card>}
      {shown.length > 0 && (
        <Card className="overflow-x-auto rounded-lg">
          <table className="w-full min-w-[760px] text-sm">
            <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
              <th className="p-3">{flight ? "Flight" : "Train"}</th><th className="p-3">Route</th><th className="p-3">Time</th><th className="p-3">Runs on</th><th className="p-3">Class</th><th className="p-3">Updated</th>{manage && <th className="w-10 p-3" />}
            </tr></thead>
            <tbody>
              {shown.map((s) => (
                <tr key={s.id} className={cn("border-b border-border/60 last:border-0", !s.active && "opacity-60")} data-testid={`schedule-row-${s.id}`}>
                  <td className="p-3 font-medium"><span className="flex items-center gap-2"><Icon size={14} className="shrink-0 text-muted-foreground" />{s.title}</span>{s.note && <div className="ml-6 max-w-[260px] truncate text-xs font-normal text-muted-foreground" title={s.note}>{s.note}</div>}</td>
                  <td className="p-3">{place(s.from_name, s.from_code)} → {place(s.to_name, s.to_code)}{s.serves && <div className="text-xs text-muted-foreground">Reaches {s.serves}</div>}</td>
                  <td className="p-3 tabular-nums">{timeOf(s) || "–"}</td>
                  <td className="p-3">{s.runs_on}</td>
                  <td className="p-3">{s.classes ?? "–"}</td>
                  <td className="p-3 text-xs">{s.updated_at ? fmtDate(s.updated_at) : "–"}{s.needs_update && <span className="ml-1.5 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">Needs update</span>}</td>
                  {manage && (
                    <td className="relative p-3 text-right">
                      <button type="button" aria-label="Actions" onClick={() => setMenu(menu === s.id ? null : s.id)} className="rounded p-1 hover:bg-accent" data-testid={`schedule-menu-${s.id}`}><MoreHorizontal size={16} /></button>
                      {menu === s.id && (
                        <div className="absolute right-2 top-10 z-10 w-36 rounded-md border border-border bg-background p-1 text-left shadow-md">
                          <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent" onClick={() => { setMenu(null); setDlg({ open: true, item: s }); }} data-testid={`schedule-edit-${s.id}`}><Pencil size={13} /> Edit</button>
                          <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent" onClick={() => { setMenu(null); void dup(s); }}><Copy size={13} /> Duplicate</button>
                          <button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm text-red-600 hover:bg-accent" onClick={() => { setMenu(null); void del(s); }}><Trash2 size={13} /> Delete</button>
                        </div>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
      <p className="text-xs text-muted-foreground">Details only: no prices are stored or shown. Customers see these as suggestions and are told to confirm timings at booking.</p>
      {manage && <ScheduleDialog kind={kind} open={dlg.open} item={dlg.item} onClose={() => setDlg({ open: false, item: null })} onSaved={load} />}
      {manage && <ImportDialog open={importing} onClose={() => setImporting(false)} onDone={load} />}
    </div>
  );
}
