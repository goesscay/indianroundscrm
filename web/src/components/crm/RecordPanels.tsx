"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { FileText, Landmark, ListChecks, Plus, ReceiptText, StickyNote, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import ActivityDialog from "@/components/activities/ActivityDialog";
import { DoneToggle, canEditActivity, dueClass, formatDue, typeMeta } from "@/components/activities/shared";
import { cn } from "@/lib/utils";
import { INR2, PayPill, QuotePill, fmtDate } from "@/components/crm/shared";
import type { Activity, CrmNote, DealBooks, Invoice, Quote } from "@/types";

/** Notes card for a customer or a deal. `base` is "/customers/12" or "/deals/7". */
export function RecordNotes({ base, canWrite }: { base: string; canWrite: boolean }) {
  const { user, can } = useCurrentUser();
  const [notes, setNotes] = useState<CrmNote[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [txt, setTxt] = useState("");
  const [busy, setBusy] = useState(false);
  const lm = useLoadMore(notes, 8, base);

  const load = useCallback(async () => {
    try { const { data } = await api.get<CrmNote[]>(`${base}/notes`); setNotes(data); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setLoaded(true); }
  }, [base]);
  useEffect(() => { void load(); }, [load]);

  const add = async () => {
    if (!txt.trim()) return;
    setBusy(true);
    try { await api.post(`${base}/notes`, { note: txt.trim() }); setTxt(""); await load(); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };
  const remove = async (id: string) => {
    if (!window.confirm("Delete this note?")) return;
    try { await api.delete(`/crm-notes/${id}`); await load(); } catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <Card className="p-5 rounded-lg" data-testid="record-notes">
      <div className="mb-3 flex items-center gap-2">
        <StickyNote size={16} className="text-muted-foreground" />
        <h3 className="font-display font-bold">Notes</h3>
        {loaded && <span className="text-xs text-muted-foreground">({notes.length})</span>}
      </div>
      {canWrite && (
        <div className="mb-3 space-y-2">
          <Textarea rows={2} value={txt} onChange={(e) => setTxt(e.target.value)} placeholder="Add a note…" data-testid="note-input" />
          <Button size="sm" onClick={add} disabled={busy || !txt.trim()} data-testid="note-add">Add note</Button>
        </div>
      )}
      {loaded && notes.length === 0 && <p className="text-sm text-muted-foreground">No notes yet.</p>}
      <ul className="divide-y divide-border">
        {lm.visible.map((n) => (
          <li key={n.id} className="py-2.5 text-sm" data-testid={`note-${n.id}`}>
            <p className="whitespace-pre-wrap">{n.note}</p>
            <div className="mt-1 flex items-center justify-between text-xs text-muted-foreground">
              <span>{n.created_by_name || "Someone"} · {new Date(n.created_at).toLocaleString(undefined, { day: "numeric", month: "short", hour: "numeric", minute: "2-digit" })}</span>
              {(canWrite && (String(n.created_by_id) === String(user.id) || can("customers.edit", "deals.edit"))) && (
                <button type="button" onClick={() => remove(n.id)} aria-label="Delete note" className="rounded p-1 hover:bg-accent"><Trash2 size={13} /></button>
              )}
            </div>
          </li>
        ))}
      </ul>
      <LoadMore lm={lm} />
    </Card>
  );
}

/** Activities card for a customer or deal. `link` is what new activities attach to. */
export function RecordActivities({ base, link }: { base: string; link: { customer_id?: string; deal_id?: string } }) {
  const { user, can } = useCurrentUser();
  const myId = user.id ? String(user.id) : "";
  const canCreate = can("activities.create", "activities.manage");
  const [items, setItems] = useState<Activity[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState("");
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Activity | null>(null);
  const lm = useLoadMore(items, 8, base);

  const load = useCallback(async () => {
    try { const { data } = await api.get<Activity[]>(`${base}/activities`); setItems(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
    finally { setLoaded(true); }
  }, [base]);
  useEffect(() => { void load(); }, [load]);

  const toggle = async (a: Activity) => {
    try { await api.patch(`/activities/${a.id}`, { done: !a.done }); toast.success(a.done ? "Reopened" : "Marked as done"); }
    catch (e) { toast.error(formatApiError(e)); }
    await load();
  };

  return (
    <Card className="p-5 rounded-lg" data-testid="record-activities">
      <div className="mb-3 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <ListChecks size={16} className="text-muted-foreground" />
          <h3 className="font-display font-bold">Activities</h3>
          {loaded && <span className="text-xs text-muted-foreground">({items.length})</span>}
        </div>
        {canCreate && (
          <Button size="sm" variant="outline" onClick={() => { setEditing(null); setOpen(true); }} data-testid="record-activity-add"><Plus size={14} /> Add activity</Button>
        )}
      </div>
      {error && <div className="text-sm text-destructive">{error}</div>}
      {loaded && !error && items.length === 0 && <p className="py-2 text-sm text-muted-foreground">No calls, meetings or tasks yet.</p>}
      <ul className="divide-y divide-border">
        {lm.visible.map((a) => {
          const m = typeMeta(a.type); const Icon = m.icon;
          return (
            <li key={a.id} className="flex items-start gap-3 py-2.5">
              <div className="pt-0.5"><DoneToggle done={a.done} title={a.title} onToggle={() => toggle(a)} size={20} disabled={!canEditActivity(can, myId, a)} testId={`record-activity-done-${a.id}`} /></div>
              <button type="button" onClick={() => { setEditing(a); setOpen(true); }} className="min-w-0 flex-1 text-left focus-visible:outline-none focus-visible:underline">
                <div className={cn("truncate text-sm font-medium", a.done && "line-through text-muted-foreground")}>{a.title}</div>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                  <span className="inline-flex items-center gap-1"><Icon size={11} aria-hidden /> {m.label}</span>
                  <span className={dueClass(a)}>{formatDue(a.due_at)}</span>
                  <span>{a.assigned_to?.name ?? "Nobody"}</span>
                </div>
              </button>
            </li>
          );
        })}
      </ul>
      <LoadMore lm={lm} />
      <ActivityDialog open={open} onOpenChange={setOpen} activity={editing} defaults={link} onChanged={() => void load()} />
    </Card>
  );
}

/** Quotes for a customer or a deal, with a New quote shortcut. `filter` is {customer_id} or {deal_id}. */
export function RecordQuotes({ filter, newHref }: { filter: { customer_id?: string; deal_id?: string }; newHref: string }) {
  const { can } = useCurrentUser();
  const [items, setItems] = useState<Quote[] | null>(null);
  const key = JSON.stringify(filter);

  useEffect(() => {
    if (!can("quotes.view")) return;
    api.get<Quote[]>("/quotes", { params: { ...filter, limit: 50 } }).then((r) => setItems(r.data)).catch(() => setItems([]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  if (!can("quotes.view")) return null;
  return (
    <Card className="p-5 rounded-lg" data-testid="record-quotes">
      <div className="mb-3 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <FileText size={16} className="text-muted-foreground" />
          <h3 className="font-display font-bold">Quotes</h3>
          {items && <span className="text-xs text-muted-foreground">({items.length})</span>}
        </div>
        {can("quotes.create") && <Link href={newHref}><Button size="sm" variant="outline" data-testid="record-quote-new"><Plus size={14} /> New quote</Button></Link>}
      </div>
      {items && items.length === 0 && <p className="text-sm text-muted-foreground">No quotes yet.</p>}
      <ul className="divide-y divide-border">
        {items?.map((q) => (
          <li key={q.id}>
            <Link href={`/quotes/${q.id}`} className="-mx-2 flex items-center justify-between gap-3 rounded px-2 py-2.5 hover:bg-secondary/40" data-testid={`record-quote-${q.id}`}>
              <div className="min-w-0">
                <div className="truncate text-sm font-medium">{q.title}</div>
                <div className="text-xs text-muted-foreground"><span className="font-mono">{q.quote_no}</span> · {fmtDate(q.issue_date)}</div>
              </div>
              <div className="flex shrink-0 items-center gap-3"><span className="text-sm font-semibold">{INR2(q.total)}</span><QuotePill status={q.display_status} /></div>
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  );
}

/** Invoices for a customer or a deal, with a New invoice shortcut. */
export function RecordInvoices({ filter, newHref }: { filter: { customer_id?: string; deal_id?: string }; newHref: string }) {
  const { can } = useCurrentUser();
  const [items, setItems] = useState<Invoice[] | null>(null);
  const key = JSON.stringify(filter);

  useEffect(() => {
    if (!can("invoices.view")) return;
    api.get<Invoice[]>("/invoices", { params: { ...filter, limit: 50 } }).then((r) => setItems(r.data)).catch(() => setItems([]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  if (!can("invoices.view")) return null;
  return (
    <Card className="p-5 rounded-lg" data-testid="record-invoices">
      <div className="mb-3 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <ReceiptText size={16} className="text-muted-foreground" />
          <h3 className="font-display font-bold">Invoices</h3>
          {items && <span className="text-xs text-muted-foreground">({items.length})</span>}
        </div>
        {can("invoices.create") && <Link href={newHref}><Button size="sm" variant="outline" data-testid="record-invoice-new"><Plus size={14} /> New invoice</Button></Link>}
      </div>
      {items && items.length === 0 && <p className="text-sm text-muted-foreground">No invoices yet.</p>}
      <ul className="divide-y divide-border">
        {items?.map((i) => (
          <li key={i.id}>
            <Link href={`/invoices/${i.id}`} className="-mx-2 flex items-center justify-between gap-3 rounded px-2 py-2.5 hover:bg-secondary/40" data-testid={`record-invoice-${i.id}`}>
              <div className="min-w-0">
                <div className="truncate text-sm font-medium">{i.title}</div>
                <div className="text-xs text-muted-foreground"><span className="font-mono">{i.invoice_no ?? "Draft"}</span>{i.status === "issued" && i.balance > 0 ? ` · ${INR2(i.balance)} due` : ""}</div>
              </div>
              <div className="flex shrink-0 items-center gap-3"><span className="text-sm font-semibold">{INR2(i.total)}</span><PayPill status={i.payment_status} /></div>
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  );
}

/** What the books say about one trip: revenue, costs and profit, with its supplier bills. Needs finance access. */
export function DealBooksCard({ dealId, onChanged }: { dealId: string; onChanged?: () => void }) {
  const { can } = useCurrentUser();
  const [d, setD] = useState<DealBooks | null>(null);

  useEffect(() => {
    if (!can("finance.view")) return;
    api.get<DealBooks>(`/deals/${dealId}/books`).then((r) => { setD(r.data); onChanged?.(); }).catch(() => setD(null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dealId]);

  if (!can("finance.view") || !d) return null;
  return (
    <Card className="p-5 rounded-lg" data-testid="deal-books">
      <div className="mb-3 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2"><Landmark size={16} className="text-muted-foreground" /><h3 className="font-display font-bold">Books for this trip</h3></div>
        {can("finance.payables") && <Link href={`/accounts/bills?new=1&deal=${dealId}`}><Button size="sm" variant="outline" data-testid="deal-add-bill"><Plus size={14} /> Add supplier bill</Button></Link>}
      </div>
      <dl className="space-y-1.5 text-sm">
        <div className="flex justify-between"><dt className="text-muted-foreground">Revenue (excl. GST)</dt><dd className="font-medium" data-testid="books-revenue">{INR2(d.total_revenue)}</dd></div>
        <div className="flex justify-between"><dt className="text-muted-foreground">Costs booked</dt><dd className="font-medium" data-testid="books-costs">{INR2(d.total_costs)}</dd></div>
        <div className="flex justify-between border-t border-border pt-2 font-bold"><dt>Profit</dt><dd data-testid="books-profit">{INR2(d.profit)}{d.margin_pct != null ? <span className="ml-2 text-xs font-normal text-muted-foreground">{d.margin_pct}%</span> : null}</dd></div>
      </dl>
      {d.bills.length > 0 && (
        <ul className="mt-3 divide-y divide-border border-t border-border">
          {d.bills.map((b) => (
            <li key={b.id}><Link href={`/accounts/bills/${b.id}`} className="-mx-2 flex items-center justify-between gap-3 rounded px-2 py-2 text-sm hover:bg-secondary/40">
              <span className="min-w-0 truncate">{b.supplier?.name} <span className="font-mono text-xs text-muted-foreground">{b.bill_no}</span></span>
              <span className="flex shrink-0 items-center gap-2"><span className="font-semibold">{INR2(b.total)}</span><PayPill status={b.payment_status === "open" ? "unpaid" : b.payment_status} /></span></Link></li>
          ))}
        </ul>
      )}
    </Card>
  );
}
