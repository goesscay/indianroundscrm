"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Mail, MapPin, MessageCircle, Pencil, Phone, Plus, Trash2, Users } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import CustomerDialog from "@/components/crm/CustomerDialog";
import DealDialog from "@/components/crm/DealDialog";
import { RecordActivities, RecordInvoices, RecordNotes, RecordQuotes } from "@/components/crm/RecordPanels";
import CustomerReplies from "@/components/crm/CustomerReplies";
import CustomerTrips from "@/components/crm/CustomerTrips";
import PaymentDialog from "@/components/crm/PaymentDialog";
import { INR, StagePill, customerTypeLabel, fmtDate } from "@/components/crm/shared";
import StatusPill from "@/components/app/StatusPill";
import type { Customer } from "@/types";

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <Card className="rounded-lg p-4">
      <div className="label-caps">{label}</div>
      <div className="mt-1 font-display text-2xl font-black tracking-tight">{value}</div>
      {sub && <div className="text-xs text-muted-foreground">{sub}</div>}
    </Card>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-[110px_1fr] gap-2 py-1.5 text-sm">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="min-w-0 break-words">{children}</dd>
    </div>
  );
}

export default function CustomerDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const [c, setC] = useState<Customer | null>(null);
  const [error, setError] = useState("");
  const [edit, setEdit] = useState(false);
  const [newDeal, setNewDeal] = useState(false);
  const [advance, setAdvance] = useState(false);

  const load = useCallback(async () => {
    try { const { data } = await api.get<Customer>(`/customers/${id}`); setC(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

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

  return (
    <div className="space-y-6" data-testid="customer-detail">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.push("/customers")} className="-ml-3 mb-2"><ArrowLeft size={16} /> Customers</Button>
          <div className="label-caps">Customer · {c.customer_no}</div>
          <div className="mt-1 flex flex-wrap items-center gap-3">
            <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight" data-testid="customer-title">{c.name}</h1>
            <span className="rounded border border-border bg-secondary px-2 py-0.5 text-xs font-medium">{customerTypeLabel(c.type)}</span>
            {c.status === "inactive" && <span className="rounded border border-border px-2 py-0.5 text-xs text-muted-foreground">Inactive</span>}
          </div>
          <p className="mt-2 text-sm text-muted-foreground">{[c.company, c.city, c.owner ? `Owner: ${c.owner.name}` : "Unassigned"].filter(Boolean).join(" · ")}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {tel.length === 10 && <a href={`tel:+91${tel}`}><Button variant="outline"><Phone size={16} /> Call</Button></a>}
          {wa.length === 10 && <a href={`https://wa.me/91${wa}`} target="_blank" rel="noreferrer"><Button variant="outline"><MessageCircle size={16} /> WhatsApp</Button></a>}
          {c.email && <a href={`mailto:${c.email}`}><Button variant="outline"><Mail size={16} /> E-mail</Button></a>}
          {canEdit && <Button variant="outline" onClick={() => setEdit(true)} data-testid="customer-edit"><Pencil size={16} /> Edit</Button>}
          {can("finance.view") && <Link href={`/accounts/statements?type=customer&id=${c.id}`}><Button variant="outline" data-testid="customer-statement">Statement</Button></Link>}
          {can("receipts.record") && <Button variant="outline" onClick={() => setAdvance(true)} data-testid="customer-advance">Record advance</Button>}
          {can("deals.create") && <Button onClick={() => setNewDeal(true)} data-testid="customer-new-deal"><Plus size={16} /> New deal</Button>}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
        <Stat label="Open pipeline" value={INR(c.open_pipeline)} sub={`${(c.deals ?? []).filter((d) => d.stage !== "won" && d.stage !== "lost").length} open deals`} />
        <Stat label="Won" value={INR(c.won_value)} sub={`${(c.deals ?? []).filter((d) => d.stage === "won").length} trips booked`} />
        <Stat label="Deals" value={String(c.deals_count)} sub="all time" />
        <Stat label="Invoiced" value={INR(c.invoiced)} sub="net of credit notes" />
        <Stat label="Outstanding" value={INR(c.outstanding)} sub={c.advances ? `${INR(c.advances)} advance held` : "still to be paid"} />
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
        <div className="space-y-6">
          <Card className="rounded-lg p-5">
            <h3 className="mb-2 font-display font-bold">Details</h3>
            <dl>
              <Row label="Phone">{c.phone || "—"}</Row>
              {c.whatsapp && <Row label="WhatsApp">{c.whatsapp}</Row>}
              <Row label="E-mail">{c.email || "—"}</Row>
              {c.company && <Row label="Company">{c.company}</Row>}
              {c.gstin && <Row label="GSTIN"><span className="font-mono">{c.gstin}</span></Row>}
              <Row label="Source">{c.source || "—"}</Row>
              <Row label="Address">{address ? <span className="inline-flex items-start gap-1"><MapPin size={13} className="mt-0.5 shrink-0 text-muted-foreground" />{address}</span> : "—"}</Row>
              {c.notes && <Row label="Earlier notes"><span className="whitespace-pre-wrap">{c.notes}</span></Row>}
            </dl>
          </Card>

          {(c.contacts?.length ?? 0) > 0 && (
            <Card className="rounded-lg p-5">
              <h3 className="mb-2 flex items-center gap-2 font-display font-bold"><Users size={16} className="text-muted-foreground" /> Contact people</h3>
              <ul className="divide-y divide-border">
                {c.contacts!.map((p) => (
                  <li key={p.id} className="py-2 text-sm">
                    <div className="font-medium">{p.name}{p.is_primary && <span className="ml-2 rounded bg-secondary px-1.5 py-0.5 text-[10px] uppercase tracking-wide">Primary</span>}</div>
                    <div className="text-xs text-muted-foreground">{[p.designation, p.phone, p.email].filter(Boolean).join(" · ") || "—"}</div>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          <Card className="rounded-lg p-5" data-testid="customer-travellers">
            <h3 className="mb-2 font-display font-bold">Travellers <span className="text-xs font-normal text-muted-foreground">({c.travellers?.length ?? 0})</span></h3>
            {(c.travellers?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">No travellers saved yet.{canEdit ? " Use Edit to add them." : ""}</p>}
            <ul className="divide-y divide-border">
              {c.travellers?.map((t) => (
                <li key={t.id} className="py-2.5 text-sm">
                  <div className="font-medium">{t.name}{t.relation ? <span className="font-normal text-muted-foreground"> · {t.relation}</span> : null}</div>
                  <div className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                    {t.dob && <span>DOB {fmtDate(t.dob)}</span>}
                    {t.nationality && <span>{t.nationality}</span>}
                    {t.passport_no && <span>Passport <span className="font-mono">{t.passport_no}</span>{t.passport_expiry ? ` (exp ${fmtDate(t.passport_expiry)})` : ""}</span>}
                  </div>
                  {t.preferences && <div className="mt-0.5 text-xs">{t.preferences}</div>}
                </li>
              ))}
            </ul>
          </Card>
        </div>

        <div className="space-y-6">
          <Card className="rounded-lg p-5" data-testid="customer-deals">
            <div className="mb-2 flex items-center justify-between">
              <h3 className="font-display font-bold">Deals <span className="text-xs font-normal text-muted-foreground">({c.deals?.length ?? 0})</span></h3>
            </div>
            {(c.deals?.length ?? 0) === 0 && <p className="text-sm text-muted-foreground">No deals yet.</p>}
            <ul className="divide-y divide-border">
              {c.deals?.map((d) => (
                <li key={d.id}>
                  <Link href={`/deals/${d.id}`} className="flex items-center justify-between gap-3 py-2.5 hover:bg-secondary/40 -mx-2 px-2 rounded" data-testid={`customer-deal-${d.id}`}>
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

          {(c.leads?.length ?? 0) > 0 && (
            <Card className="rounded-lg p-5">
              <h3 className="mb-2 font-display font-bold">Came from leads</h3>
              <ul className="divide-y divide-border">
                {c.leads!.map((l) => (
                  <li key={l.lead_id}>
                    <Link href={`/leads/${l.lead_id}`} className="flex items-center justify-between gap-3 py-2 text-sm hover:underline">
                      <span><span className="font-mono font-semibold">{l.lead_id}</span> <span className="text-muted-foreground">{l.destination}</span></span>
                      <StatusPill status={l.lead_status} />
                    </Link>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          <RecordQuotes filter={{ customer_id: c.id }} newHref={`/quotes/new?customer=${c.id}`} />
          <RecordInvoices filter={{ customer_id: c.id }} newHref={`/invoices/new?customer=${c.id}`} />
          <CustomerTrips customerId={c.id} />
          <CustomerReplies base={`/customers/${c.id}`} />
          <RecordActivities base={`/customers/${c.id}`} link={{ customer_id: c.id }} />
          <RecordNotes base={`/customers/${c.id}`} canWrite={canEdit} />
        </div>
      </div>

      {canEdit && <CustomerDialog open={edit} onOpenChange={setEdit} customer={c} onSaved={() => void load()} />}
      <PaymentDialog open={advance} onOpenChange={setAdvance} endpoint={`/customers/${c.id}/advances`} kind="advance" onSaved={() => void load()} />
      <DealDialog open={newDeal} onOpenChange={setNewDeal} customer={{ id: c.id, customer_no: c.customer_no, name: c.name }} onSaved={() => void load()} />
      {can("customers.delete") && (
        <div className="pt-2"><Button variant="ghost" size="sm" className="text-destructive" onClick={remove} data-testid="customer-delete"><Trash2 size={14} /> Delete customer</Button></div>
      )}
    </div>
  );
}
