"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ChevronDown, ChevronUp, Reply } from "lucide-react";
import { api } from "@/lib/api";
import { Card } from "@/components/ui/card";

interface CustomerReply {
  id: string; from_email: string; from_name: string; subject: string; preview: string; body: string; received_at: string | null; matched_by: string;
  customer: { id: string; name: string } | null;
  deal: { id: string; deal_no: string; name: string } | null;
  quote: { id: string; quote_no: string } | null;
  invoice: { id: string; invoice_no: string | null } | null;
}

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { day: "numeric", month: "short", hour: "numeric", minute: "2-digit" }) : "");

/** Replies customers sent to our quote / invoice e-mails. `base` is "/customers/3", "/deals/7", "/quotes/5" or "/invoices/9". Hidden when there are none. */
export default function CustomerReplies({ base }: { base: string }) {
  const [rows, setRows] = useState<CustomerReply[]>([]);
  const [open, setOpen] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    api.get<CustomerReply[]>(`${base}/replies`).then(({ data }) => { if (live) setRows(data); }).catch(() => { /* optional panel */ });
    return () => { live = false; };
  }, [base]);

  if (rows.length === 0) return null;
  return (
    <Card className="rounded-lg p-5" data-testid="customer-replies">
      <h3 className="mb-2 flex items-center gap-2 font-display font-bold"><Reply size={16} className="text-muted-foreground" /> Customer replies <span className="text-xs font-normal text-muted-foreground">({rows.length})</span></h3>
      <ul className="divide-y divide-border">
        {rows.map((r) => (
          <li key={r.id} className="py-2 text-sm">
            <button type="button" className="flex w-full items-start justify-between gap-3 text-left" onClick={() => setOpen(open === r.id ? null : r.id)} aria-expanded={open === r.id}>
              <span className="min-w-0">
                <span className="block truncate font-medium">{r.subject || "(no subject)"}</span>
                <span className="block truncate text-xs text-muted-foreground">{r.from_name || r.from_email} · {when(r.received_at)}</span>
              </span>
              {open === r.id ? <ChevronUp size={14} className="mt-1 shrink-0" /> : <ChevronDown size={14} className="mt-1 shrink-0" />}
            </button>
            {open === r.id ? <p className="mt-2 whitespace-pre-line rounded bg-secondary/40 p-3 text-sm">{r.body || r.preview}</p>
              : <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{r.preview}</p>}
            <div className="mt-1 flex flex-wrap gap-x-3 text-xs">
              {r.quote && <Link href={`/quotes/${r.quote.id}`} className="underline">{r.quote.quote_no}</Link>}
              {r.invoice && <Link href={`/invoices/${r.invoice.id}`} className="underline">{r.invoice.invoice_no}</Link>}
              {r.deal && <Link href={`/deals/${r.deal.id}`} className="underline">{r.deal.deal_no}</Link>}
              {r.customer && <Link href={`/customers/${r.customer.id}`} className="underline">{r.customer.name}</Link>}
            </div>
          </li>
        ))}
      </ul>
    </Card>
  );
}
