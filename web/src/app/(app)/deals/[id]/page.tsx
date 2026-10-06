"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Check, Pencil, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import DealDialog from "@/components/crm/DealDialog";
import { RecordActivities, RecordNotes, RecordQuotes } from "@/components/crm/RecordPanels";
import { INR, STAGES, StagePill, TRIP_STATUSES, fmtDate } from "@/components/crm/shared";
import type { Deal, DealStage } from "@/types";

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-[130px_1fr] gap-2 py-1.5 text-sm">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="min-w-0 break-words">{children}</dd>
    </div>
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

  const load = useCallback(async () => {
    try { const { data } = await api.get<Deal>(`/deals/${id}`); setD(data); setError(""); }
    catch (e) { setError(formatApiError(e)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

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

  return (
    <div className="space-y-6" data-testid="deal-detail">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.push("/deals")} className="-ml-3 mb-2"><ArrowLeft size={16} /> Deals</Button>
          <div className="label-caps">Deal · {d.deal_no}</div>
          <div className="mt-1 flex flex-wrap items-center gap-3">
            <h1 className="font-display text-3xl font-black tracking-tight sm:text-4xl" data-testid="deal-title">{d.name}</h1>
            <StagePill stage={d.stage} />
          </div>
          <p className="mt-2 text-sm text-muted-foreground">
            {d.customer && <Link href={`/customers/${d.customer.id}`} className="font-medium text-foreground hover:underline" data-testid="deal-customer-link">{d.customer.name}</Link>}
            {d.destination ? ` · ${d.destination}` : ""}{d.travel_date ? ` · Travel ${fmtDate(d.travel_date)}` : ""}
          </p>
        </div>
        <div className="flex gap-2">
          {canEdit && <Button variant="outline" onClick={() => setEdit(true)} data-testid="deal-edit"><Pencil size={16} /> Edit</Button>}
        </div>
      </div>

      {/* Stage stepper */}
      <Card className="rounded-lg p-4" data-testid="deal-stepper">
        <ol className="flex flex-wrap items-center gap-2">
          {pipeline.map((s, i) => {
            const done = d.stage !== "lost" && i < idx;
            const current = d.stage === s.value;
            return (
              <li key={s.value}>
                <button type="button" disabled={!canEdit || busy} onClick={() => moveTo(s.value)} aria-current={current ? "step" : undefined} data-testid={`stage-${s.value}`}
                        className={cn("inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors disabled:cursor-default",
                          current ? "border-foreground bg-foreground text-background" : done ? "border-border bg-secondary text-foreground" : "border-border text-muted-foreground hover:bg-secondary")}>
                  {done && <Check size={13} />} {s.label}
                </button>
              </li>
            );
          })}
          {canEdit && d.stage !== "lost" && (
            <li className="ml-auto"><Button size="sm" variant="outline" disabled={busy} onClick={() => moveTo("lost")} data-testid="stage-lost">Mark lost</Button></li>
          )}
        </ol>
        {d.stage === "lost" && <p className="mt-3 text-sm text-red-700 dark:text-red-400">Lost{d.lost_reason ? `: ${d.lost_reason}` : ""}. {canEdit ? "Click a stage above to reopen it." : ""}</p>}
        {d.stage === "won" && (
          <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <span className="text-muted-foreground">Trip status:</span>
            {TRIP_STATUSES.map((t) => (
              <button key={t.value} type="button" disabled={!canEdit} onClick={() => setTrip(t.value)} data-testid={`trip-${t.value}`}
                      className={cn("rounded-full border px-2.5 py-1 text-xs", d.trip_status === t.value ? "border-foreground bg-foreground text-background" : "border-border text-muted-foreground hover:bg-secondary disabled:hover:bg-transparent")}>{t.label}</button>
            ))}
          </div>
        )}
      </Card>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card className="rounded-lg p-4"><div className="label-caps">Selling</div><div className="mt-1 font-display text-2xl font-black">{INR(d.selling_amount)}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Supplier cost</div><div className="mt-1 font-display text-2xl font-black">{d.supplier_cost ? INR(d.supplier_cost) : "—"}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Margin</div><div className={cn("mt-1 font-display text-2xl font-black", d.supplier_cost && d.margin < 0 && "text-red-600 dark:text-red-400")}>{d.supplier_cost ? INR(d.margin) : "—"}</div></Card>
        <Card className="rounded-lg p-4"><div className="label-caps">Chance of winning</div><div className="mt-1 font-display text-2xl font-black">{d.probability}%</div><div className="text-xs text-muted-foreground">{INR(d.weighted)} weighted</div></Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
        <div className="space-y-6">
          <Card className="rounded-lg p-5">
            <h3 className="mb-2 font-display font-bold">Trip</h3>
            <dl>
              <Row label="Destination">{d.destination || "—"}</Row>
              <Row label="Travel date">{fmtDate(d.travel_date)}{d.duration_days ? ` · ${Math.max(0, d.duration_days - 1)}N / ${d.duration_days}D` : ""}</Row>
              <Row label="Travellers">{d.adults} adult{d.adults === 1 ? "" : "s"}{d.children ? `, ${d.children} child${d.children === 1 ? "" : "ren"}` : ""}</Row>
              <Row label="Expected close"><span className={cn(d.overdue && "font-medium text-red-600 dark:text-red-400")}>{fmtDate(d.expected_close)}{d.overdue ? " (overdue)" : ""}</span></Row>
              <Row label="Owner">{d.owner?.name ?? "Unassigned"}</Row>
              {d.lead_id && <Row label="From lead"><Link href={`/leads/${d.lead_id}`} className="font-mono hover:underline" data-testid="deal-lead-link">{d.lead_id}</Link></Row>}
              {d.notes && <Row label="Notes"><span className="whitespace-pre-wrap">{d.notes}</span></Row>}
            </dl>
          </Card>
          {can("deals.delete") && <Button variant="ghost" size="sm" className="text-destructive" onClick={remove} data-testid="deal-delete"><Trash2 size={14} /> Delete deal</Button>}
        </div>
        <div className="space-y-6">
          <RecordQuotes filter={{ deal_id: d.id }} newHref={`/quotes/new?deal=${d.id}`} />
          <RecordActivities base={`/deals/${d.id}`} link={{ deal_id: d.id, customer_id: d.customer_id }} />
          <RecordNotes base={`/deals/${d.id}`} canWrite={canEdit} />
        </div>
      </div>

      {canEdit && <DealDialog open={edit} onOpenChange={setEdit} deal={d} onSaved={(x) => setD(x)} />}
    </div>
  );
}
