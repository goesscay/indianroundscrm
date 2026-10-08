"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Plane } from "lucide-react";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { INR, fmtDate } from "./shared";
import { PaymentChip, ProgressBar, daysLabel, type TripSummary } from "./tripShared";

/** On the customer page: what each trip has been paid, what is left, and how far the preparation is. */
export default function CustomerTrips({ customerId }: { customerId: string }) {
  const { can } = useCurrentUser();
  const [rows, setRows] = useState<TripSummary[]>([]);
  const allowed = can("trips.view");

  useEffect(() => {
    if (!allowed) return;
    let live = true;
    api.get<TripSummary[]>(`/customers/${customerId}/trips`).then(({ data }) => { if (live) setRows(data); }).catch(() => { /* optional panel */ });
    return () => { live = false; };
  }, [customerId, allowed]);

  if (!allowed || rows.length === 0) return null;
  const paid = rows.reduce((s, r) => s + r.payment.paid, 0);
  const balance = rows.reduce((s, r) => s + r.payment.balance, 0);
  return (
    <Card className="rounded-lg p-5" data-testid="customer-trips">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="flex items-center gap-2 font-display font-bold"><Plane size={16} className="text-muted-foreground" /> Trips &amp; payments</h3>
        <span className="text-xs text-muted-foreground">Paid {INR(paid)} · Balance <span className={balance > 0 ? "font-semibold text-red-600 dark:text-red-400" : ""}>{INR(balance)}</span></span>
      </div>
      <ul className="divide-y divide-border">
        {rows.map((r) => (
          <li key={r.deal.id} className="py-2.5 text-sm">
            <Link href={`/deals/${r.deal.id}`} className="block hover:underline">
              <div className="flex items-start justify-between gap-2">
                <span className="min-w-0 truncate font-medium">{r.deal.name}</span>
                <PaymentChip p={r.payment} />
              </div>
              <div className="mt-0.5 text-xs text-muted-foreground">
                {r.deal.travel_date ? `${fmtDate(r.deal.travel_date)} · ${daysLabel(r.days_left)}` : "No travel date"} · Paid {INR(r.payment.paid)} of {INR(r.payment.invoiced)}
              </div>
              {r.confirmed ? (
                <div className="mt-1.5"><ProgressBar percent={r.progress.percent} tone={r.progress.overdue > 0 ? "warn" : "default"} />
                  <div className="mt-0.5 text-xs text-muted-foreground">Checklist {r.progress.done}/{r.progress.total}{r.progress.overdue > 0 ? ` · ${r.progress.overdue} overdue` : ""}</div></div>
              ) : <div className="mt-1 text-xs text-muted-foreground">Trip not confirmed yet</div>}
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  );
}
