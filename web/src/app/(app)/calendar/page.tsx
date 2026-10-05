"use client";

import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import StatusPill from "@/components/app/StatusPill";
import { ChevronLeft, ChevronRight, Plane, Bell } from "lucide-react";
import { toast } from "sonner";
import type { CalendarEvent } from "@/types";

const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];

function ymd(d: Date) { return d.toISOString().slice(0, 10); }

export default function CalendarView() {
  const [cursor, setCursor] = useState(() => { const d = new Date(); d.setDate(1); return d; });
  const [events, setEvents] = useState<CalendarEvent[]>([]);
  const [selected, setSelected] = useState<string | null>(null);

  const monthStr = useMemo(() => `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, "0")}`, [cursor]);

  useEffect(() => {
    (async () => {
      try { const { data } = await api.get<CalendarEvent[]>("/leads/calendar", { params: { month: monthStr } }); setEvents(data); }
      catch (e) { toast.error(formatApiError(e)); }
    })();
  }, [monthStr]);

  const grouped = useMemo(() => {
    const g: Record<string, CalendarEvent[]> = {};
    for (const ev of events) { (g[ev.date] = g[ev.date] || []).push(ev); }
    return g;
  }, [events]);

  // Build calendar grid
  const firstDay = new Date(cursor.getFullYear(), cursor.getMonth(), 1).getDay();
  const daysInMonth = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
  const cells: Array<number | null> = [];
  for (let i = 0; i < firstDay; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(d);
  while (cells.length % 7 !== 0) cells.push(null);

  const today = ymd(new Date());

  const shift = (delta: number) => {
    const d = new Date(cursor); d.setMonth(d.getMonth() + delta); d.setDate(1); setCursor(d);
  };

  const selectedList = selected ? grouped[selected] || [] : [];
  const selLm = useLoadMore(selectedList, 10, selected ?? "");

  return (
    <div className="space-y-6" data-testid="calendar-page">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <div className="label-caps">Schedule</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Calendar</h1>
          <p className="text-sm text-muted-foreground mt-2">Travel dates & follow-ups across your pipeline.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="icon" onClick={() => shift(-1)} data-testid="cal-prev"><ChevronLeft size={16} /></Button>
          <div className="font-display font-bold text-lg min-w-[180px] text-center" data-testid="cal-month">
            {monthNames[cursor.getMonth()]} {cursor.getFullYear()}
          </div>
          <Button variant="outline" size="icon" onClick={() => shift(1)} data-testid="cal-next"><ChevronRight size={16} /></Button>
          <Button variant="outline" onClick={() => { const d = new Date(); d.setDate(1); setCursor(d); }}>Today</Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="p-4 rounded-lg lg:col-span-2">
          <div className="grid grid-cols-7 gap-1 mb-2">
            {["Sun","Mon","Tue","Wed","Thu","Fri","Sat"].map(d => (
              <div key={d} className="label-caps text-center py-1">{d}</div>
            ))}
          </div>
          <div className="grid grid-cols-7 gap-1">
            {cells.map((d, i) => {
              if (!d) return <div key={i} className="min-h-[80px]" />;
              const date = `${monthStr}-${String(d).padStart(2, "0")}`;
              const evs = grouped[date] || [];
              const isToday = date === today;
              const isSelected = date === selected;
              const travel = evs.filter(e => e.kind === "travel").length;
              const followup = evs.filter(e => e.kind === "follow-up").length;
              return (
                <button
                  key={i}
                  onClick={() => setSelected(date)}
                  className={`min-h-[80px] p-2 rounded-md border text-left transition-all ${
                    isSelected ? "border-[#0A0A0A] dark:border-white/70 ring-2 ring-[#0A0A0A]/30 dark:ring-white/30" :
                    isToday ? "border-[#E5E5E5] bg-[#E5E5E5]/10" : "border-border hover:bg-secondary/60"
                  }`}
                  data-testid={`cal-day-${d}`}
                >
                  <div className={`font-display font-bold text-sm ${isToday ? "text-[#0A0A0A] dark:text-foreground" : ""}`}>{d}</div>
                  <div className="mt-1 space-y-0.5">
                    {travel > 0 && (
                      <div className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-[#0A0A0A] text-white dark:bg-white dark:text-neutral-900 inline-flex items-center gap-1">
                        <Plane size={9} /> {travel}
                      </div>
                    )}
                    {followup > 0 && (
                      <div className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-500 text-white dark:text-neutral-900 inline-flex items-center gap-1 ml-0.5">
                        <Bell size={9} /> {followup}
                      </div>
                    )}
                  </div>
                </button>
              );
            })}
          </div>
        </Card>

        <Card className="p-6 rounded-lg" data-testid="cal-detail">
          <div className="label-caps">Details</div>
          <h3 className="font-display text-xl font-bold mt-1">
            {selected ? new Date(selected).toDateString() : "Select a date"}
          </h3>
          <div className="mt-4 space-y-3">
            {selected && selectedList.length === 0 && <div className="text-sm text-muted-foreground">No events on this day.</div>}
            {selLm.visible.map((ev, i) => (
              <Link key={i} href={`/leads/${ev.lead_id}`} className="block border border-border rounded-md p-3 hover:bg-secondary/50 transition-colors">
                <div className="flex items-center justify-between">
                  <div className="font-mono font-semibold text-sm">{ev.lead_id}</div>
                  <span className={`text-[10px] px-2 py-0.5 rounded font-semibold ${ev.kind === "travel" ? "bg-[#0A0A0A] text-white dark:bg-white dark:text-neutral-900" : "bg-amber-500 text-white dark:text-neutral-900"}`}>
                    {ev.kind === "travel" ? "TRAVEL" : "FOLLOW-UP"}
                  </span>
                </div>
                <div className="text-sm font-medium mt-1">{ev.customer_name}</div>
                <div className="text-xs text-muted-foreground">{ev.destination}</div>
                <div className="mt-2"><StatusPill status={ev.lead_status} /></div>
              </Link>
            ))}
            <LoadMore lm={selLm} />
          </div>
        </Card>
      </div>
    </div>
  );
}
