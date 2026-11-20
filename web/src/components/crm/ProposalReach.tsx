"use client";

import { useEffect, useMemo, useState } from "react";
import { Plane, Plus, Train, Trash2 } from "lucide-react";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { cn } from "@/lib/utils";
import type { DayKey, ProposalData, ReachItem, ScheduleKind, TransportSchedule } from "@/types";

const DAYS: { k: DayKey; l: string }[] = [{ k: "mon", l: "Mon" }, { k: "tue", l: "Tue" }, { k: "wed", l: "Wed" }, { k: "thu", l: "Thu" }, { k: "fri", l: "Fri" }, { k: "sat", l: "Sat" }, { k: "sun", l: "Sun" }];
const ALL = DAYS.map((d) => d.k);
type SetFn = (fn: (d: ProposalData) => void) => void;

const runsOn = (days: DayKey[]) => {
  if (days.length === 7) return "Daily";
  if (days.length >= 5) return "Daily except " + DAYS.filter((d) => !days.includes(d.k)).map((d) => d.l).join(", ");
  return DAYS.filter((d) => days.includes(d.k)).map((d) => d.l).join(", ") || "No days set";
};
const timeOf = (x: { departs: string | null; arrives: string | null; arrives_next_day: boolean }) => [x.departs, x.arrives].filter(Boolean).join(" → ") + (x.arrives && x.arrives_next_day ? " (+1)" : "");
const toItem = (s: TransportSchedule): ReachItem => ({ schedule_id: s.id, kind: s.kind, title: s.title, from_name: s.from_name, from_code: s.from_code, to_name: s.to_name, to_code: s.to_code, departs: s.departs, arrives: s.arrives,
  arrives_next_day: s.arrives_next_day, days: s.days, classes: s.classes, note: s.note });
const Badge = ({ kind }: { kind: ScheduleKind }) => <span className={cn("inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide", kind === "flight" ? "bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300" : "bg-secondary text-muted-foreground")}>{kind === "flight" ? <Plane size={10} /> : <Train size={10} />}{kind}</span>;

const BLANK = { kind: "flight" as ScheduleKind, title: "", from_name: "", to_name: "", departs: "", arrives: "", classes: "", note: "", days: ALL };

/** Flights and trains on a quotation: suggestions only. The list is already filtered by route and travel date; sales tick the ones to share. No prices, nothing calculated. */
export default function ProposalReach({ p, set, kind }: { p: ProposalData; set: SetFn; kind?: ScheduleKind }) {
  const [rows, setRows] = useState<TransportSchedule[]>([]);
  const [origins, setOrigins] = useState<string[]>([]);
  const [from, setFrom] = useState(p.reach?.from ?? "");
  const [to, setTo] = useState("");
  const [show, setShow] = useState<"all" | ScheduleKind>(kind ?? "all");
  const [adding, setAdding] = useState(false);
  const [f, setF] = useState({ ...BLANK, kind: kind ?? BLANK.kind });
  const items = (p.reach?.items ?? []).filter((x) => !kind || x.kind === kind);       // inside a Flights or Trains tab only that kind is shown and counted
  const date = p.trip.start_date ?? "";

  useEffect(() => { api.get<string[]>("/transport/schedules/origins").then((r) => setOrigins(r.data)).catch(() => setOrigins([])); }, []);
  // the destination follows the trip until the salesperson types another
  const toValue = to !== "" ? to : p.trip.destination;
  useEffect(() => {
    const t = setTimeout(() => {
      api.get<TransportSchedule[]>("/transport/schedules", { params: { from: from || undefined, to: toValue || undefined, date: date || undefined } }).then((r) => setRows(r.data)).catch(() => setRows([]));
    }, 300);
    return () => clearTimeout(t);
  }, [from, toValue, date]);

  const ticked = (id: string) => items.some((x) => String(x.schedule_id) === id);
  const toggle = (s: TransportSchedule) => set((d) => {
    d.reach ??= { from: null, items: [] };
    const i = d.reach.items.findIndex((x) => String(x.schedule_id) === s.id);
    if (i >= 0) d.reach.items.splice(i, 1); else if (d.reach.items.length < 12) d.reach.items.push(toItem(s));
  });
  const shownRows = useMemo(() => rows.filter((r) => (kind ?? show) === "all" || r.kind === (kind ?? show)), [rows, show, kind]);
  const listedIds = new Set(rows.map((r) => r.id));
  const outside = items.filter((x) => x.schedule_id != null && !listedIds.has(String(x.schedule_id)));
  const custom = items.filter((x) => x.schedule_id == null);

  const addCustom = () => {
    if (!f.title.trim() || !f.from_name.trim() || !f.to_name.trim()) return;
    set((d) => {
      d.reach ??= { from: null, items: [] };
      if (d.reach.items.length < 12) d.reach.items.push({ schedule_id: null, kind: f.kind, title: f.title.trim(), from_name: f.from_name.trim(), from_code: null, to_name: f.to_name.trim(), to_code: null, departs: f.departs || null, arrives: f.arrives || null,
        arrives_next_day: false, days: f.days.length ? f.days : ALL, classes: f.classes || null, note: f.note || null });
    });
    setF({ ...BLANK, kind: kind ?? BLANK.kind, from_name: from }); setAdding(false);
  };

  return (
    <div className="space-y-3" data-testid="proposal-reach">
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-[170px]"><Label className="text-xs">From</Label>
          <Input list="reach-origins" value={from} onChange={(e) => { setFrom(e.target.value); set((d) => { d.reach ??= { from: null, items: [] }; d.reach.from = e.target.value || null; }); }} placeholder="Delhi" className="mt-1 h-9" data-testid="reach-from" />
          <datalist id="reach-origins">{origins.map((o) => <option key={o} value={o} />)}</datalist></div>
        <div className="w-[170px]"><Label className="text-xs">To</Label><Input value={toValue} onChange={(e) => setTo(e.target.value)} className="mt-1 h-9" data-testid="reach-to" /></div>
        <div className="w-[150px]"><Label className="text-xs">Travel date</Label><Input type="date" value={date} onChange={(e) => set((d) => { d.trip.start_date = e.target.value || null; })} className="mt-1 h-9" /></div>
        {!kind && <div className="w-[170px]"><Label className="text-xs">Show</Label>
          <Select value={show} onValueChange={(v) => setShow(v as "all" | ScheduleKind)}><SelectTrigger className="mt-1 h-9" data-testid="reach-show"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem value="all">Flights and trains</SelectItem><SelectItem value="flight">Flights only</SelectItem><SelectItem value="train">Trains only</SelectItem></SelectContent></Select></div>}
      </div>

      {shownRows.length === 0 && outside.length === 0 ? (
        <p className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground" data-testid="reach-empty">{date ? "Nothing in the list runs on this route and day." : "Nothing in the list for this route yet."} You can add one for this quote only below.</p>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full min-w-[640px] text-sm">
            <thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="w-14 p-2.5">Share</th><th className="p-2.5">Type</th><th className="p-2.5">Flight or train</th><th className="p-2.5">Time</th><th className="p-2.5">Runs on</th></tr></thead>
            <tbody>
              {[...shownRows, ...outside.map((x) => ({ ...x, id: String(x.schedule_id), runs_on: runsOn(x.days), outside: true }) as unknown as TransportSchedule & { outside: boolean })].map((s) => (
                <tr key={s.id} className="border-b last:border-0" data-testid={`reach-row-${s.id}`}>
                  <td className="p-2.5"><input type="checkbox" checked={ticked(s.id)} onChange={() => toggle(s)} aria-label={`Share ${s.title}`} className="h-4 w-4 accent-black dark:accent-white" data-testid={`reach-tick-${s.id}`} /></td>
                  <td className="p-2.5"><Badge kind={s.kind} /></td>
                  <td className="p-2.5"><div className="font-medium">{s.title}</div>
                    <div className="text-xs text-muted-foreground">{s.from_name} → {s.to_name}{s.from_code && s.to_code ? ` (${s.from_code} → ${s.to_code})` : ""}{s.note ? ` · ${s.note}` : ""}</div>
                    {"outside" in s && <div className="text-xs text-amber-700 dark:text-amber-300">Chosen earlier. It does not match the route or date shown now.</div>}</td>
                  <td className="p-2.5 tabular-nums">{timeOf(s) || "–"}</td>
                  <td className="p-2.5">{s.runs_on}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {custom.length > 0 && (
        <div className="space-y-1.5" data-testid="reach-custom">
          <div className="text-xs font-medium text-muted-foreground">Typed for this quote only</div>
          {custom.map((x) => (
            <div key={`${x.kind}-${x.title}-${x.from_name}`} className="flex items-center justify-between gap-2 rounded-md bg-muted/40 px-2.5 py-2 text-sm">
              <span className="flex items-center gap-2"><Badge kind={x.kind} /><span className="font-medium">{x.title}</span><span className="text-xs text-muted-foreground">{x.from_name} → {x.to_name}{timeOf(x) ? ` · ${timeOf(x)}` : ""} · {runsOn(x.days)}</span></span>
              <button type="button" aria-label="Remove" className="rounded p-1.5 text-muted-foreground hover:bg-accent" onClick={() => set((d) => { const i = d.reach?.items.findIndex((y) => y.schedule_id == null && y.title === x.title && y.from_name === x.from_name); if (d.reach && i != null && i >= 0) d.reach.items.splice(i, 1); })}><Trash2 size={14} /></button>
            </div>
          ))}
        </div>
      )}

      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm" data-testid="reach-count">{items.length === 0 ? "Nothing is shared with the customer yet." : `${items.length} ${kind ? (items.length === 1 ? kind : `${kind}s`) : items.length === 1 ? "suggestion" : "suggestions"} will appear on the customer's quotation.`}</p>
        <button type="button" className="text-sm underline" onClick={() => { setF({ ...BLANK, kind: kind ?? BLANK.kind, from_name: from, to_name: toValue }); setAdding((a) => !a); }} data-testid="reach-add-custom">Not on the list? Add {kind ? `a ${kind}` : "a flight or train"} typed for this quote only</button>
      </div>

      {adding && (
        <div className="space-y-2 rounded-md border p-3" data-testid="reach-custom-form">
          <div className={cn("grid gap-2", kind ? "sm:grid-cols-[1.4fr_1fr_1fr]" : "sm:grid-cols-[110px_1.4fr_1fr_1fr]")}>
            {!kind && <div><Label className="text-xs">Type</Label><Select value={f.kind} onValueChange={(v) => setF({ ...f, kind: v as ScheduleKind })}><SelectTrigger className="mt-1 h-9"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="flight">Flight</SelectItem><SelectItem value="train">Train</SelectItem></SelectContent></Select></div>}
            <div><Label className="text-xs">Name and number</Label><Input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} className="mt-1 h-9" placeholder="IndiGo 6E 999" data-testid="reach-c-title" /></div>
            <div><Label className="text-xs">From</Label><Input value={f.from_name} onChange={(e) => setF({ ...f, from_name: e.target.value })} className="mt-1 h-9" data-testid="reach-c-from" /></div>
            <div><Label className="text-xs">To</Label><Input value={f.to_name} onChange={(e) => setF({ ...f, to_name: e.target.value })} className="mt-1 h-9" data-testid="reach-c-to" /></div>
          </div>
          <div className="grid gap-2 sm:grid-cols-4">
            <div><Label className="text-xs">Departs</Label><Input type="time" value={f.departs} onChange={(e) => setF({ ...f, departs: e.target.value })} className="mt-1 h-9" /></div>
            <div><Label className="text-xs">Arrives</Label><Input type="time" value={f.arrives} onChange={(e) => setF({ ...f, arrives: e.target.value })} className="mt-1 h-9" /></div>
            <div><Label className="text-xs">Class</Label><Input value={f.classes} onChange={(e) => setF({ ...f, classes: e.target.value })} className="mt-1 h-9" /></div>
            <div><Label className="text-xs">Note</Label><Input value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} className="mt-1 h-9" placeholder="Then a road transfer" /></div>
          </div>
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="mr-1 text-xs text-muted-foreground">Runs on</span>
            {DAYS.map((d) => <button key={d.k} type="button" aria-pressed={f.days.includes(d.k)} onClick={() => setF({ ...f, days: f.days.includes(d.k) ? f.days.filter((x) => x !== d.k) : [...f.days, d.k] })} className={cn("rounded border px-2 py-1 text-xs", f.days.includes(d.k) ? "border-foreground bg-foreground text-background" : "text-muted-foreground")}>{d.l}</button>)}
            <div className="ml-auto flex gap-2"><Button type="button" size="sm" variant="outline" onClick={() => setAdding(false)}>Cancel</Button><Button type="button" size="sm" onClick={addCustom} disabled={!f.title.trim() || !f.from_name.trim() || !f.to_name.trim()} data-testid="reach-c-add"><Plus size={14} /> Add to quotation</Button></div>
          </div>
          <p className="text-xs text-muted-foreground">Details only. There is no price field, and this is not added to the transport master.</p>
        </div>
      )}
    </div>
  );
}
