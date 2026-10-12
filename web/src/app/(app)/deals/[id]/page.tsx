"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState, type ReactNode } from "react";
import { ArrowLeft, ArrowUpRight, Check, Mail, MessageCircle, Pencil, Phone, Trash2, UserRound } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import DealDialog from "@/components/crm/DealDialog";
import CustomerReplies from "@/components/crm/CustomerReplies";
import TripPlanPanel from "@/components/crm/TripPlanPanel";
import { DealBooksCard, RecordActivities, RecordInvoices, RecordNotes, RecordQuotes } from "@/components/crm/RecordPanels";
import { INR, STAGES, StagePill, TRIP_STATUSES, customerTypeLabel, fmtDate } from "@/components/crm/shared";
import type { Customer, Deal, DealStage } from "@/types";

function Stat({ label, value, sub, warn }: { label: string; value: string; sub?: string; warn?: boolean }) {
  return (
    <div className="px-4 py-3">
      <div className="label-caps">{label}</div>
      <div className={cn("mt-0.5 font-display text-xl font-black tracking-tight", warn && "text-red-600 dark:text-red-400")}>{value}</div>
      {sub && <div className="truncate text-[11px] text-muted-foreground">{sub}</div>}
    </div>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="label-caps">{label}</dt>
      <dd className="mt-0.5 break-words text-sm">{children}</dd>
    </div>
  );
}

const TAB_CLS = "rounded-none border-b-2 border-transparent px-3 py-2 text-sm font-medium text-muted-foreground shadow-none data-[state=active]:border-foreground data-[state=active]:bg-transparent data-[state=active]:text-foreground data-[state=active]:shadow-none";
const TABS = ["overview", "billing", "activity"] as const;
type Tab = (typeof TABS)[number];

/** Who the deal is for, with a way to open them and their other deals. */
function CustomerCard({ deal }: { deal: Deal }) {
  const { can } = useCurrentUser();
  const [c, setC] = useState<Customer | null>(null);
  const allowed = can("customers.view");
  const base = deal.customer;

  useEffect(() => {
    if (!allowed || !deal.customer_id) return;
    let live = true;
    api.get<Customer>(`/customers/${deal.customer_id}`).then((r) => { if (live) setC(r.data); }).catch(() => { /* the card still shows the name */ });
    return () => { live = false; };
  }, [allowed, deal.customer_id]);

  const tel = (c?.phone || "").replace(/\D/g, "").slice(-10);
  const wa = ((c?.whatsapp || c?.phone || "").replace(/\D/g, "")).slice(-10);
  const others = (c?.deals ?? []).filter((x) => x.id !== deal.id);

  return (
    <Card className="rounded-lg p-4" data-testid="deal-customer-card">
      <div className="flex items-start justify-between gap-3">
        <h3 className="flex items-center gap-1.5 font-display text-base font-bold"><UserRound size={15} className="text-muted-foreground" aria-hidden /> Customer</h3>
        {allowed && base && (
          <Link href={`/customers/${base.id}`} data-testid="deal-customer-open">
            <Button size="sm" variant="outline" className="h-7">View customer <ArrowUpRight size={13} /></Button>
          </Link>
        )}
      </div>
      {!base && <p className="mt-2 text-sm text-muted-foreground">No customer is linked to this deal.</p>}
      {base && (
        <>
          <div className="mt-2">
            {allowed
              ? <Link href={`/customers/${base.id}`} className="text-base font-semibold underline-offset-2 hover:underline" data-testid="deal-customer-link">{base.name}</Link>
              : <span className="text-base font-semibold">{base.name}</span>}
            <div className="text-xs text-muted-foreground">
              <span className="font-mono">{base.customer_no}</span>{c ? ` · ${customerTypeLabel(c.type)}` : ""}{c?.company ? ` · ${c.company}` : ""}
            </div>
          </div>

          {c && (
            <>
              <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2">
                <Field label="Phone">{c.phone ? <a href={tel.length === 10 ? `tel:+91${tel}` : undefined} className="hover:underline">{c.phone}</a> : "—"}</Field>
                <Field label="E-mail">{c.email ? <a href={`mailto:${c.email}`} className="break-all hover:underline">{c.email}</a> : "—"}</Field>
              </dl>
              <div className="mt-3 flex flex-wrap gap-1.5">
                {tel.length === 10 && <a href={`tel:+91${tel}`}><Button size="sm" variant="outline" className="h-7"><Phone size={13} /> Call</Button></a>}
                {wa.length === 10 && <a href={`https://wa.me/91${wa}`} target="_blank" rel="noreferrer"><Button size="sm" variant="outline" className="h-7"><MessageCircle size={13} /> WhatsApp</Button></a>}
                {c.email && <a href={`mailto:${c.email}`}><Button size="sm" variant="outline" className="h-7"><Mail size={13} /> E-mail</Button></a>}
              </div>
              <div className="mt-3 grid grid-cols-3 divide-x divide-border rounded-md border border-border text-center">
                <div className="px-2 py-1.5"><div className="text-sm font-bold">{c.deals_count}</div><div className="text-[11px] text-muted-foreground">deals</div></div>
                <div className="px-2 py-1.5"><div className="text-sm font-bold">{INR(c.won_value)}</div><div className="text-[11px] text-muted-foreground">won</div></div>
                <div className="px-2 py-1.5"><div className={cn("text-sm font-bold", (c.outstanding ?? 0) > 0 && "text-red-600 dark:text-red-400")}>{INR(c.outstanding)}</div><div className="text-[11px] text-muted-foreground">outstanding</div></div>
              </div>
              {others.length > 0 && (
                <div className="mt-3" data-testid="deal-other-deals">
                  <div className="label-caps mb-0.5">Their other deals</div>
                  <ul className="divide-y divide-border">
                    {others.slice(0, 4).map((x) => (
                      <li key={x.id}>
                        <Link href={`/deals/${x.id}`} className="-mx-2 flex items-center justify-between gap-2 rounded px-2 py-1.5 text-sm hover:bg-secondary/40">
                          <span className="min-w-0 truncate">{x.name} <span className="font-mono text-xs text-muted-foreground">{x.deal_no}</span></span>
                          <StagePill stage={x.stage} />
                        </Link>
                      </li>
                    ))}
                  </ul>
                  {others.length > 4 && <Link href={`/customers/${base.id}#trips`} className="mt-1 inline-block text-xs text-muted-foreground underline underline-offset-2">All {others.length + 1} deals</Link>}
                </div>
              )}
            </>
          )}
        </>
      )}
    </Card>
  );
}

export default function DealDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const [d, setD] = useState<Deal | null>(null);
  const [error, setError] = useState("");
  const [edit, setEdit] = useState(false);
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState<Tab>("overview");

  const load = useCallback(async () => {
    try { const { data } = await api.get<Deal>(`/deals/${id}`); setD(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  // the open tab is kept in the address (#billing) so a refresh or the back button returns to it
  useEffect(() => {
    const h = window.location.hash.replace("#", "") as Tab;
    if ((TABS as readonly string[]).includes(h)) setTab(h);
  }, []);
  const pickTab = (v: string) => { setTab(v as Tab); window.history.replaceState(null, "", `#${v}`); };

  const moveTo = async (stage: DealStage) => {
    if (!d || d.stage === stage) return;
    let reason: string | undefined;
    if (stage === "lost") {
      const r = window.prompt("Why was this deal lost?");
      if (!r || !r.trim()) return;
      reason = r.trim();
    }
    setBusy(true);
    try {
      const { data } = await api.patch<Deal>(`/deals/${d.id}`, { stage, ...(reason ? { lost_reason: reason } : {}) });
      setD(data); toast.success(`Moved to ${STAGES.find((s) => s.value === stage)?.label}`);
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const setTrip = async (trip_status: string) => {
    if (!d) return;
    try { const { data } = await api.patch<Deal>(`/deals/${d.id}`, { trip_status }); setD(data); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const remove = async () => {
    if (!d || !window.confirm(`Delete deal ${d.deal_no}? This cannot be undone.`)) return;
    try { await api.delete(`/deals/${d.id}`); toast.success("Deal deleted"); router.replace("/deals"); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  if (error) return <div className="space-y-3"><Button variant="ghost" size="sm" onClick={() => router.push("/deals")}><ArrowLeft size={16} /> Deals</Button><div className="text-sm text-destructive">{error}</div></div>;
  if (!d) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;

  const canEdit = can("deals.edit");
  const pipeline = STAGES.filter((s) => s.value !== "lost");
  const idx = pipeline.findIndex((s) => s.value === d.stage);
  const daysToTravel = d.travel_date ? Math.round((new Date(d.travel_date.slice(0, 10) + "T00:00:00").getTime() - new Date().setHours(0, 0, 0, 0)) / 86400000) : null;

  return (
    <div className="space-y-3" data-testid="deal-detail">
      {/* header */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <Button variant="ghost" size="sm" onClick={() => router.push("/deals")} className="-ml-3 h-7"><ArrowLeft size={14} /> Deals</Button>
          <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl" data-testid="deal-title">{d.name}</h1>
            <StagePill stage={d.stage} />
          </div>
          <p className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
            <span className="font-mono">{d.deal_no}</span>
            {d.customer && <><span aria-hidden>·</span><Link href={`/customers/${d.customer.id}`} className="font-medium text-foreground hover:underline" data-testid="deal-header-customer">{d.customer.name}</Link></>}
            {d.destination && <><span aria-hidden>·</span><span>{d.destination}</span></>}
            {d.travel_date && <><span aria-hidden>·</span><span>Travel {fmtDate(d.travel_date)}</span></>}
            {d.lead_id && <><span aria-hidden>·</span><Link href={`/leads/${d.lead_id}`} className="font-mono hover:underline" data-testid="deal-lead-link">from {d.lead_id}</Link></>}
          </p>
        </div>
        <div className="flex items-center gap-1.5">
          {canEdit && <Button size="sm" variant="outline" onClick={() => setEdit(true)} data-testid="deal-edit"><Pencil size={14} /> Edit</Button>}
          {can("deals.delete") && <Button size="sm" variant="ghost" className="text-destructive" onClick={remove} title="Delete deal" aria-label="Delete deal" data-testid="deal-delete"><Trash2 size={14} /></Button>}
        </div>
      </div>

      {/* stage: one slim line */}
      <Card className="rounded-lg px-3 py-2.5" data-testid="deal-stepper">
        <ol className="flex flex-wrap items-center gap-1.5">
          {pipeline.map((s, i) => {
            const done = d.stage !== "lost" && i < idx;
            const current = d.stage === s.value;
            return (
              <li key={s.value}>
                <button type="button" disabled={!canEdit || busy} onClick={() => moveTo(s.value)} aria-current={current ? "step" : undefined} data-testid={`stage-${s.value}`}
                        className={cn("inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors disabled:cursor-default",
                          current ? "border-foreground bg-foreground text-background" : done ? "border-border bg-secondary text-foreground" : "border-border text-muted-foreground hover:bg-secondary")}>
                  {done && <Check size={12} />} {s.label}
                </button>
              </li>
            );
          })}
          {d.stage === "won" && (
            <li className="ml-2 flex flex-wrap items-center gap-1 border-l border-border pl-3">
              <span className="mr-1 text-xs text-muted-foreground">Trip:</span>
              {TRIP_STATUSES.map((t) => (
                <button key={t.value} type="button" disabled={!canEdit} onClick={() => setTrip(t.value)} data-testid={`trip-${t.value}`}
                        className={cn("rounded-full border px-2 py-0.5 text-[11px]", d.trip_status === t.value ? "border-foreground bg-foreground text-background" : "border-border text-muted-foreground hover:bg-secondary disabled:hover:bg-transparent")}>{t.label}</button>
              ))}
            </li>
          )}
          {canEdit && d.stage !== "lost" && (
            <li className="ml-auto"><Button size="sm" variant="outline" className="h-7" disabled={busy} onClick={() => moveTo("lost")} data-testid="stage-lost">Mark lost</Button></li>
          )}
        </ol>
        {d.stage === "lost" && <p className="mt-2 text-sm text-red-700 dark:text-red-400">Lost{d.lost_reason ? `: ${d.lost_reason}` : ""}. {canEdit ? "Click a stage above to reopen it." : ""}</p>}
      </Card>

      {/* the money at a glance: one strip instead of four cards */}
      <Card className="grid grid-cols-2 divide-border overflow-hidden rounded-lg sm:grid-cols-5 sm:divide-x" data-testid="deal-stats">
        <Stat label="Selling" value={INR(d.selling_amount)} />
        <Stat label="Supplier cost" value={d.supplier_cost ? INR(d.supplier_cost) : "—"} />
        <Stat label="Margin" value={d.supplier_cost ? INR(d.margin) : "—"} warn={!!d.supplier_cost && d.margin < 0} sub={d.supplier_cost && d.selling_amount ? `${Math.round((d.margin / d.selling_amount) * 100)}% of selling` : undefined} />
        <Stat label="Chance of winning" value={`${d.probability}%`} sub={`${INR(d.weighted)} weighted`} />
        <Stat label={daysToTravel !== null && daysToTravel >= 0 ? "Travel in" : "Expected close"}
              value={daysToTravel !== null && daysToTravel >= 0 ? `${daysToTravel} day${daysToTravel === 1 ? "" : "s"}` : fmtDate(d.expected_close)}
              sub={daysToTravel !== null && daysToTravel >= 0 ? fmtDate(d.travel_date) : d.overdue ? "overdue" : undefined} warn={d.overdue && !(daysToTravel !== null && daysToTravel >= 0)} />
      </Card>

      <Tabs value={tab} onValueChange={pickTab}>
        <TabsList className="h-auto w-full justify-start gap-0 overflow-x-auto rounded-none border-b border-border bg-transparent p-0" data-testid="deal-tabs">
          <TabsTrigger value="overview" className={TAB_CLS} data-testid="tab-overview">Overview</TabsTrigger>
          <TabsTrigger value="billing" className={TAB_CLS} data-testid="tab-billing">Quotes &amp; invoices</TabsTrigger>
          <TabsTrigger value="activity" className={TAB_CLS} data-testid="tab-activity">Activity &amp; notes</TabsTrigger>
        </TabsList>

        {/* Overview: who it is for, what the trip is, and how the trip is going */}
        <TabsContent value="overview" className="mt-3 space-y-3">
          <div className="grid items-start gap-3 lg:grid-cols-2">
            <CustomerCard deal={d} />
            <Card className="rounded-lg p-4" data-testid="deal-trip-card">
              <h3 className="mb-3 font-display text-base font-bold">Trip</h3>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                <Field label="Destination">{d.destination || "—"}</Field>
                <Field label="Travel date">{fmtDate(d.travel_date)}{d.duration_days ? ` · ${Math.max(0, d.duration_days - 1)}N / ${d.duration_days}D` : ""}</Field>
                <Field label="Travellers">{d.adults} adult{d.adults === 1 ? "" : "s"}{d.children ? `, ${d.children} child${d.children === 1 ? "" : "ren"}` : ""}</Field>
                <Field label="Expected close"><span className={cn(d.overdue && "font-medium text-red-600 dark:text-red-400")}>{fmtDate(d.expected_close)}{d.overdue ? " (overdue)" : ""}</span></Field>
                <Field label="Owner">{d.owner?.name ?? "Unassigned"}</Field>
                <Field label="Created">{fmtDate(d.created_at)}</Field>
                {d.notes && <div className="col-span-2"><Field label="Notes"><span className="whitespace-pre-wrap">{d.notes}</span></Field></div>}
              </dl>
            </Card>
          </div>
          {can("trips.view") && d.stage !== "lost" && <TripPlanPanel dealId={d.id} onChanged={() => void load()} />}
        </TabsContent>

        {/* Billing: what was quoted, billed and booked */}
        <TabsContent value="billing" className="mt-3">
          <div className="grid items-start gap-3 lg:grid-cols-2">
            <RecordQuotes filter={{ deal_id: d.id }} newHref={`/quotes/new?deal=${d.id}`} />
            <RecordInvoices filter={{ deal_id: d.id }} newHref={`/invoices/new?deal=${d.id}`} />
            <div className="lg:col-span-2"><DealBooksCard dealId={d.id} /></div>
          </div>
        </TabsContent>

        {/* Activity: follow-ups, the customer's replies, notes */}
        <TabsContent value="activity" className="mt-3 space-y-3">
          <CustomerReplies base={`/deals/${d.id}`} />
          <div className="grid items-start gap-3 lg:grid-cols-2">
            <RecordActivities base={`/deals/${d.id}`} link={{ deal_id: d.id, customer_id: d.customer_id }} />
            <RecordNotes base={`/deals/${d.id}`} canWrite={canEdit} />
          </div>
        </TabsContent>
      </Tabs>

      {canEdit && <DealDialog open={edit} onOpenChange={setEdit} deal={d} onSaved={(x) => setD(x)} />}
    </div>
  );
}
