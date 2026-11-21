"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { cn } from "@/lib/utils";
import type { RoadRow } from "@/types";

const inr = (n: number) => new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", maximumFractionDigits: 2 }).format(n);
const cur = (n: number, c: string) => (c === "INR" ? inr(n) : new Intl.NumberFormat("en-US", { style: "currency", currency: c, maximumFractionDigits: 2 }).format(n));
const plural = (n: number, w: string) => `${n} ${w}${n === 1 ? "" : "s"}`;

/** What the rate is multiplied by, in words: "5 days × 2 vehicles", "4 persons × 2 ways". */
function timesText(r: RoadRow): string {
  const b = r.breakdown!;
  if (r.charged === "per_day") return `${plural(b.days ?? 1, "day")} × ${plural(b.units, "vehicle")}`;
  if (r.charged === "per_person") return `${plural(b.units, "person")}${(b.legs ?? 1) > 1 ? ` × ${b.legs} ways` : ""}`;
  return `${plural(b.units, "vehicle")}${(b.legs ?? 1) > 1 ? ` × ${b.legs} ways` : ""}`;
}
const perText = (r: RoadRow) => (r.charged === "per_day" ? "per day" : r.charged === "per_person" ? "per person" : "per trip");
const marginText = (r: RoadRow) => { const b = r.breakdown!; return b.margin_type === "amount" ? `${inr(b.margin_value)} per person × ${plural(Math.max(1, b.persons), "person")}` : `${b.margin_value}% of the cost`; };
const nameOf = (r: RoadRow) => (r.car_name ? `${r.car_name}, ${r.name}` : r.name);

/**
 * The Price breakdown: for each road transport line the rate, what it is multiplied by, the cost, the margin, the selling price and the GST, then the steps
 * in words with one line worked through. The server sends the figures only to people who may see costs and margins, so for sales there is nothing to show.
 */
export default function RoadPriceBreakdown({ rows, gstRate, total }: { rows: RoadRow[]; gstRate: number; total: number }) {
  const [open, setOpen] = useState(false);
  const priced = rows.filter((r) => r.breakdown && !r.rate_needed);
  if (priced.length === 0) return null;
  const gst = (n: number) => Math.round(n * gstRate) / 100;
  const sum = (f: (r: RoadRow) => number) => priced.reduce((a, r) => a + f(r), 0);
  const cost = sum((r) => r.cost ?? 0), margin = sum((r) => r.margin_amount ?? 0), selling = sum((r) => r.selling);
  const ex = priced[0];
  const b = ex.breakdown!;
  const foreign = ex.currency !== "INR" && b.fx != null;
  const steps: string[] = [
    `Rate: the price Operations keep for this car and route on the travel date: ${cur(b.rate, b.currency)} ${perText(ex)}${b.rate_varies ? " (average: a season changes the price during the stay)" : ""}.`,
    `Times: × ${timesText(ex)} = ${cur(b.raw, b.currency)}.`,
    ...(foreign ? [`Exchange: × ${b.fx} (the rate on this quotation) = ${inr(ex.cost ?? 0)}.`] : []),
    `Cost: ${inr(ex.cost ?? 0)}.`,
    `Margin: ${marginText(ex)} = ${inr(ex.margin_amount ?? 0)}.`,
    `Selling price: cost + margin = ${inr(ex.selling)}.`,
    `GST: ${gstRate}% of the selling price = ${inr(gst(ex.selling))}, so ${inr(ex.selling + gst(ex.selling))} with GST.`,
  ];
  return (
    <div className="rounded-md border" data-testid="road-breakdown">
      <button type="button" aria-expanded={open} onClick={() => setOpen((o) => !o)} className="flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left text-sm font-medium" data-testid="road-breakdown-toggle">
        <span>Price breakdown <span className="font-normal text-muted-foreground">(only people who may see costs and margins)</span></span>
        <ChevronDown size={16} className={cn("shrink-0 transition-transform", open && "rotate-180")} />
      </button>
      {open && (
        <div className="space-y-3 border-t px-4 py-3">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <th className="py-2 pr-2">Line</th><th className="py-2 pr-2">Rate</th><th className="py-2 pr-2">Times</th><th className="py-2 pr-2 text-right">Cost</th><th className="py-2 pr-2 text-right">Margin</th>
                <th className="py-2 pr-2 text-right">Selling</th><th className="py-2 pr-2 text-right">GST {gstRate}%</th><th className="py-2 text-right">With GST</th>
              </tr></thead>
              <tbody>
                {priced.map((r) => (
                  <tr key={r.key} className="border-b last:border-0" data-testid={`breakdown-row-${r.key}`}>
                    <td className="py-2 pr-2 font-medium">{nameOf(r)}</td>
                    <td className="py-2 pr-2 tabular-nums">{cur(r.breakdown!.rate, r.breakdown!.currency)} <span className="text-xs text-muted-foreground">{perText(r)}</span></td>
                    <td className="py-2 pr-2 text-xs">{timesText(r)}{r.breakdown!.fx != null && r.currency !== "INR" ? ` × ${r.breakdown!.fx}` : ""}</td>
                    <td className="py-2 pr-2 text-right tabular-nums">{inr(r.cost ?? 0)}</td>
                    <td className="py-2 pr-2 text-right tabular-nums">{inr(r.margin_amount ?? 0)}<div className="text-[11px] text-muted-foreground">{marginText(r)}</div></td>
                    <td className="py-2 pr-2 text-right font-medium tabular-nums">{inr(r.selling)}</td>
                    <td className="py-2 pr-2 text-right tabular-nums">{inr(gst(r.selling))}</td>
                    <td className="py-2 text-right tabular-nums">{inr(r.selling + gst(r.selling))}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot><tr className="border-t font-semibold" data-testid="breakdown-total">
                <td className="py-2 pr-2" colSpan={3}>Total</td><td className="py-2 pr-2 text-right tabular-nums">{inr(cost)}</td><td className="py-2 pr-2 text-right tabular-nums">{inr(margin)}</td>
                <td className="py-2 pr-2 text-right tabular-nums" data-testid="breakdown-selling">{inr(selling)}</td><td className="py-2 pr-2 text-right tabular-nums">{inr(gst(selling))}</td><td className="py-2 text-right tabular-nums">{inr(selling + gst(selling))}</td>
              </tr></tfoot>
            </table>
          </div>
          <p className={cn("text-xs", Math.abs(selling - total) < 0.5 ? "text-emerald-700 dark:text-emerald-400" : "text-amber-700 dark:text-amber-300")} data-testid="breakdown-check">
            {Math.abs(selling - total) < 0.5 ? `The lines add up to the Transport price, ${inr(total)} before GST.` : `The priced lines come to ${inr(selling)}; the Transport price is ${inr(total)} because some lines still need a rate.`}
          </p>
          <div className="rounded-md bg-muted/40 p-3 text-sm" data-testid="breakdown-steps">
            <div className="mb-1 font-medium">How a price is worked out (for {nameOf(ex)})</div>
            <ol className="list-decimal space-y-0.5 pl-5 text-muted-foreground">{steps.map((t, i) => <li key={i}>{t}</li>)}</ol>
          </div>
        </div>
      )}
    </div>
  );
}
