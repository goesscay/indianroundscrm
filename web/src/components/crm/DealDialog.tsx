"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Loader2, Search, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { Customer, CustomerBrief, Deal, DealStage, TripStatus } from "@/types";
import { STAGES, TRIP_STATUSES, useCrmOptions } from "./shared";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Edit this deal; omit to create. */
  deal?: Deal | null;
  /** Create-mode: fixed customer (e.g. from the customer page). */
  customer?: CustomerBrief | null;
  /** Create-mode: default stage (the board's column). */
  stage?: DealStage;
  onSaved: (d: Deal) => void;
}

const NONE = "none";
const field = "mt-1.5 h-10";

export default function DealDialog({ open, onOpenChange, deal, customer, stage, onSaved }: Props) {
  const { user } = useCurrentUser();
  const options = useCrmOptions();
  const editing = !!deal;

  const [cust, setCust] = useState<CustomerBrief | null>(null);
  const [q, setQ] = useState("");
  const [results, setResults] = useState<Customer[]>([]);
  const reqId = useRef(0);

  const [name, setName] = useState("");
  const [st, setSt] = useState<DealStage>("enquiry");
  const [trip, setTrip] = useState<TripStatus | typeof NONE>(NONE);
  const [destination, setDestination] = useState("");
  const [travel, setTravel] = useState("");
  const [days, setDays] = useState("");
  const [adults, setAdults] = useState("1");
  const [children, setChildren] = useState("0");
  const [selling, setSelling] = useState("");
  const [cost, setCost] = useState("");
  const [close, setClose] = useState("");
  const [prob, setProb] = useState("");
  const [lost, setLost] = useState("");
  const [owner, setOwner] = useState(NONE);
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setError(""); setSaving(false); setQ(""); setResults([]);
    const d = deal;
    setCust(d?.customer ?? customer ?? null);
    setName(d?.name ?? ""); setSt(d?.stage ?? stage ?? "enquiry"); setTrip(d?.trip_status ?? NONE);
    setDestination(d ? (d.destination ?? "") : (user.default_destination?.trim() ?? "")); setTravel(d?.travel_date ?? ""); setDays(d?.duration_days != null ? String(d.duration_days) : "");
    setAdults(String(d?.adults ?? 1)); setChildren(String(d?.children ?? 0));
    setSelling(d ? String(d.selling_amount || "") : ""); setCost(d ? String(d.supplier_cost || "") : "");
    setClose(d?.expected_close ?? ""); setProb(d ? String(d.probability) : ""); setLost(d?.lost_reason ?? "");
    setOwner(d ? (d.owner?.id ?? NONE) : (user.id ? String(user.id) : NONE)); setNotes(d?.notes ?? "");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, deal?.id]);

  useEffect(() => {
    if (!open || cust || !q.trim()) { setResults([]); return; }
    const id = ++reqId.current;
    const t = setTimeout(() => {
      api.get<Customer[]>("/customers", { params: { q: q.trim(), limit: 8 } })
        .then((r) => { if (id === reqId.current) setResults(r.data); })
        .catch(() => { if (id === reqId.current) setResults([]); });
    }, 250);
    return () => clearTimeout(t);
  }, [q, cust, open]);

  const num = (v: string) => (v.trim() === "" ? null : Number(v));

  const submit = async (e?: FormEvent) => {
    e?.preventDefault();
    if (!cust) { setError("Choose the customer this deal is for."); return; }
    if (st === "lost" && !lost.trim()) { setError("Tell us why the deal was lost."); return; }
    setSaving(true); setError("");
    const body = {
      customer_id: cust.id, name: name.trim() || null, stage: st, trip_status: st === "won" && trip !== NONE ? trip : null,
      destination: destination.trim() || null, travel_date: travel || null, duration_days: num(days),
      adults: num(adults) ?? 1, children: num(children) ?? 0, selling_amount: num(selling) ?? 0, supplier_cost: num(cost) ?? 0,
      expected_close: close || null, lost_reason: st === "lost" ? lost.trim() : null,
      owner_id: owner === NONE ? null : owner, notes: notes.trim() || null,
      // only an explicit change overrides the stage's default chance
      ...(prob.trim() !== "" && (!editing || Number(prob) !== deal!.probability) ? { probability: Number(prob) } : {}),
    };
    try {
      const { data } = editing ? await api.patch<Deal>(`/deals/${deal!.id}`, body) : await api.post<Deal>("/deals", body);
      toast.success(editing ? "Deal updated" : `Deal ${data.deal_no} created`);
      onSaved(data);
      onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSaving(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto" data-testid="deal-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">{editing ? `Edit ${deal!.deal_no}` : "New deal"}</DialogTitle>
          <DialogDescription>A deal is one trip you are selling to a customer.</DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4">
          <div>
            <Label>Customer *</Label>
            {cust ? (
              <div className="mt-1.5 flex h-10 items-center justify-between rounded border border-border bg-secondary/50 px-3 text-sm">
                <span className="truncate"><span className="font-medium">{cust.name}</span> <span className="font-mono text-xs text-muted-foreground">{cust.customer_no}</span></span>
                {!editing && !customer && <button type="button" onClick={() => setCust(null)} aria-label="Change customer" className="rounded p-1 hover:bg-accent"><X size={14} /></button>}
              </div>
            ) : (
              <div className="relative mt-1.5">
                <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search customer by name, phone or number…" className="h-10 pl-9" data-testid="deal-customer-search" autoFocus />
                {results.length > 0 && (
                  <ul className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded border border-border bg-popover shadow-md">
                    {results.map((c) => (
                      <li key={c.id}>
                        <button type="button" onClick={() => { setCust({ id: c.id, customer_no: c.customer_no, name: c.name }); setQ(""); }}
                                className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-accent" data-testid={`deal-customer-opt-${c.id}`}>
                          <span className="truncate">{c.name}{c.phone ? <span className="text-muted-foreground"> · {c.phone}</span> : null}</span>
                          <span className="font-mono text-xs text-muted-foreground">{c.customer_no}</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Label htmlFor="d-name">Deal name</Label>
              <Input id="d-name" className={field} value={name} onChange={(e) => setName(e.target.value)} placeholder="Auto: destination — customer" />
            </div>
            <div>
              <Label>Stage</Label>
              <Select value={st} onValueChange={(v) => setSt(v as DealStage)}>
                <SelectTrigger className={field} data-testid="deal-stage"><SelectValue /></SelectTrigger>
                <SelectContent>{STAGES.map((s) => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <div>
              <Label>Owner</Label>
              <Select value={owner} onValueChange={setOwner}>
                <SelectTrigger className={field}><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>Unassigned</SelectItem>
                  {(options?.owners ?? []).map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            {st === "won" && (
              <div className="sm:col-span-2">
                <Label>Trip status</Label>
                <Select value={trip} onValueChange={(v) => setTrip(v as TripStatus)}>
                  <SelectTrigger className={field}><SelectValue placeholder="Confirmed" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>Confirmed</SelectItem>
                    {TRIP_STATUSES.filter((t) => t.value !== "confirmed").map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
            )}
            {st === "lost" && (
              <div className="sm:col-span-2">
                <Label htmlFor="d-lost">Why was it lost? *</Label>
                <Input id="d-lost" className={field} value={lost} onChange={(e) => setLost(e.target.value)} placeholder="Too expensive, chose another agent, postponed…" data-testid="deal-lost-reason" />
              </div>
            )}
            <div><Label htmlFor="d-dest">Destination</Label><Input id="d-dest" className={field} value={destination} onChange={(e) => setDestination(e.target.value)} /></div>
            <div><Label htmlFor="d-travel">Travel date</Label><Input id="d-travel" type="date" className={field} value={travel} onChange={(e) => setTravel(e.target.value)} /></div>
            <div><Label htmlFor="d-days">Duration (days)</Label><Input id="d-days" type="number" min={0} className={field} value={days} onChange={(e) => setDays(e.target.value)} /></div>
            <div className="grid grid-cols-2 gap-3">
              <div><Label htmlFor="d-ad">Adults</Label><Input id="d-ad" type="number" min={0} className={field} value={adults} onChange={(e) => setAdults(e.target.value)} /></div>
              <div><Label htmlFor="d-ch">Children</Label><Input id="d-ch" type="number" min={0} className={field} value={children} onChange={(e) => setChildren(e.target.value)} /></div>
            </div>
            <div><Label htmlFor="d-sell">Selling amount (₹)</Label><Input id="d-sell" type="number" min={0} className={field} value={selling} onChange={(e) => setSelling(e.target.value)} data-testid="deal-selling" /></div>
            <div><Label htmlFor="d-cost">Supplier cost (₹)</Label><Input id="d-cost" type="number" min={0} className={field} value={cost} onChange={(e) => setCost(e.target.value)} /></div>
            <div><Label htmlFor="d-close">Expected close</Label><Input id="d-close" type="date" className={field} value={close} onChange={(e) => setClose(e.target.value)} /></div>
            <div><Label htmlFor="d-prob">Chance of winning (%)</Label><Input id="d-prob" type="number" min={0} max={100} className={field} value={prob} onChange={(e) => setProb(e.target.value)} placeholder="Set by stage" disabled={st === "won" || st === "lost"} /></div>
            <div className="sm:col-span-2"><Label htmlFor="d-notes">Notes</Label><Textarea id="d-notes" rows={3} className="mt-1.5" value={notes} onChange={(e) => setNotes(e.target.value)} /></div>
          </div>

          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="deal-save">
              {saving && <Loader2 size={14} className="animate-spin" />} {editing ? "Save changes" : "Create deal"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
