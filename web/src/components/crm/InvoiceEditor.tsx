"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useMemo, useRef, useState } from "react";
import { ArrowLeft, Loader2, Plus, Search, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import type { Customer, CustomerBrief, Deal, Installment, Invoice, LineKind, QuoteLine } from "@/types";
import { INR2, LINE_KINDS, calcQuote } from "./shared";

const NONE = "none";
const field = "mt-1.5 h-10";
const blankLine = (): QuoteLine => ({ kind: "other", description: "", qty: 1, unit_price: 0, discount: 0, tax_rate: 5 });

function Section({ title, children, action }: { title: string; children: React.ReactNode; action?: React.ReactNode }) {
  return (
    <Card className="space-y-3 rounded-lg p-5">
      <div className="flex items-center justify-between border-b border-border pb-2"><h3 className="font-display font-bold">{title}</h3>{action}</div>
      {children}
    </Card>
  );
}

/** Create (no invoiceId) or edit a draft invoice. */
export default function InvoiceEditor({ invoiceId }: { invoiceId?: string }) {
  const router = useRouter();
  const sp = useSearchParams();
  const editing = !!invoiceId;

  const [ready, setReady] = useState(!editing && !sp.get("customer") && !sp.get("deal"));
  const [customer, setCustomer] = useState<CustomerBrief | null>(null);
  const [q, setQ] = useState("");
  const [results, setResults] = useState<Customer[]>([]);
  const reqId = useRef(0);
  const [deals, setDeals] = useState<Deal[]>([]);
  const [dealId, setDealId] = useState(NONE);

  const [title, setTitle] = useState("");
  const [destination, setDestination] = useState("");
  const [travel, setTravel] = useState("");
  const [adults, setAdults] = useState("2");
  const [children, setChildren] = useState("0");
  const [tcs, setTcs] = useState(editing ? "0" : "2");   // a new invoice starts at 2%; a draft keeps what was saved
  const [notes, setNotes] = useState("");
  const [terms, setTerms] = useState("");
  const [lines, setLines] = useState<QuoteLine[]>([blankLine()]);
  const [sched, setSchedRaw] = useState<Installment[] | null>(null); // null = let the server build the default plan
  const [touched, setTouched] = useState(false);
  const [loadedTotal, setLoadedTotal] = useState<number | null>(null);
  const autoTitle = useRef<string | null>(null);   // the title we suggested, so a deal change may replace it but never your own words
  const dealLine = useRef<{ description: string; price: number } | null>(null);   // the charge we filled in from the chosen deal
  const setSched: typeof setSchedRaw = (v) => { setTouched(true); setSchedRaw(v); };
  const [invNo, setInvNo] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let off = false;
    (async () => {
      try {
        if (editing) {
          const { data: x } = await api.get<Invoice>(`/invoices/${invoiceId}`);
          if (off) return;
          if (x.status !== "draft") { toast.error("Issued invoices cannot be edited. Use a credit note to correct one."); router.replace(`/invoices/${x.id}`); return; }
          setInvNo(x.title); setCustomer(x.customer); setDealId(x.deal_id ?? NONE); setTitle(x.title); setDestination(x.destination ?? ""); setTravel(x.travel_date ?? "");
          setAdults(String(x.adults)); setChildren(String(x.children)); setTcs(String(x.tcs_rate || 0)); setNotes(x.notes ?? ""); setTerms(x.terms ?? "");
          setLines((x.lines?.length ? x.lines : [blankLine()]).map((l) => ({ ...l })));
          setSchedRaw((x.installments ?? []).map((i) => ({ label: i.label, due_date: i.due_date, amount: i.amount }))); setLoadedTotal(x.total);
        } else {
          const dealParam = sp.get("deal"); const custParam = sp.get("customer");
          if (dealParam) {
            const { data: d } = await api.get<Deal>(`/deals/${dealParam}`);
            if (off) return;
            setCustomer(d.customer); setDealId(d.id); setTitle(d.destination ? `${d.destination} trip` : d.name);
            setDestination(d.destination ?? ""); setTravel(d.travel_date ?? ""); setAdults(String(d.adults)); setChildren(String(d.children));
            if (d.selling_amount > 0) { dealLine.current = { description: d.name, price: d.selling_amount }; setLines([{ kind: "package", description: d.name, qty: 1, unit_price: d.selling_amount, discount: 0, tax_rate: 5 }]); }
          } else if (custParam) {
            const { data: c } = await api.get<Customer>(`/customers/${custParam}`);
            if (off) return;
            setCustomer({ id: c.id, customer_no: c.customer_no, name: c.name });
          }
        }
      } catch (e) { if (!off) setError(formatApiError(e)); }
      finally { if (!off) setReady(true); }
    })();
    return () => { off = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [invoiceId]);

  useEffect(() => {
    if (customer || !q.trim()) { setResults([]); return; }
    const id = ++reqId.current;
    const t = setTimeout(() => {
      api.get<Customer[]>("/customers", { params: { q: q.trim(), limit: 8 } })
        .then((r) => { if (id === reqId.current) setResults(r.data); }).catch(() => { if (id === reqId.current) setResults([]); });
    }, 250);
    return () => clearTimeout(t);
  }, [q, customer]);

  useEffect(() => {
    if (!customer) { setDeals([]); return; }
    api.get<Deal[]>("/deals", { params: { customer_id: customer.id, limit: 100 } }).then((r) => setDeals(r.data)).catch(() => setDeals([]));
  }, [customer]);

  /** Choosing a deal fills in its trip details and its amount, unless charges were already typed in by hand. */
  const pickDeal = (id: string) => {
    setDealId(id);
    const auto = dealLine.current;
    const untouched = lines.every((l) => !l.description.trim() && !(Number(l.unit_price) > 0));
    const ours = !!auto && lines.length === 1 && lines[0].description === auto.description && Number(lines[0].unit_price) === auto.price;
    if (id === NONE) {                                         // un-linking takes back only what we had filled in
      if (ours) { setLines([blankLine()]); dealLine.current = null; }
      return;
    }
    const d = deals.find((x) => x.id === id);
    if (!d) return;
    if (!title.trim() || title === (autoTitle.current ?? "")) { const t = d.destination ? `${d.destination} trip` : d.name; setTitle(t); autoTitle.current = t; }
    if (d.destination) setDestination(d.destination);
    if (d.travel_date) setTravel(d.travel_date.slice(0, 10));
    setAdults(String(d.adults)); setChildren(String(d.children));
    if (d.selling_amount > 0) {
      if (untouched || ours) {
        dealLine.current = { description: d.name, price: d.selling_amount };
        setLines([{ kind: "package", description: d.name, qty: 1, unit_price: d.selling_amount, discount: 0, tax_rate: 5 }]);
      } else {
        toast.info(`The deal amount ${INR2(d.selling_amount)} was not added because this invoice already has charges.`);
      }
    }
  };

  const calc = useMemo(() => calcQuote(lines, Number(tcs) || 0), [lines, tcs]);
  const upd = (i: number, patch: Partial<QuoteLine>) => { setLines((l) => l.map((x, k) => (k === i ? { ...x, ...patch } : x))); };
  const schedSum = Math.round((sched ?? []).reduce((a, s) => a + (Number(s.amount) || 0), 0) * 100) / 100;
  const updInst = (i: number, patch: Partial<Installment>) => setSched((l) => (l ?? []).map((x, k) => (k === i ? { ...x, ...patch } : x)));

  const save = async () => {
    if (!customer) { setError("Choose the customer this invoice is for."); return; }
    if (!title.trim()) { setError("Give the invoice a title."); return; }
    const filled = lines.filter((l) => l.description.trim());
    if (filled.length === 0) { setError("Add at least one price line."); return; }
    if (filled.some((l) => (Number(l.discount) || 0) > (Number(l.qty) || 0) * (Number(l.unit_price) || 0))) { setError("A discount is more than its line amount."); return; }
    // an untouched plan on a draft whose total changed is rebuilt by the server
    const keepPlan = sched && sched.length && (touched || (loadedTotal !== null && Math.abs(loadedTotal - calc.total) <= 0.01));
    if (keepPlan && Math.abs(schedSum - calc.total) > 0.01) { setError(`The payments add up to ${INR2(schedSum)} but the invoice total is ${INR2(calc.total)}.`); return; }
    setSaving(true); setError("");
    const body = {
      customer_id: customer.id, deal_id: dealId === NONE ? null : dealId, title: title.trim(), destination, travel_date: travel || null,
      adults: Number(adults) || 0, children: Number(children) || 0, tcs_rate: Number(tcs) || 0, notes, terms, lines: filled,
      installments: keepPlan && sched ? sched.filter((s) => Number(s.amount) > 0) : null,
    };
    try {
      const { data } = editing ? await api.patch<Invoice>(`/invoices/${invoiceId}`, body) : await api.post<Invoice>("/invoices", body);
      toast.success(editing ? (sched && sched.length && !keepPlan ? "Draft saved. The payment schedule was reset because the total changed." : "Draft saved") : "Draft invoice created");
      router.push(`/invoices/${data.id}`);
    } catch (e) { setError(formatApiError(e)); setSaving(false); }
  };

  if (!ready) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;

  return (
    <div className="space-y-6" data-testid="invoice-editor">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.back()} className="-ml-3 mb-2"><ArrowLeft size={16} /> Back</Button>
          <div className="label-caps">{editing ? `Draft · ${invNo}` : "Sales"}</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">{editing ? "Edit draft invoice" : "New invoice"}</h1>
          <p className="mt-1 text-sm text-muted-foreground">The invoice number is given when you issue it, so drafts never leave gaps in the series.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => router.back()} disabled={saving}>Cancel</Button>
          <Button onClick={save} disabled={saving} data-testid="invoice-save">{saving && <Loader2 size={14} className="animate-spin" />} Save draft</Button>
        </div>
      </div>
      {error && <div className="rounded border border-destructive/40 p-3 text-sm text-destructive" role="alert" data-testid="invoice-error">{error}</div>}

      <Section title="Customer and trip">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label>Customer *</Label>
            {customer ? (
              <div className="mt-1.5 flex h-10 items-center justify-between rounded border border-border bg-secondary/50 px-3 text-sm">
                <span className="truncate"><span className="font-medium">{customer.name}</span> <span className="font-mono text-xs text-muted-foreground">{customer.customer_no}</span></span>
                {!editing && <button type="button" onClick={() => { setCustomer(null); setDealId(NONE); }} aria-label="Change customer" className="rounded p-1 hover:bg-accent"><X size={14} /></button>}
              </div>
            ) : (
              <div className="relative mt-1.5">
                <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search customer by name, phone or number…" className="h-10 pl-9" data-testid="invoice-customer-search" autoFocus />
                {results.length > 0 && (
                  <ul className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded border border-border bg-popover shadow-md">
                    {results.map((c) => (
                      <li key={c.id}>
                        <button type="button" onClick={() => { setCustomer({ id: c.id, customer_no: c.customer_no, name: c.name }); setQ(""); }} data-testid={`invoice-customer-opt-${c.id}`}
                                className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-accent">
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
          <div className="sm:col-span-2"><Label htmlFor="i-title">Invoice title *</Label><Input id="i-title" className={field} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Goa family getaway" data-testid="invoice-title" /></div>
          <div>
            <Label>Deal</Label>
            <Select value={dealId} onValueChange={pickDeal} disabled={!customer}>
              <SelectTrigger className={field}><SelectValue placeholder="Not linked" /></SelectTrigger>
              <SelectContent>
                <SelectItem value={NONE}>Not linked to a deal</SelectItem>
                {deals.map((d) => <SelectItem key={d.id} value={d.id}>{d.deal_no} · {d.name}</SelectItem>)}
              </SelectContent>
            </Select>
            <p className="mt-1 text-xs text-muted-foreground">Choosing a deal fills in its trip and amount. The first payment on it marks it Won.</p>
          </div>
          <div><Label htmlFor="i-dest">Destination</Label><Input id="i-dest" className={field} value={destination} onChange={(e) => setDestination(e.target.value)} /></div>
          <div><Label htmlFor="i-travel">Travel date</Label><Input id="i-travel" type="date" className={field} value={travel} onChange={(e) => setTravel(e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><Label htmlFor="i-ad">Adults</Label><Input id="i-ad" type="number" min={0} className={field} value={adults} onChange={(e) => setAdults(e.target.value)} /></div>
            <div><Label htmlFor="i-ch">Children</Label><Input id="i-ch" type="number" min={0} className={field} value={children} onChange={(e) => setChildren(e.target.value)} /></div>
          </div>
        </div>
      </Section>

      <Section title="Charges">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-sm">
            <thead>
              <tr className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                <th className="w-[120px] pb-2">Type</th><th className="pb-2">Description</th><th className="w-[70px] pb-2">Qty</th><th className="w-[120px] pb-2">Rate ₹</th>
                <th className="w-[100px] pb-2">Discount ₹</th><th className="w-[70px] pb-2">GST %</th><th className="w-[120px] pb-2 text-right">Amount</th><th className="w-[36px] pb-2" />
              </tr>
            </thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={i} className="align-top" data-testid={`invoice-line-${i}`}>
                  <td className="pr-2 pb-2">
                    <Select value={l.kind} onValueChange={(v) => upd(i, { kind: v as LineKind })}>
                      <SelectTrigger className="h-9" aria-label="Line type"><SelectValue /></SelectTrigger>
                      <SelectContent>{LINE_KINDS.map((k) => <SelectItem key={k.value} value={k.value}>{k.label}</SelectItem>)}</SelectContent>
                    </Select>
                  </td>
                  <td className="pr-2 pb-2"><Input className="h-9" aria-label="Description" value={l.description} onChange={(e) => upd(i, { description: e.target.value })} placeholder="What the customer is paying for" data-testid={`invline-desc-${i}`} /></td>
                  <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} aria-label="Quantity" value={l.qty} onChange={(e) => upd(i, { qty: Number(e.target.value) })} /></td>
                  <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} aria-label="Rate" value={l.unit_price || ""} onChange={(e) => upd(i, { unit_price: Number(e.target.value) })} data-testid={`invline-rate-${i}`} /></td>
                  <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} aria-label="Discount" value={l.discount || ""} onChange={(e) => upd(i, { discount: Number(e.target.value) })} /></td>
                  <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} max={100} step="0.5" aria-label="GST percent" value={l.tax_rate} onChange={(e) => upd(i, { tax_rate: Number(e.target.value) })} /></td>
                  <td className="pb-2 pt-2 text-right font-medium">{INR2(calc.rows[i]?.total ?? 0)}</td>
                  <td className="pb-2"><Button type="button" size="icon" variant="ghost" aria-label="Remove line" onClick={() => setLines((cur) => (cur.length > 1 ? cur.filter((_, k) => k !== i) : [blankLine()]))}><Trash2 size={15} /></Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Button type="button" variant="outline" size="sm" onClick={() => setLines((l) => [...l, blankLine()])} data-testid="invoice-add-line"><Plus size={14} /> Add line</Button>
        <div className="ml-auto w-full max-w-sm space-y-1.5 text-sm" data-testid="invoice-totals">
          <div className="flex justify-between"><span className="text-muted-foreground">Subtotal</span><span>{INR2(calc.subtotal)}</span></div>
          {calc.discount > 0 && <div className="flex justify-between"><span className="text-muted-foreground">Discount</span><span>− {INR2(calc.discount)}</span></div>}
          <div className="flex justify-between"><span className="text-muted-foreground">GST</span><span>{INR2(calc.tax)}</span></div>
          <div className="flex items-center justify-between gap-3">
            <span className="flex items-center gap-2 text-muted-foreground">TCS % <Input type="number" min={0} max={100} step="0.5" value={tcs} onChange={(e) => setTcs(e.target.value)} className="h-8 w-20" aria-label="TCS percent" /></span>
            <span>{INR2(calc.tcs)}</span>
          </div>
          <p className="-mt-1 text-right text-[11px] text-muted-foreground" data-testid="invoice-tcs-base">TCS is {Number(tcs) || 0}% of the subtotal {INR2(calc.taxable)}, not of the GST.</p>
          <div className="flex justify-between border-t border-border pt-2 text-base font-bold"><span>Total</span><span data-testid="invoice-total">{INR2(calc.total)}</span></div>
          <p className="text-xs text-muted-foreground">CGST/SGST or IGST is worked out from the customer&apos;s state when the invoice is issued.</p>
        </div>
      </Section>

      <Section title="Payment schedule" action={
        <div className="flex gap-2">
          {sched === null || sched.length === 0
            ? <Button type="button" size="sm" variant="outline" onClick={() => setSched([{ label: "Advance", due_date: new Date().toISOString().slice(0, 10), amount: Math.round(calc.total / 2) }, { label: "Balance", due_date: travel || new Date().toISOString().slice(0, 10), amount: Math.round((calc.total - Math.round(calc.total / 2)) * 100) / 100 }])} data-testid="custom-schedule">Customise</Button>
            : <Button type="button" size="sm" variant="outline" onClick={() => setSched(null)}>Use the default plan</Button>}
          {sched && sched.length > 0 && <Button type="button" size="sm" variant="outline" onClick={() => setSched((l) => [...(l ?? []), { label: `Payment ${(l?.length ?? 0) + 1}`, due_date: new Date().toISOString().slice(0, 10), amount: 0 }])}><Plus size={14} /> Add payment</Button>}
        </div>}>
        {(sched === null || sched.length === 0) ? (
          <p className="text-sm text-muted-foreground">Default plan: half as an advance now and the rest {travel ? "15 days before travel" : "a week later"}. Click Customise to set your own amounts and dates.</p>
        ) : (
          <div className="space-y-2">
            {sched.map((s, i) => (
              <div key={i} className="grid gap-2 sm:grid-cols-[1.2fr_1fr_1fr_auto]">
                <Input aria-label="Payment name" value={s.label} onChange={(e) => updInst(i, { label: e.target.value })} />
                <Input aria-label="Due date" type="date" value={s.due_date} onChange={(e) => updInst(i, { due_date: e.target.value })} />
                <Input aria-label="Amount" type="number" min={0} step="0.01" value={s.amount || ""} onChange={(e) => updInst(i, { amount: Number(e.target.value) })} data-testid={`inst-amount-${i}`} />
                <Button type="button" size="icon" variant="ghost" aria-label="Remove payment" onClick={() => setSched((l) => (l ?? []).filter((_, k) => k !== i))}><Trash2 size={15} /></Button>
              </div>
            ))}
            <div className={Math.abs(schedSum - calc.total) > 0.01 ? "text-sm text-destructive" : "text-sm text-muted-foreground"} data-testid="schedule-sum">
              Payments add up to {INR2(schedSum)} of {INR2(calc.total)}
              {Math.abs(schedSum - calc.total) > 0.01 && (
                <button type="button" className="ml-2 underline underline-offset-2" onClick={() => setSched((l) => { const a = [...(l ?? [])]; if (a.length) { const rest = a.slice(0, -1).reduce((x, y) => x + (Number(y.amount) || 0), 0); a[a.length - 1] = { ...a[a.length - 1], amount: Math.round((calc.total - rest) * 100) / 100 }; } return a; })}>Set the last payment to the rest</button>
              )}
            </div>
          </div>
        )}
      </Section>

      <Section title="Notes and terms">
        <div><Label htmlFor="i-notes">Notes</Label><Textarea id="i-notes" rows={3} className="mt-1.5" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Shown on the invoice" /></div>
        <div><Label htmlFor="i-terms">Terms &amp; conditions</Label><Textarea id="i-terms" rows={4} className="mt-1.5" value={terms} onChange={(e) => setTerms(e.target.value)} /></div>
      </Section>

      <div className="flex justify-end gap-2 pb-4">
        <Button variant="outline" onClick={() => router.back()} disabled={saving}>Cancel</Button>
        <Button onClick={save} disabled={saving}>{saving && <Loader2 size={14} className="animate-spin" />} Save draft</Button>
      </div>
    </div>
  );
}
