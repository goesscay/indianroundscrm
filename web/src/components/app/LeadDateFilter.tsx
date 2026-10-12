"use client";

import { useState } from "react";
import { CalendarDays } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { cn } from "@/lib/utils";

export type DateField = "created" | "travel";
export interface DateFilter { field: DateField; from: string; to: string }
export const NO_DATES: DateFilter = { field: "created", from: "", to: "" };

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const monthStart = (d: Date, offset = 0) => new Date(d.getFullYear(), d.getMonth() + offset, 1);
const monthEnd = (d: Date, offset = 0) => new Date(d.getFullYear(), d.getMonth() + offset + 1, 0);

interface Preset { label: string; range: () => [Date, Date] }

const PRESETS: Record<DateField, Preset[]> = {
  created: [
    { label: "Today", range: () => [new Date(), new Date()] },
    { label: "Yesterday", range: () => { const y = addDays(new Date(), -1); return [y, y]; } },
    { label: "Last 7 days", range: () => [addDays(new Date(), -6), new Date()] },
    { label: "This month", range: () => [monthStart(new Date()), new Date()] },
    { label: "Last month", range: () => [monthStart(new Date(), -1), monthEnd(new Date(), -1)] },
  ],
  travel: [
    { label: "Today", range: () => [new Date(), new Date()] },
    { label: "Next 7 days", range: () => [new Date(), addDays(new Date(), 6)] },
    { label: "Next 30 days", range: () => [new Date(), addDays(new Date(), 29)] },
    { label: "This month", range: () => [monthStart(new Date()), monthEnd(new Date())] },
    { label: "Next month", range: () => [monthStart(new Date(), 1), monthEnd(new Date(), 1)] },
  ],
};

const FIELD_LABEL: Record<DateField, string> = { created: "Created date", travel: "Travel date" };

const short = (d: string) => (d ? new Date(d + "T00:00:00").toLocaleDateString("en-IN", { day: "numeric", month: "short" }) : "");

/** One-line summary of an applied filter, e.g. "Created 1 Oct – 12 Oct". */
export function describeDates(v: DateFilter): string {
  if (!v.from && !v.to) return "";
  const range = v.from && v.to ? (v.from === v.to ? short(v.from) : `${short(v.from)} – ${short(v.to)}`) : v.from ? `from ${short(v.from)}` : `until ${short(v.to)}`;
  return `${v.field === "created" ? "Created" : "Travel"} ${range}`;
}

/** An icon button that opens a small popup for the date filters. Nothing is applied until "Apply". */
export default function LeadDateFilter({ value, onApply }: { value: DateFilter; onApply: (v: DateFilter) => void }) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<DateFilter>(value);
  const active = !!(value.from || value.to);
  const invalid = !!draft.from && !!draft.to && draft.from > draft.to;

  const show = (o: boolean) => {
    if (o) setDraft(value);   // always start from what is applied now
    setOpen(o);
  };
  const apply = (v: DateFilter) => { onApply(v); setOpen(false); };

  return (
    <>
      <Button
        variant={active ? "default" : "outline"} size="sm" className="h-9 gap-1.5" onClick={() => show(true)}
        title={active ? describeDates(value) : "Filter by date"} aria-label="Date filters" data-testid="date-filter-btn"
      >
        <CalendarDays size={15} />
        {active ? <span className="max-w-[170px] truncate text-xs" data-testid="date-filter-summary">{describeDates(value)}</span> : <span className="hidden text-xs sm:inline">Dates</span>}
      </Button>

      <Dialog open={open} onOpenChange={show}>
        <DialogContent className="sm:max-w-md" data-testid="date-dialog">
          <DialogHeader>
            <DialogTitle className="font-display">Filter by date</DialogTitle>
            <DialogDescription>Show only leads whose date falls in this range.</DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div>
              <Label className="label-caps">Date</Label>
              <div className="mt-1.5 grid grid-cols-2 gap-1 rounded-md bg-secondary p-1" role="group" aria-label="Which date">
                {(Object.keys(FIELD_LABEL) as DateField[]).map((f) => (
                  <button
                    key={f} type="button" aria-pressed={draft.field === f}
                    onClick={() => setDraft({ ...draft, field: f })}
                    className={cn("rounded px-3 py-1.5 text-sm font-medium transition-colors", draft.field === f ? "bg-background shadow-sm" : "text-muted-foreground hover:text-foreground")}
                    data-testid={`date-field-${f}`}
                  >{FIELD_LABEL[f]}</button>
                ))}
              </div>
            </div>

            <div>
              <Label className="label-caps">Quick ranges</Label>
              <div className="mt-1.5 flex flex-wrap gap-1.5">
                {PRESETS[draft.field].map((p) => (
                  <Button
                    key={p.label} type="button" size="sm" variant="outline"
                    onClick={() => { const [a, b] = p.range(); setDraft({ ...draft, from: iso(a), to: iso(b) }); }}
                    data-testid={`date-preset-${p.label.toLowerCase().replace(/\s+/g, "-")}`}
                  >{p.label}</Button>
                ))}
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label htmlFor="dd-from" className="label-caps">From</Label>
                <Input id="dd-from" type="date" className="mt-1.5" value={draft.from} max={draft.to || undefined} onChange={(e) => setDraft({ ...draft, from: e.target.value })} data-testid="date-from" />
              </div>
              <div>
                <Label htmlFor="dd-to" className="label-caps">To</Label>
                <Input id="dd-to" type="date" className="mt-1.5" value={draft.to} min={draft.from || undefined} onChange={(e) => setDraft({ ...draft, to: e.target.value })} data-testid="date-to" />
              </div>
            </div>
            {invalid && <div className="text-sm text-destructive" role="alert">The “From” date is after the “To” date.</div>}
          </div>

          <DialogFooter className="gap-2 sm:gap-2">
            {active && <Button type="button" variant="ghost" className="mr-auto" onClick={() => apply(NO_DATES)} data-testid="date-clear">Clear dates</Button>}
            <Button type="button" variant="outline" onClick={() => setOpen(false)} data-testid="date-cancel">Cancel</Button>
            <Button type="button" onClick={() => apply(draft)} disabled={invalid || (!draft.from && !draft.to)} data-testid="date-apply">Apply</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
