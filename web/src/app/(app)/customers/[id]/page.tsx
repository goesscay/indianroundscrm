"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState, type ReactNode } from "react";
import { ArrowLeft, Mail, MapPin, MessageCircle, Pencil, Phone, Plus, Trash2, Users } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import CustomerDialog from "@/components/crm/CustomerDialog";
import DealDialog from "@/components/crm/DealDialog";
import { RecordActivities, RecordInvoices, RecordNotes, RecordQuotes } from "@/components/crm/RecordPanels";
import CustomerReplies from "@/components/crm/CustomerReplies";
import CustomerTrips from "@/components/crm/CustomerTrips";
import PaymentDialog from "@/components/crm/PaymentDialog";
import { INR, StagePill, customerTypeLabel, fmtDate } from "@/components/crm/shared";
import StatusPill from "@/components/app/StatusPill";
import { cn } from "@/lib/utils";
import type { Customer } from "@/types";

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
const TABS = ["overview", "trips", "billing", "activity"] as const;
type Tab = (typeof TABS)[number];

export default function CustomerDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const [c, setC] = useState<Customer | null>(null);
  const [error, setError] = useState("");
  const [edit, setEdit] = useState(false);
  const [newDeal, setNewDeal] = useState(false);
  const [advance, setAdvance] = useState(false);
  const [tab, setTab] = useState<Tab>("overview");

  const load = useCallback(async () => {
    try { const { data } = await api.get<Customer>(`/customers/${id}`); setC(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  // the open tab is kept in the address (#trips) so a refresh or the back button returns to it
  useEffect(() => {
    const h = window.location.hash.replace("#", "") as Tab;
    if ((TABS as readonly string[]).includes(h)) setTab(h);
  }, []);
  const pickTab = (v: string) => { setTab(v as Tab); window.history.replaceState(null, "", `#${v}`); };

  const remove = async () => {
    if (!c || !window.confirm(`Delete ${c.name}? This cannot be undone.`)) return;
    try { await api.delete(`/customers/${c.id}`); toast.success("Customer deleted"); router.replace("/customers"); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  if (error) return <div className="space-y-3"><Button variant="ghost" size="sm" onClick={() => router.push("/customers")}><ArrowLeft size={16} /> Customers</Button><div className="text-sm text-destructive">{error}</div></div>;
  if (!c) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;

  const tel = (c.phone || "").replace(/\D/g, "").slice(-10);
  const wa = ((c.whatsapp || c.phone || "").replace(/\D/g, "")).slice(-10);
  const address = [c.address_line, c.city, c.state, c.pincode, c.country].filter(Boolean).join(", ");
  const canEdit = can("customers.edit");
  const deals = c.deals ?? [];
  const open = deals.filter((d) => d.stage !== "won" && d.stage !== "lost").length;
  const won = deals.filter((d) => d.stage === "won").length;
  const count = (n: number) => (n > 0 ? <span className="ml-1.5 rounded-full bg-secondary px-1.5 text-[11px] font-semibold text-foreground">{n}</span> : null);

  return (
    <div className="space-y-3" data-testid="customer-detail">
      {/* header */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <Button variant="ghost" size="sm" onClick={() => router.push("/customers")} className="-ml-3 h-7"><ArrowLeft size={14} /> Customers</Button>
          <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl" data-testid="customer-title">{c.name}</h1>
            <span className="rounded border border-border bg-secondary px-2 py-0.5 text-xs font-medium">{customerTypeLabel(c.type)}</span>
            {c.status === "inactive" && <span className="rounded border border-border px-2 py-0.5 text-xs text-muted-foreground">Inactive</span>}
          </div>
          <p className="mt-0.5 text-xs text-muted-foreground">
            <span className="font-mono">{c.customer_no}</span>{[c.company, c.city, c.owner ? `Owner: ${c.owner.name}` : "Unassigned"].filter(Boolean).map((x) => ` · ${x}`).join("")}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          {tel.length === 10 && <a href={`tel:+91${tel}`}><Button size="sm" variant="outline"><Phone size={14} /> Call</Button></a>}
          {wa.length === 10 && <a href={`https://wa.me/91${wa}`} target="_blank" rel="noreferrer"><Button size="sm" variant="outline"><MessageCircle size={14} /> WhatsApp</Button></a>}
          {c.email && <a href={`mailto:${c.email}`}><Button size="sm" variant="outline"><Mail size={14} /> E-mail</Button></a>}
          {canEdit && <Button size="sm" variant="outline" onClick={() => setEdit(true)} data-testid="customer-edit"><Pencil size={14} /> Edit</Button>}
          {can("finance.view") && <Link href={`/accounts/statements?type=customer&id=${c.id}`}><Button size="sm" variant="outline" data-testid="customer-statement">Statement</Button></Link>}
          {can("receipts.record") && <Button size="sm" variant="outline" onClick={() => setAdvance(true)} data-testid="customer-advance">Record advance</Button>}
          {can("deals.create") && <Button size="sm" onClick={() => setNewDeal(true)} data-testid="customer-new-deal"><Plus size={14} /> New deal</Button>}
        </div>
      </div>

      {/* money at a glance: one strip instead of five cards */}
      <Card className="grid grid-cols-2 divide-border overflow-hidden rounded-lg sm:grid-cols-5 sm:divide-x" data-testid="customer-stats">
        <Stat label="Open pipeline" value={INR(c.open_pipeline)} sub={`${open} open deal${open === 1 ? "" : "s"}`} />
        <Stat label="Won" value={INR(c.won_value)} sub={`${won} trip${won === 1 ? "" : "s"} booked`} />
        <Stat label="Deals" value={String(c.deals_count)} sub="all time" />
        <Stat label="Invoiced" value={INR(c.invoiced)} sub="net of credit notes" />
        <Stat label="Outstanding" value={INR(c.outstanding)} sub={c.advances ? `${INR(c.advances)} advance held` : "still to be paid"} warn={(c.outstanding ?? 0) > 0} />
      </Card>

      <Tabs value={tab} onValueChange={pickTab}>
        <TabsList className="h-auto w-full justify-start gap-0 overflow-x-auto rounded-none border-b border-border bg-transparent p-0" data-testid="customer-tabs">
          <TabsTrigger value="overview" className={TAB_CLS} data-testid="tab-overview">Overview</TabsTrigger>
          <TabsTrigger value="trips" className={TAB_CLS} data-testid="tab-trips">Trips &amp; deals{count(deals.length)}</TabsTrigger>
          <TabsTrigger value="billing" className={TAB_CLS} data-testid="tab-billing">Quotes &amp; invoices</TabsTrigger>
          <TabsTrigger value="activity" className={TAB_CLS} data-testid="tab-activity">Activity &amp; notes</TabsTrigger>
        </TabsList>

        {/* Overview: who they are */}
        <TabsContent value="overview" className="mt-3 space-y-3">
          <div className="grid gap-3 lg:grid-cols-2">
            <Card className="rounded-lg p-4">
              <h3 className="mb-3 font-display text-base font-bold">Details</h3>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                <Field label="Phone">{c.phone || "—"}</Field>
                <Field label="E-mail">{c.email || "—"}</Field>
                {c.whatsapp && <Field label="WhatsApp">{c.whatsapp}</Field>}
                {c.company && <Field label="Company">{c.company}</Field>}
                {c.gstin && <Field label="GSTIN"><span className="font-mono">{c.gstin}</span></Field>}
                <Field label="Source">{c.source || "—"}</Field>
                <div className="col-span-2"><Field label="Address">{address ? <span className="inline-flex items-start gap-1"><MapPin size={13} className="mt-0.5 shrink-0 text-muted-foreground" />{address}</span> : "—"}</Field></div>
                {c.notes && <div className="col-span-2"><Field label="Earlier notes"><span className="whitespace-pre-wrap">{c.notes}</span></Field></div>}
              </dl>
              {(c.contacts?.length ?? 0) > 0 && (
                <div className="mt-4 border-t border-border pt-3">
                  <h4 className="label-caps mb-1 flex items-center gap-1.5"><Users size={13} /> Contact people</h4>
                  <ul className="divide-y divide-border">
                    {c.contacts!.map((p) => (
                      <li key={p.id} className="py-1.5 text-sm">
                        <span className="font-medium">{p.name}</span>{p.is_primary && <span className="ml-2 rounded bg-secondary px-1.5 py-0.5 text-[10px] uppercase tracking-wide">Primary</span>}
                        <span className="text-xs text-muted-foreground"> · {[p.designation, p.phone, p.email].filter(Boolean).join(" · ") || "—"}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </Card>

            <Card className="rounded-lg p-4" data-testid="customer-travellers">
              <h3 className="mb-2 font-display text-base font-bold">Travellers <span className="text-xs font-normal text-muted-foreground">({c.travellers?.length ?? 0})</span></h3>
              {(c.travellers?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">No travellers saved yet.{canEdit ? " Use Edit to add them." : ""}</p>}
              <ul className="divide-y divide-border">
                {c.travellers?.map((t) => (
                  <li key={t.id} className="py-2 text-sm">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                      <span className="font-medium">{t.name}{t.relation ? <span className="font-normal text-muted-foreground"> · {t.relation}</span> : null}</span>
                      <span className="text-xs text-muted-foreground">{[t.dob ? `DOB ${fmtDate(t.dob)}` : "", t.nationality].filter(Boolean).join(" · ")}</span>
                    </div>
                    {(t.passport_no || t.preferences) && (
                      <div className="mt-0.5 text-xs text-muted-foreground">
                        {t.passport_no && <span>Passport <span className="font-mono">{t.passport_no}</span>{t.passport_expiry ? ` (exp ${fmtDate(t.passport_expiry)})` : ""}</span>}
                        {t.passport_no && t.preferences ? " · " : ""}{t.preferences}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </Card>
          </div>

          {(c.leads?.length ?? 0) > 0 && (
            <Card className="rounded-lg p-4">
              <h3 className="mb-1 font-display text-base font-bold">Came from leads</h3>
              <ul className="divide-y divide-border">
                {c.leads!.map((l) => (
                  <li key={l.lead_id}>
                    <Link href={`/leads/${l.lead_id}`} className="flex items-center justify-between gap-3 py-1.5 text-sm hover:underline">
                      <span><span className="font-mono font-semibold">{l.lead_id}</span> <span className="text-muted-foreground">{l.destination}</span></span>
                      <StatusPill status={l.lead_status} />
                    </Link>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </TabsContent>

        {/* Trips: deals and what has been paid on them */}
        <TabsContent value="trips" className="mt-3">
          <div className="grid items-start gap-3 lg:grid-cols-2">
            <Card className="rounded-lg p-4" data-testid="customer-deals">
              <h3 className="mb-1 font-display text-base font-bold">Deals <span className="text-xs font-normal text-muted-foreground">({deals.length})</span></h3>
              {deals.length === 0 && <p className="text-sm text-muted-foreground">No deals yet.</p>}
              <ul className="divide-y divide-border">
                {deals.map((d) => (
                  <li key={d.id}>
                    <Link href={`/deals/${d.id}`} className="-mx-2 flex items-center justify-between gap-3 rounded px-2 py-2 hover:bg-secondary/40" data-testid={`customer-deal-${d.id}`}>
                      <div className="min-w-0">
                        <div className="truncate text-sm font-medium">{d.name}</div>
                        <div className="text-xs text-muted-foreground"><span className="font-mono">{d.deal_no}</span>{d.travel_date ? ` · Travel ${fmtDate(d.travel_date)}` : ""}</div>
                      </div>
                      <div className="flex shrink-0 items-center gap-3">
                        <span className="text-sm font-semibold">{INR(d.selling_amount)}</span>
                        <StagePill stage={d.stage} />
                      </div>
                    </Link>
                  </li>
                ))}
              </ul>
            </Card>
            <div className="space-y-3"><CustomerTrips customerId={c.id} /></div>
          </div>
        </TabsContent>

        {/* Billing: quotes and invoices side by side */}
        <TabsContent value="billing" className="mt-3">
          <div className="grid items-start gap-3 lg:grid-cols-2">
            <RecordQuotes filter={{ customer_id: c.id }} newHref={`/quotes/new?customer=${c.id}`} />
            <RecordInvoices filter={{ customer_id: c.id }} newHref={`/invoices/new?customer=${c.id}`} />
          </div>
        </TabsContent>

        {/* Activity: what the team is doing and what the customer said */}
        <TabsContent value="activity" className="mt-3 space-y-3">
          <CustomerReplies base={`/customers/${c.id}`} />
          <div className="grid items-start gap-3 lg:grid-cols-2">
            <RecordActivities base={`/customers/${c.id}`} link={{ customer_id: c.id }} />
            <RecordNotes base={`/customers/${c.id}`} canWrite={canEdit} />
          </div>
        </TabsContent>
      </Tabs>

      {canEdit && <CustomerDialog open={edit} onOpenChange={setEdit} customer={c} onSaved={() => void load()} />}
      <PaymentDialog open={advance} onOpenChange={setAdvance} endpoint={`/customers/${c.id}/advances`} kind="advance" onSaved={() => void load()} />
      <DealDialog open={newDeal} onOpenChange={setNewDeal} customer={{ id: c.id, customer_no: c.customer_no, name: c.name }} onSaved={() => void load()} />
      {can("customers.delete") && (
        <div className="pt-1"><Button variant="ghost" size="sm" className="text-destructive" onClick={remove} data-testid="customer-delete"><Trash2 size={14} /> Delete customer</Button></div>
      )}
    </div>
  );
}
