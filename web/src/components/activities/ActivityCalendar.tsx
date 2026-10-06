"use client";

import { useEffect, useMemo, useState } from "react";
import { ChevronLeft, ChevronRight, Plane } from "lucide-react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import type { Activity, CalendarEvent } from "@/types";
import { PRIORITY_DOT, dayKey, typeMeta } from "./shared";

const WEEKDAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
const MAX_CHIPS = 3;

interface Props {
  filters: Record<string, string>; // q / type / owner
  refreshTick: number;
  canCreate: boolean;
  onOpen: (a: Activity) => void;
  onCreate: (dueLocal: string) => void;
  /** Show the "Trips" layer: lead travel dates. Needs the calendar.view permission. */
  canTrips?: boolean;
}

const TRIPS_KEY = "ir-cal-trips";
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

export default function ActivityCalendar({ filters, refreshTick, canCreate, onOpen, onCreate, canTrips }: Props) {
  const router = useRouter();
  const [showTrips, setShowTrips] = useState(true);
  const [trips, setTrips] = useState<CalendarEvent[]>([]);
  useEffect(() => { try { if (localStorage.getItem(TRIPS_KEY) === "0") setShowTrips(false); } catch { /* storage blocked */ } }, []);
  const toggleTrips = () => setShowTrips((v) => { try { localStorage.setItem(TRIPS_KEY, v ? "0" : "1"); } catch { /* ignore */ } return !v; });
  const [cursor, setCursor] = useState(() => { const d = new Date(); d.setDate(1); d.setHours(0, 0, 0, 0); return d; });
  const [items, setItems] = useState<Activity[]>([]);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState<string | null>(null);

  // Visible grid: Monday-first weeks covering the month.
  const { gridStart, weeks, days } = useMemo(() => {
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7;
    const dim = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
    const w = Math.ceil((offset + dim) / 7);
    const start = new Date(first.getFullYear(), first.getMonth(), 1 - offset);
    const list = Array.from({ length: w * 7 }, (_, i) => new Date(start.getFullYear(), start.getMonth(), start.getDate() + i));
    return { gridStart: start, weeks: w, days: list };
  }, [cursor]);

  const key = JSON.stringify(filters);
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    const end = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + weeks * 7);
    api.get<Activity[]>("/activities/calendar", { params: { ...filters, from: gridStart.toISOString(), to: end.toISOString() } })
      .then((r) => { if (!cancelled) setItems(Array.isArray(r.data) ? r.data : []); })
      .catch((e) => { if (!cancelled) toast.error(formatApiError(e)); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gridStart, weeks, key, refreshTick]);

  useEffect(() => {
    if (!canTrips || !showTrips) { setTrips([]); return; }
    let cancelled = false;
    const end = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + weeks * 7);
    api.get<CalendarEvent[]>("/leads/calendar", { params: { from: ymd(gridStart), to: ymd(end) } })
      .then((r) => { if (!cancelled) setTrips(Array.isArray(r.data) ? r.data : []); })
      .catch(() => { if (!cancelled) setTrips([]); });
    return () => { cancelled = true; };
  }, [gridStart, weeks, canTrips, showTrips, refreshTick]);

  const tripsByDay = useMemo(() => {
    const g: Record<string, CalendarEvent[]> = {};
    for (const t of trips) (g[t.date] ||= []).push(t);
    return g;
  }, [trips]);

  const byDay = useMemo(() => {
    const g: Record<string, Activity[]> = {};
    for (const a of items) {
      if (!a.due_at) continue;
      (g[dayKey(new Date(a.due_at))] ||= []).push(a);
    }
    for (const k of Object.keys(g)) g[k].sort((x, y) => (x.due_at ?? "").localeCompare(y.due_at ?? ""));
    return g;
  }, [items]);

  const todayKey = dayKey(new Date());
  const shift = (delta: number) => setCursor((c) => new Date(c.getFullYear(), c.getMonth() + delta, 1));
  const goToday = () => { const d = new Date(); setCursor(new Date(d.getFullYear(), d.getMonth(), 1)); };
  const title = cursor.toLocaleDateString(undefined, { month: "long", year: "numeric" });

  return (
    <div className="space-y-3" data-testid="activities-calendar">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <div className="font-display font-bold text-lg" data-testid="cal-month" aria-live="polite">{title}</div>
        <div className="flex items-center gap-2">
          {canTrips && (
            <Button variant={showTrips ? "default" : "outline"} onClick={toggleTrips} aria-pressed={showTrips} data-testid="cal-trips-toggle"><Plane size={14} className="mr-1" /> Trips</Button>
          )}
          <Button variant="outline" size="icon" onClick={() => shift(-1)} aria-label="Previous month" data-testid="cal-prev"><ChevronLeft size={16} /></Button>
          <Button variant="outline" onClick={goToday} data-testid="cal-today">Today</Button>
          <Button variant="outline" size="icon" onClick={() => shift(1)} aria-label="Next month" data-testid="cal-next"><ChevronRight size={16} /></Button>
        </div>
      </div>

      <Card className={cn("rounded-lg overflow-hidden transition-opacity", loading && "opacity-70")}>
        <div className="overflow-x-auto">
          <div className="min-w-[760px]">
            <div className="grid grid-cols-7 border-b border-border bg-secondary/40">
              {WEEKDAYS.map((d) => <div key={d} className="label-caps text-center py-2">{d}</div>)}
            </div>
            <div className="grid grid-cols-7">
              {days.map((d, i) => {
                const k = dayKey(d);
                const inMonth = d.getMonth() === cursor.getMonth();
                const isToday = k === todayKey;
                const dayTrips = tripsByDay[k] ?? [];
                const list = byDay[k] ?? [];
                const total = dayTrips.length + list.length;
                const open = expanded === k;
                const shownTrips = open ? dayTrips : dayTrips.slice(0, MAX_CHIPS);
                const shown = open ? list : list.slice(0, Math.max(0, MAX_CHIPS - shownTrips.length));
                const hidden = total - shownTrips.length - shown.length;
                return (
                  <div
                    key={k}
                    onClick={() => { if (canCreate) onCreate(`${k}T09:00`); }}
                    className={cn(
                      "min-h-[112px] border-b border-r border-border p-1.5 transition-colors",
                      (i + 1) % 7 === 0 && "border-r-0",
                      !inMonth && "bg-secondary/30",
                      canCreate && "cursor-pointer hover:bg-secondary/50"
                    )}
                    data-testid={`cal-cell-${k}`}
                  >
                    <div className="flex justify-end mb-1">
                      <span
                        className={cn(
                          "inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-xs font-semibold",
                          isToday ? "bg-primary text-primary-foreground" : inMonth ? "text-foreground" : "text-muted-foreground/60"
                        )}
                        aria-current={isToday ? "date" : undefined}
                      >{d.getDate()}</span>
                    </div>
                    <div className="space-y-1">
                      {shownTrips.map((t, ti) => (
                        <button
                          key={`${t.lead_id}-${ti}`} type="button"
                          onClick={(e) => { e.stopPropagation(); router.push(`/leads/${t.lead_id}`); }}
                          title={`Trip · ${t.customer_name} · ${t.destination} (${t.lead_status})`}
                          className="flex w-full items-center gap-1 rounded border border-transparent bg-[#0A0A0A] px-1.5 py-0.5 text-left text-[11px] leading-tight text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:bg-white dark:text-neutral-900"
                          data-testid={`cal-trip-${t.lead_id}`}
                        >
                          <Plane size={10} className="shrink-0" aria-hidden />
                          <span className="truncate">{t.customer_name} · {t.destination}</span>
                        </button>
                      ))}
                      {shown.map((a) => {
                        const m = typeMeta(a.type);
                        const Icon = m.icon;
                        return (
                          <button
                            key={a.id}
                            type="button"
                            onClick={(e) => { e.stopPropagation(); onOpen(a); }}
                            title={`${a.title} (${m.label}${a.assigned_to ? `, ${a.assigned_to.name}` : ""})`}
                            className={cn(
                              "flex w-full items-center gap-1 rounded border px-1.5 py-0.5 text-left text-[11px] leading-tight focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                              m.chip,
                              a.done && "opacity-50 line-through",
                              a.overdue && !a.done && "ring-1 ring-red-400/70 dark:ring-red-500/60"
                            )}
                            data-testid={`cal-chip-${a.id}`}
                          >
                            <span className={cn("h-1.5 w-1.5 shrink-0 rounded-full", PRIORITY_DOT[a.priority])} aria-hidden />
                            <Icon size={10} className="shrink-0" aria-hidden />
                            <span className="truncate">{a.title}</span>
                          </button>
                        );
                      })}
                      {(hidden > 0 || (open && total > MAX_CHIPS)) && (
                        <button
                          type="button"
                          onClick={(e) => { e.stopPropagation(); setExpanded(open ? null : k); }}
                          className="px-1 text-[11px] font-medium text-muted-foreground hover:text-foreground hover:underline"
                          data-testid={`cal-more-${k}`}
                        >
                          {open ? "Show less" : `+${hidden} more`}
                        </button>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </Card>
    </div>
  );
}
