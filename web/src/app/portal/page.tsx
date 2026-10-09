"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ChevronRight, Loader2 } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import PortalShell from "@/components/portal/PortalShell";
import { StateBadge, dateText, money, type PortalRequestRow } from "@/components/portal/shared";

function RequestList() {
  const [rows, setRows] = useState<PortalRequestRow[] | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api.get<PortalRequestRow[]>("/portal/requests").then((r) => setRows(r.data)).catch((e) => setError(formatApiError(e)));
  }, []);

  if (error) return <div className="rounded-xl bg-white p-6 text-sm text-red-700 shadow-sm" role="alert">{error}</div>;
  if (!rows) return <div className="p-8 text-center text-sm text-neutral-500"><Loader2 className="mx-auto mb-2 animate-spin" size={18} />Loading…</div>;

  const open = rows.filter((r) => r.state === "open");
  return (
    <div className="space-y-4" data-testid="portal-requests">
      <div>
        <h1 className="text-2xl font-bold">Enquiries</h1>
        <p className="mt-1 text-sm text-neutral-600">
          {rows.length === 0 ? "No enquiries yet. New ones appear here when we send them to you." : open.length > 0 ? `${open.length} waiting for your quote.` : "You have answered every enquiry."}
        </p>
      </div>
      <ul className="space-y-3">
        {rows.map((r) => (
          <li key={r.lead_id}>
            <Link href={`/portal/requests/${encodeURIComponent(r.lead_id)}`} className="flex items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm transition hover:shadow" data-testid={`portal-request-${r.lead_id}`}>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-sm font-semibold">{r.lead_id}</span>
                  <StateBadge state={r.state} />
                </div>
                <div className="mt-1 truncate text-base font-medium">{r.destination || "Trip enquiry"}</div>
                <div className="mt-0.5 text-xs text-neutral-600">
                  {[r.travel_date ? `Travel ${dateText(r.travel_date)}` : null, r.duration, r.adults != null ? `${r.adults} adult${Number(r.adults) === 1 ? "" : "s"}` : null, r.children ? `${r.children} child${Number(r.children) === 1 ? "" : "ren"}` : null].filter(Boolean).join(" · ")}
                </div>
                {r.quote && <div className="mt-1 text-xs text-emerald-700">Your quote{r.quote.amount != null ? `: ${money(r.quote.amount, r.quote.currency)}` : " sent"} (version {r.quote.version})</div>}
              </div>
              <ChevronRight size={18} className="shrink-0 text-neutral-400" aria-hidden />
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

export default function PortalHome() {
  return <PortalShell>{() => <RequestList />}</PortalShell>;
}
