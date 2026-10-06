"use client";

import CustomerReplies from "@/components/crm/CustomerReplies";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Check, FilePlus2, FileText, GitBranch, Pencil, Send, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import SendQuoteDialog from "@/components/crm/SendQuoteDialog";
import { INR2, QuotePill, fmtDate } from "@/components/crm/shared";
import type { Quote } from "@/types";

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-[120px_1fr] gap-2 py-1.5 text-sm">
      <dt className="text-muted-foreground">{label}</dt><dd className="min-w-0 break-words">{children}</dd>
    </div>
  );
}

export default function QuoteDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const [x, setX] = useState<Quote | null>(null);
  const [error, setError] = useState("");
  const [sendOpen, setSendOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try { const { data } = await api.get<Quote>(`/quotes/${id}`); setX(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  const act = async (fn: () => Promise<unknown>, ok: string) => {
    setBusy(true);
    try { await fn(); toast.success(ok); await load(); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const setStatus = (status: string, reason?: string) => act(() => api.post(`/quotes/${id}/status`, { status, ...(reason ? { reason } : {}) }), status === "accepted" ? "Marked accepted" : status === "rejected" ? "Marked rejected" : "Marked sent");

  const openPdf = async () => {
    try {
      const res = await api.get(`/quotes/${id}/pdf`, { responseType: "blob" });
      const url = URL.createObjectURL(new Blob([res.data], { type: "application/pdf" }));
      window.open(url, "_blank", "noopener");
      setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const revise = async () => {
    setBusy(true);
    try { const { data } = await api.post<Quote>(`/quotes/${id}/revise`, {}); toast.success(`Created ${data.quote_no}`); router.push(`/quotes/${data.id}/edit`); }
    catch (e) { toast.error(formatApiError(e)); setBusy(false); }
  };

  const toInvoice = async () => {
    setBusy(true);
    try { const { data } = await api.post<{ id: string }>(`/quotes/${id}/invoice`, {}); toast.success("Draft invoice created from this quote"); router.push(`/invoices/${data.id}/edit`); }
    catch (e) { toast.error(formatApiError(e)); setBusy(false); }
  };

  const remove = async () => {
    if (!x || !window.confirm(`Delete draft ${x.quote_no}?`)) return;
    try { await api.delete(`/quotes/${id}`); toast.success("Quote deleted"); router.replace("/quotes"); } catch (e) { toast.error(formatApiError(e)); }
  };

  if (error) return <div className="space-y-3"><Button variant="ghost" size="sm" onClick={() => router.push("/quotes")}><ArrowLeft size={16} /> Quotes</Button><div className="text-sm text-destructive">{error}</div></div>;
  if (!x) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;

  const canCreate = can("quotes.create"); const canSend = can("quotes.send");
  const draft = x.status === "draft";

  return (
    <div className="space-y-6" data-testid="quote-detail">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.push("/quotes")} className="-ml-3 mb-2"><ArrowLeft size={16} /> Quotes</Button>
          <div className="label-caps">Quote · {x.quote_no}</div>
          <div className="mt-1 flex flex-wrap items-center gap-3">
            <h1 className="font-display text-3xl font-black tracking-tight sm:text-4xl" data-testid="quote-title-h">{x.title}</h1>
            <QuotePill status={x.display_status} />
          </div>
          <p className="mt-2 text-sm text-muted-foreground">
            {x.customer && <Link href={`/customers/${x.customer.id}`} className="font-medium text-foreground hover:underline">{x.customer.name}</Link>}
            {x.deal && <> · <Link href={`/deals/${x.deal.id}`} className="hover:underline">{x.deal.deal_no}</Link></>}
            {x.version > 1 && <> · version {x.version}</>}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={openPdf} data-testid="quote-pdf"><FileText size={16} /> PDF</Button>
          {!draft && x.status !== "superseded" && x.status !== "rejected" && can("invoices.create") && <Button variant="outline" disabled={busy} onClick={toInvoice} data-testid="quote-to-invoice"><FilePlus2 size={16} /> Create invoice</Button>}
          {draft && canCreate && <Button variant="outline" onClick={() => router.push(`/quotes/${x.id}/edit`)} data-testid="quote-edit"><Pencil size={16} /> Edit</Button>}
          {canSend && !["superseded", "rejected"].includes(x.status) && <Button onClick={() => setSendOpen(true)} disabled={busy} data-testid="quote-send"><Send size={16} /> {x.status === "draft" ? "Send" : "Send again"}</Button>}
          {canSend && draft && <Button variant="outline" disabled={busy} onClick={() => setStatus("sent")} data-testid="quote-mark-sent">Mark as sent</Button>}
        </div>
      </div>

      {x.status === "sent" && canSend && (
        <Card className="flex flex-wrap items-center justify-between gap-3 rounded-lg p-4" data-testid="quote-decision">
          <span className="text-sm">Waiting for the customer{x.sent_at ? ` since ${fmtDate(x.sent_at)}` : ""}. What did they say?</span>
          <div className="flex gap-2">
            <Button size="sm" disabled={busy} onClick={() => setStatus("accepted")} data-testid="quote-accept"><Check size={14} /> Accepted</Button>
            <Button size="sm" variant="outline" disabled={busy} onClick={() => { const r = window.prompt("Why did they say no? (optional)"); if (r !== null) void setStatus("rejected", r); }} data-testid="quote-reject"><X size={14} /> Rejected</Button>
          </div>
        </Card>
      )}
      {x.status === "accepted" && <Card className="rounded-lg border-emerald-300 p-4 text-sm dark:border-emerald-900">Accepted{x.accepted_at ? ` on ${fmtDate(x.accepted_at)}` : ""}. {x.deal ? "The deal's selling amount now matches this quote." : "Link a deal to carry the amount across."}</Card>}
      {x.status === "rejected" && <Card className="rounded-lg p-4 text-sm">Rejected{x.reject_reason ? `: ${x.reject_reason}` : ""}.</Card>}
      {["sent", "rejected"].includes(x.status) && canCreate && (
        <div><Button variant="outline" size="sm" disabled={busy} onClick={revise} data-testid="quote-revise"><GitBranch size={14} /> Revise (new version)</Button></div>
      )}
      {x.status === "superseded" && <Card className="rounded-lg p-4 text-sm text-muted-foreground">A newer version of this quote exists. This one is kept for the record.</Card>}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card className="rounded-lg p-4"><div className="label-caps">Total</div><div className="mt-1 font-display text-2xl font-black" data-testid="quote-total-h">{INR2(x.total)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">GST</div><div className="mt-1 font-display text-2xl font-black">{INR2(x.tax_total)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Valid until</div><div className="mt-1 font-display text-2xl font-black">{fmtDate(x.valid_until)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Travellers</div><div className="mt-1 font-display text-2xl font-black">{x.adults}{x.children ? ` + ${x.children}` : ""}</div><div className="text-xs text-muted-foreground">adults{x.children ? " + children" : ""}</div></Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <div className="space-y-6">
          <Card className="overflow-hidden rounded-lg">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-sm">
                <thead><tr className="border-b border-border bg-secondary/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <th className="px-4 py-2.5">Description</th><th className="px-2 py-2.5 text-right">Qty</th><th className="px-2 py-2.5 text-right">Rate</th><th className="px-2 py-2.5 text-right">Discount</th><th className="px-2 py-2.5 text-right">GST</th><th className="px-4 py-2.5 text-right">Amount</th>
                </tr></thead>
                <tbody>
                  {x.lines?.map((l, i) => (
                    <tr key={i} className="border-b border-border last:border-0">
                      <td className="px-4 py-2.5">{l.description}</td><td className="px-2 py-2.5 text-right">{l.qty}</td>
                      <td className="px-2 py-2.5 text-right">{INR2(l.unit_price)}</td><td className="px-2 py-2.5 text-right">{l.discount ? INR2(l.discount) : "—"}</td>
                      <td className="px-2 py-2.5 text-right">{l.tax_rate}%</td><td className="px-4 py-2.5 text-right font-medium">{INR2(l.total)}</td>
                    </tr>
                  ))}
                  {(x.lines?.length ?? 0) === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">No price lines yet.</td></tr>}
                </tbody>
              </table>
            </div>
            <div className="ml-auto w-full max-w-xs space-y-1.5 px-4 py-3 text-sm">
              <div className="flex justify-between"><span className="text-muted-foreground">Subtotal</span><span>{INR2(x.subtotal)}</span></div>
              {x.discount_total > 0 && <div className="flex justify-between"><span className="text-muted-foreground">Discount</span><span>− {INR2(x.discount_total)}</span></div>}
              <div className="flex justify-between"><span className="text-muted-foreground">GST</span><span>{INR2(x.tax_total)}</span></div>
              {x.tcs_total > 0 && <div className="flex justify-between"><span className="text-muted-foreground">TCS ({x.tcs_rate}%)</span><span>{INR2(x.tcs_total)}</span></div>}
              <div className="flex justify-between border-t border-border pt-2 font-bold"><span>Total</span><span>{INR2(x.total)}</span></div>
            </div>
          </Card>

          {(x.itinerary?.length ?? 0) > 0 && (
            <Card className="rounded-lg p-5"><h3 className="mb-2 font-display font-bold">Itinerary</h3>
              <ol className="space-y-2 text-sm">{x.itinerary!.map((d, i) => <li key={i}><span className="font-semibold">Day {d.day_no ?? i + 1} — {d.title}</span>{d.description && <div className="whitespace-pre-line text-muted-foreground">{d.description}</div>}</li>)}</ol>
            </Card>
          )}
          {(x.inclusions || x.exclusions) && (
            <div className="grid gap-6 sm:grid-cols-2">
              {x.inclusions && <Card className="rounded-lg p-5"><h3 className="mb-2 font-display font-bold">Inclusions</h3><p className="whitespace-pre-line text-sm">{x.inclusions}</p></Card>}
              {x.exclusions && <Card className="rounded-lg p-5"><h3 className="mb-2 font-display font-bold">Exclusions</h3><p className="whitespace-pre-line text-sm">{x.exclusions}</p></Card>}
            </div>
          )}
          {x.terms && <Card className="rounded-lg p-5"><h3 className="mb-2 font-display font-bold">Terms &amp; conditions</h3><p className="whitespace-pre-line text-sm text-muted-foreground">{x.terms}</p></Card>}
        </div>

        <div className="space-y-6">
          <Card className="rounded-lg p-5">
            <h3 className="mb-2 font-display font-bold">Details</h3>
            <dl>
              <Row label="Destination">{x.destination || "—"}</Row>
              <Row label="Travel">{fmtDate(x.travel_date)}{x.duration_days ? ` · ${Math.max(0, x.duration_days - 1)}N / ${x.duration_days}D` : ""}</Row>
              <Row label="Issued">{fmtDate(x.issue_date)}</Row>
              <Row label="Owner">{x.owner?.name ?? "Unassigned"}</Row>
              {x.intro && <Row label="Intro"><span className="whitespace-pre-line">{x.intro}</span></Row>}
            </dl>
          </Card>
          <Card className="rounded-lg p-5" data-testid="quote-emails">
            <h3 className="mb-2 font-display font-bold">E-mails sent</h3>
            {(x.emails?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">Not e-mailed yet.</p>}
            <ul className="divide-y divide-border">
              {x.emails?.map((m, i) => (
                <li key={i} className="py-2 text-sm">
                  <div className="font-medium">{m.subject}</div>
                  <div className="text-xs text-muted-foreground">To {m.to} · {new Date(m.created_at).toLocaleString(undefined, { day: "numeric", month: "short", hour: "numeric", minute: "2-digit" })}{m.with_pdf ? " · PDF attached" : ""}</div>
                  <div className={m.status === "failed" ? "text-xs text-destructive" : "text-xs text-muted-foreground"}>{m.status === "failed" ? `Failed: ${m.error}` : m.status === "simulated_sent" ? "Logged only (mail not connected)" : "Sent"}</div>
                </li>
              ))}
            </ul>
          </Card>
          <CustomerReplies base={`/quotes/${x.id}`} />
          {draft && can("quotes.delete", "quotes.create") && <Button variant="ghost" size="sm" className="text-destructive" onClick={remove} data-testid="quote-delete"><Trash2 size={14} /> Delete draft</Button>}
        </div>
      </div>

      <SendQuoteDialog quoteId={x.id} open={sendOpen} onOpenChange={setSendOpen} onSent={(q) => setX(q)} />
    </div>
  );
}
