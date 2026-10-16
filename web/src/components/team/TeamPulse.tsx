"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ArrowDown, ArrowRight, ArrowUp } from "lucide-react";
import { api } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { INR } from "@/components/crm/shared";
import { cn } from "@/lib/utils";
import type { TeamSummary } from "@/types";

const TILES: { key: keyof TeamSummary["tiles"]; label: string; fmt?: (v: number) => string; lowerBetter?: boolean; warn?: boolean }[] = [
  { key: "new_leads", label: "New leads" },
  { key: "contacts", label: "Calls, WhatsApps, e-mails" },
  { key: "booked", label: "Bookings" },
  { key: "booked_value", label: "Booked value", fmt: INR },
  { key: "untouched_24h", label: "Leads not contacted 24h+", warn: true, lowerBetter: true },
  { key: "fu_overdue", label: "Follow-ups overdue", warn: true, lowerBetter: true },
  { key: "tasks_overdue", label: "Trip items overdue", warn: true, lowerBetter: true },
];

/** The team at a glance, on the dashboard. Super Admin only: the server answers 404 to everyone else, and this renders nothing then. */
export default function TeamPulse() {
  const [s, setS] = useState<TeamSummary | null>(null);
  useEffect(() => {
    let live = true;
    api.get<TeamSummary>("/reports/team/summary").then((r) => { if (live) setS(r.data); }).catch(() => { /* not a Super Admin, or not available: show nothing */ });
    return () => { live = false; };
  }, []);
  if (!s) return null;
  return (
    <section className="space-y-3" data-testid="team-pulse">
      <div className="flex items-center justify-between gap-3">
        <h2 className="font-display text-lg font-bold">Team this month <span className="text-xs font-normal text-muted-foreground">(arrows compare with the same days last month)</span></h2>
        <Link href="/team-performance" className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" data-testid="team-pulse-link">Full report <ArrowRight size={12} /></Link>
      </div>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
        {TILES.map((t) => {
          const v = s.tiles[t.key];
          const warn = t.warn && v.now > 0;
          const d = v.prev === null ? 0 : v.now - v.prev;
          const good = t.lowerBetter ? d < 0 : d > 0;
          return (
            <Card key={t.key} className={cn("rounded-lg p-3", warn && "border-red-300 dark:border-red-900")} data-testid={`team-tile-${t.key}`}>
              <div className="text-[11px] leading-tight text-muted-foreground">{t.label}</div>
              <div className={cn("mt-1 font-display text-2xl font-black tabular-nums", warn && "text-red-600 dark:text-red-400")}>{t.fmt ? t.fmt(v.now) : v.now.toLocaleString("en-IN")}</div>
              {v.prev !== null && d !== 0 && (
                <div className={cn("inline-flex items-center text-[11px] font-semibold", good ? "text-emerald-600 dark:text-emerald-400" : "text-red-600 dark:text-red-400")}>
                  {d > 0 ? <ArrowUp size={10} /> : <ArrowDown size={10} />}{t.fmt ? t.fmt(Math.abs(d)) : Math.abs(d).toLocaleString("en-IN")}
                </div>
              )}
            </Card>
          );
        })}
      </div>
      {s.behind.length > 0 && (
        <Card className="rounded-lg p-4" data-testid="team-behind">
          <div className="mb-2 text-xs font-bold uppercase tracking-wider text-muted-foreground">To raise on the call</div>
          <ul className="divide-y divide-border">
            {s.behind.map((p) => (
              <li key={p.id} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-1.5 text-sm">
                <Link href={`/team-performance?user=${p.id}&tab=${p.group}`} className="font-medium hover:underline">{p.name}</Link>
                <span className="text-xs text-red-700 dark:text-red-400">{p.flags.map((f) => f.label).join(" · ")}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </section>
  );
}
