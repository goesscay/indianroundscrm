"use client";

import Link from "next/link";
import { cn } from "@/lib/utils";

const TABS = [{ href: "/transport", label: "Rates", id: "rates" }, { href: "/transport/flights", label: "Flights", id: "flights" }, { href: "/transport/trains", label: "Trains", id: "trains" }];

/** Rates price a quotation. Flights and Trains hold details only, with no prices, and are shown to customers as suggestions. */
export default function TransportTabs({ active }: { active: "rates" | "flights" | "trains" }) {
  return (
    <div data-testid="transport-tabs">
      <div className="flex gap-1 border-b border-border">
        {TABS.map((t) => (
          <Link key={t.id} href={t.href} data-testid={`transport-tab-${t.id}`} className={cn("-mb-px border-b-2 px-4 py-2 text-sm font-medium", active === t.id ? "border-foreground text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}>{t.label}</Link>
        ))}
      </div>
      <p className="mt-2 text-xs text-muted-foreground">Rates price a quotation. Flights and Trains hold details only, with no prices, and are shown to customers as suggestions.</p>
    </div>
  );
}
