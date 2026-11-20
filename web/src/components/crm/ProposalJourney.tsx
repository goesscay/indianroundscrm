"use client";

import { useEffect, useState } from "react";
import { Bus, Car, Plane, Ship, Train, Truck } from "lucide-react";
import { api } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { INR2, fmtDate } from "@/components/crm/shared";
import { cn } from "@/lib/utils";
import type { ReachBlock, TransportJourney } from "@/types";

const ICON = { flight: Plane, train: Train, bus: Bus, cab: Car, van: Truck, ferry: Ship } as const;

/** What the customer sees of the transport on a premium quotation: one block per destination, a line per leg, the summary and total. The same as the PDF. */
export default function ProposalJourney({ quoteId }: { quoteId: string }) {
  const [j, setJ] = useState<TransportJourney | null>(null);
  const [reach, setReach] = useState<ReachBlock | null>(null);
  useEffect(() => {
    api.get<{ journey: TransportJourney | null; reach?: ReachBlock | null }>(`/quote-proposals/${quoteId}`).then((r) => { setJ(r.data.journey); setReach(r.data.reach ?? null); }).catch(() => { setJ(null); setReach(null); });
  }, [quoteId]);
  if (!j && !reach) return null;
  return (
    <>
      {reach && <ReachCard reach={reach} />}
      {j && <JourneyCard j={j} />}
    </>
  );
}

/** "How to reach": flight and train suggestions, details only, no price. */
function ReachCard({ reach }: { reach: ReachBlock }) {
  return (
    <Card className="rounded-lg p-5" data-testid="quote-reach">
      <h3 className="mb-1 font-display font-bold">{reach.heading}</h3>
      <p className="mb-3 text-xs text-muted-foreground">Details only</p>
      <ul className="divide-y divide-border rounded-md border border-border">
        {reach.items.map((x, i) => {
          const Icon = x.kind === "flight" ? Plane : Train;
          return (
            <li key={i} className="flex flex-wrap items-start gap-3 px-3 py-2.5 text-sm" data-testid={`reach-line-${i}`}>
              <span className={cn("w-[64px] shrink-0 rounded px-1.5 py-0.5 text-center text-[10px] font-bold uppercase tracking-wide", x.kind === "flight" ? "bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300" : "bg-secondary text-muted-foreground")}>{x.label}</span>
              <span className="flex min-w-0 flex-1 items-start gap-2"><Icon size={15} className="mt-0.5 shrink-0 text-muted-foreground" />
                <span><span className="font-medium">{x.title}</span><div className="text-muted-foreground">{x.route}{x.time ? ` · ${x.time}` : ""} · {x.runs_on}{x.classes ? ` · ${x.classes}` : ""}</div>{x.note && <div className="text-xs text-muted-foreground">{x.note}</div>}</span></span>
            </li>
          );
        })}
      </ul>
      <p className="mt-3 text-xs text-muted-foreground">{reach.disclaimer}</p>
    </Card>
  );
}

function JourneyCard({ j }: { j: TransportJourney }) {
  const total = j.tax_display === "with_gst" ? j.total_incl_gst : j.selling;
  return (
    <Card className="rounded-lg p-5" data-testid="quote-journey">
      <h3 className="mb-3 font-display font-bold">Your transport</h3>
      <div className="space-y-4">
        {j.blocks.map((b) => (
          <div key={b.destination}>
            <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{b.destination}</div>
            <ul className="divide-y divide-border rounded-md border border-border">
              {b.lines.map((l, i) => {
                const Icon = ICON[l.mode] ?? Bus;
                return (
                  <li key={i} className="flex flex-wrap items-start gap-3 px-3 py-2.5 text-sm" data-testid={`journey-line-${l.day}-${l.mode}`}>
                    <span className="w-[88px] shrink-0 text-xs text-muted-foreground">Day {l.day}{l.date ? <><br />{fmtDate(l.date)}</> : null}</span>
                    <span className="flex min-w-0 flex-1 items-start gap-2"><Icon size={15} className="mt-0.5 shrink-0 text-muted-foreground" />
                      <span><span className="font-medium">{l.label}</span>{l.title && l.title !== l.label ? <> · {l.title}</> : null}{l.detail ? <span className="text-muted-foreground"> · {l.detail}</span> : null}</span></span>
                    <span className={cn("rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide", l.included ? "bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300" : "bg-secondary text-muted-foreground")}>{l.tag}</span>
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
      </div>
      <div className="mt-4 flex items-center justify-between rounded-md bg-secondary/50 px-3 py-2 text-sm">
        <span className="font-medium">Transport total {j.tax_display === "with_gst" ? "(incl. GST)" : "(before GST)"}</span>
        <span className="font-display text-lg font-black" data-testid="journey-total">{INR2(total)}</span>
      </div>
      {j.notes.map((n) => <p key={n} className="mt-2 text-xs text-muted-foreground">{n}</p>)}
    </Card>
  );
}
