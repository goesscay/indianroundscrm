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
import type { Customer, CustomerBrief, Deal, LineKind, PackageDay, PackageRates, Quote, QuoteLine, TravelPackage } from "@/types";
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

/** Create (no quoteId) or edit a draft quote. */
export default function QuoteEditor({ quoteId }: { quoteId?: string }) {
  const router = useRouter();
  const sp = useSearchParams();
  const editing = !!quoteId;

  const [ready, setReady] = useState(!editing && !sp.get("customer") && !sp.get("deal"));
  const [customer, setCustomer] = useState<CustomerBrief | null>(null);
  const [q, setQ] = useState("");
  const [results, setResults] = useState<Customer[]>([]);
  const reqId = useRef(0);
  const [deals, setDeals] = useState<Deal[]>([]);
  const [dealId, setDealId] = useState(NONE);
  const [packages, setPackages] = useState<TravelPackage[]>([]);
  const [packageId, setPackageId] = useState(NONE);

  const [title, setTitle] = useState("");
  const [destination, setDestination] = useState("");
  const [travel, setTravel] = useState("");
  const [days, setDays] = useState("");
  const [adults, setAdults] = useState("2");
  const [children, setChildren] = useState("0");
  const [validUntil, setValidUntil] = useState("");
  const [tcs, setTcs] = useState("0");
  const [intro, setIntro] = useState("");
  const [inclusions, setInclusions] = useState("");
  const [exclusions, setExclusions] = useState("");
  const [terms, setTerms] = useState("");
  const [itinerary, setItinerary] = useState<PackageDay[]>([]);
  const [lines, setLines] = useState<QuoteLine[]>([blankLine()]);
  const [quoteNo, setQuoteNo] = useState("");
  const [saving, setSaving] = useState(false);
  const [applying, setApplying] = useState(false);
  const [error, setError] = useState("");

  // load packages once
  useEffect(() => {
    api.get<TravelPackage[]>("/packages", { params: { active: "1", limit: 500 } }).then((r) => setPackages(r.data)).catch(() => { /* picker stays empty */ });
  }, []);

  // load an existing draft, or prefill from ?customer= / ?deal=
  useEffect(() => {
    let off = false;
    (async () => {
      try {
        if (editing) {
          const { data: x } = await api.get<Quote>(`/quotes/${quoteId}`);
          if (off) return;
          if (x.status !== "draft") { toast.error("Only draft quotes can be edited. Use Revise to make a new version."); router.replace(`/quotes/${x.id}`); return; }
          setQuoteNo(x.quote_no); setCustomer(x.customer); setDealId(x.deal_id ?? NONE); setPackageId(x.package_id ?? NONE);
          setTitle(x.title); setDestination(x.destination ?? ""); setTravel(x.travel_date ?? ""); setDays(x.duration_days != null ? String(x.duration_days) : "");
          setAdults(String(x.adults)); setChildren(String(x.children)); setValidUntil(x.valid_until ?? ""); setTcs(String(x.tcs_rate || 0));
          setIntro(x.intro ?? ""); setInclusions(x.inclusions ?? ""); setExclusions(x.exclusions ?? ""); setTerms(x.terms ?? "");
          setItinerary(x.itinerary ?? []); setLines((x.lines?.length ? x.lines : [blankLine()]).map((l) => ({ ...l })));
        } else {
          const dealParam = sp.get("deal"); const custParam = sp.get("customer");
          if (dealParam) {
            const { data: d } = await api.get<Deal>(`/deals/${dealParam}`);
            if (off) return;
            setCustomer(d.customer); setDealId(d.id); setTitle(d.destination ? `${d.destination} trip` : d.name);
            setDestination(d.destination ?? ""); setTravel(d.travel_date ?? ""); setDays(d.duration_days != null ? String(d.duration_days) : "");
            setAdults(String(d.adults)); setChildren(String(d.children));
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
  }, [quoteId]);

  // customer search
  useEffect(() => {
    if (customer || !q.trim()) { setResults([]); return; }
    const id = ++reqId.current;
    const t = setTimeout(() => {
      api.get<Customer[]>("/customers", { params: { q: q.trim(), limit: 8 } })
        .then((r) => { if (id === reqId.current) setResults(r.data); }).catch(() => { if (id === reqId.current) setResults([]); });
    }, 250);
    return () => clearTimeout(t);
  }, [q, customer]);

  // the customer's deals for the optional link
  useEffect(() => {
    if (!customer) { setDeals([]); return; }
    api.get<Deal[]>("/deals", { params: { customer_id: customer.id, limit: 100 } }).then((r) => setDeals(r.data)).catch(() => setDeals([]));
  }, [customer]);

  const calc = useMemo(() => calcQuote(lines, Number(tcs) || 0), [lines, tcs]);
  const upd = (i: number, patch: Partial<QuoteLine>) => setLines((l) => l.map((x, k) => (k === i ? { ...x, ...patch } : x)));

  const applyPackage = async () => {
    const pkg = packages.find((p) => p.id === packageId);
    if (!pkg) return;
    setApplying(true);
    try {
      const [{ data: rates }, { data: full }] = await Promise.all([
        api.get<PackageRates>(`/packages/${pkg.id}/price`, { params: travel ? { date: travel } : {} }),
        api.get<TravelPackage>(`/packages/${pkg.id}`),
      ]);
      const a = Number(adults) || 0, c = Number(children) || 0;
      const tag = rates.season ? ` (${rates.season} season)` : "";
      const pkgLines: QuoteLine[] = [];
      if (a > 0) pkgLines.push({ kind: "package", description: `${pkg.name} — per adult${tag}`, qty: a, unit_price: rates.adult_price, discount: 0, tax_rate: rates.gst_rate });
      if (c > 0 && rates.child_price > 0) pkgLines.push({ kind: "package", description: `${pkg.name} — per child${tag}`, qty: c, unit_price: rates.child_price, discount: 0, tax_rate: rates.gst_rate });
      setLines((cur) => {
        const rest = cur.filter((l) => l.kind !== "package" && (l.description.trim() || l.unit_price));
        return [...pkgLines, ...rest];
      });
      if (!title.trim()) setTitle(pkg.name);
      if (!destination.trim() && pkg.destination) setDestination(pkg.destination);
      if (!days.trim() && pkg.days) setDays(String(pkg.days));
      if (!inclusions.trim() && full.inclusions) setInclusions(full.inclusions);
      if (!exclusions.trim() && full.exclusions) setExclusions(full.exclusions);
      if (!terms.trim() && full.terms) setTerms(full.terms);
      if (itinerary.length === 0 && full.itinerary?.length) setItinerary(full.itinerary.map((d) => ({ title: d.title, description: d.description ?? "" })));
      toast.success(`Added ${pkg.name}${rates.season ? ` at ${rates.season} season rates` : ""}`);
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setApplying(false); }
  };

  const save = async () => {
    if (!customer) { setError("Choose the customer this quote is for."); return; }
    if (!title.trim()) { setError("Give the quote a title."); return; }
    const filled = lines.filter((l) => l.description.trim());
    if (filled.some((l) => (Number(l.discount) || 0) > (Number(l.qty) || 0) * (Number(l.unit_price) || 0))) { setError("A discount is more than its line amount."); return; }
    setSaving(true); setError("");
    const body = {
      customer_id: customer.id, deal_id: dealId === NONE ? null : dealId, package_id: packageId === NONE ? null : packageId,
      title: title.trim(), destination, travel_date: travel || null, duration_days: days.trim() === "" ? null : Number(days),
      adults: Number(adults) || 0, children: Number(children) || 0, valid_until: validUntil || null, tcs_rate: Number(tcs) || 0,
      intro, inclusions, exclusions, terms, itinerary: itinerary.filter((d) => d.title.trim()), lines: filled,
    };
    try {
      const { data } = editing ? await api.patch<Quote>(`/quotes/${quoteId}`, body) : await api.post<Quote>("/quotes", body);
      toast.success(editing ? "Quote saved" : `Quote ${data.quote_no} created`);
      router.push(`/quotes/${data.id}`);
    } catch (e) { setError(formatApiError(e)); setSaving(false); }
  };

  if (!ready) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;

  return (
    <div className="space-y-6" data-testid="quote-editor">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.back()} className="-ml-3 mb-2"><ArrowLeft size={16} /> Back</Button>
          <div className="label-caps">{editing ? `Edit draft · ${quoteNo}` : "Sales"}</div>
          <h1 className="mt-1 font-display text-3xl font-black tracking-tight sm:text-4xl">{editing ? "Edit quote" : "New quote"}</h1>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => router.back()} disabled={saving}>Cancel</Button>
          <Button onClick={save} disabled={saving} data-testid="quote-save">{saving && <Loader2 size={14} className="animate-spin" />} {editing ? "Save quote" : "Create quote"}</Button>
        </div>
      </div>
      {error && <div className="rounded border border-destructive/40 p-3 text-sm text-destructive" role="alert">{error}</div>}

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
                <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search customer by name, phone or number…" className="h-10 pl-9" data-testid="quote-customer-search" autoFocus />
                {results.length > 0 && (
                  <ul className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded border border-border bg-popover shadow-md">
                    {results.map((c) => (
                      <li key={c.id}>
                        <button type="button" onClick={() => { setCustomer({ id: c.id, customer_no: c.customer_no, name: c.name }); setQ(""); }} data-testid={`quote-customer-opt-${c.id}`}
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
          <div className="sm:col-span-2"><Label htmlFor="q-title">Quote title *</Label><Input id="q-title" className={field} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Goa family getaway" data-testid="quote-title" /></div>
          <div>
            <Label>Deal</Label>
            <Select value={dealId} onValueChange={setDealId} disabled={!customer}>
              <SelectTrigger className={field} data-testid="quote-deal"><SelectValue placeholder="Not linked" /></SelectTrigger>
              <SelectContent>
                <SelectItem value={NONE}>Not linked to a deal</SelectItem>
                {deals.map((d) => <SelectItem key={d.id} value={d.id}>{d.deal_no} · {d.name}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div><Label htmlFor="q-valid">Valid until</Label><Input id="q-valid" type="date" className={field} value={validUntil} onChange={(e) => setValidUntil(e.target.value)} /></div>
          <div><Label htmlFor="q-dest">Destination</Label><Input id="q-dest" className={field} value={destination} onChange={(e) => setDestination(e.target.value)} /></div>
          <div><Label htmlFor="q-travel">Travel date</Label><Input id="q-travel" type="date" className={field} value={travel} onChange={(e) => setTravel(e.target.value)} /></div>
          <div><Label htmlFor="q-days">Duration (days)</Label><Input id="q-days" type="number" min={0} className={field} value={days} onChange={(e) => setDays(e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><Label htmlFor="q-ad">Adults</Label><Input id="q-ad" type="number" min={0} className={field} value={adults} onChange={(e) => setAdults(e.target.value)} /></div>
            <div><Label htmlFor="q-ch">Children</Label><Input id="q-ch" type="number" min={0} className={field} value={children} onChange={(e) => setChildren(e.target.value)} /></div>
          </div>
        </div>
      </Section>

      <Section title="Price">
        <div className="flex flex-wrap items-end gap-2 rounded border border-dashed border-border p-3">
          <div className="min-w-[220px] flex-1">
            <Label>Start from a package</Label>
            <Select value={packageId} onValueChange={setPackageId}>
              <SelectTrigger className={field} data-testid="quote-package"><SelectValue placeholder="Choose a package" /></SelectTrigger>
              <SelectContent>
                <SelectItem value={NONE}>No package</SelectItem>
                {packages.map((p) => <SelectItem key={p.id} value={p.id}>{p.name}{p.destination ? ` · ${p.destination}` : ""}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <Button type="button" variant="outline" className="h-10" onClick={applyPackage} disabled={packageId === NONE || applying} data-testid="quote-apply-package">
            {applying && <Loader2 size={14} className="animate-spin" />} Add package prices
          </Button>
          <p className="basis-full text-xs text-muted-foreground">Uses the adults and children above and the price for the travel date (seasonal rates apply). It also fills the itinerary, inclusions and terms if they are empty.</p>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-sm">
            <thead>
              <tr className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                <th className="w-[120px] pb-2">Type</th><th className="pb-2">Description</th><th className="w-[70px] pb-2">Qty</th>
                <th className="w-[120px] pb-2">Rate ₹</th><th className="w-[100px] pb-2">Discount ₹</th><th className="w-[70px] pb-2">GST %</th>
                <th className="w-[120px] pb-2 text-right">Amount</th><th className="w-[36px] pb-2" />
              </tr>
            </thead>
            <tbody>
              {lines.map((l, i) => (
                <tr key={i} className="align-top" data-testid={`quote-line-${i}`}>
                  <td className="pr-2 pb-2">
                    <Select value={l.kind} onValueChange={(v) => upd(i, { kind: v as LineKind })}>
                      <SelectTrigger className="h-9" aria-label="Line type"><SelectValue /></SelectTrigger>
                      <SelectContent>{LINE_KINDS.map((k) => <SelectItem key={k.value} value={k.value}>{k.label}</SelectItem>)}</SelectContent>
                    </Select>
                  </td>
                  <td className="pr-2 pb-2"><Input className="h-9" aria-label="Description" value={l.description} onChange={(e) => upd(i, { description: e.target.value })} placeholder="What the customer is paying for" data-testid={`line-desc-${i}`} /></td>
                  <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} aria-label="Quantity" value={l.qty} onChange={(e) => upd(i, { qty: Number(e.target.value) })} /></td>
                  <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} aria-label="Rate" value={l.unit_price || ""} onChange={(e) => upd(i, { unit_price: Number(e.target.value) })} data-testid={`line-rate-${i}`} /></td>
                  <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} aria-label="Discount" value={l.discount || ""} onChange={(e) => upd(i, { discount: Number(e.target.value) })} /></td>
                  <td className="pr-2 pb-2"><Input className="h-9" type="number" min={0} max={100} step="0.5" aria-label="GST percent" value={l.tax_rate} onChange={(e) => upd(i, { tax_rate: Number(e.target.value) })} /></td>
                  <td className="pb-2 pt-2 text-right font-medium">{INR2(calc.rows[i]?.total ?? 0)}</td>
                  <td className="pb-2"><Button type="button" size="icon" variant="ghost" aria-label="Remove line" onClick={() => setLines((cur) => (cur.length > 1 ? cur.filter((_, k) => k !== i) : [blankLine()]))}><Trash2 size={15} /></Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Button type="button" variant="outline" size="sm" onClick={() => setLines((l) => [...l, blankLine()])} data-testid="add-line"><Plus size={14} /> Add line</Button>

        <div className="ml-auto w-full max-w-sm space-y-1.5 text-sm" data-testid="quote-totals">
          <div className="flex justify-between"><span className="text-muted-foreground">Subtotal</span><span>{INR2(calc.subtotal)}</span></div>
          {calc.discount > 0 && <div className="flex justify-between"><span className="text-muted-foreground">Discount</span><span>− {INR2(calc.discount)}</span></div>}
          <div className="flex justify-between"><span className="text-muted-foreground">GST</span><span>{INR2(calc.tax)}</span></div>
          <div className="flex items-center justify-between gap-3">
            <span className="flex items-center gap-2 text-muted-foreground">TCS % <Input type="number" min={0} max={100} step="0.5" value={tcs} onChange={(e) => setTcs(e.target.value)} className="h-8 w-20" aria-label="TCS percent" /></span>
            <span>{INR2(calc.tcs)}</span>
          </div>
          <div className="flex justify-between border-t border-border pt-2 text-base font-bold"><span>Total</span><span data-testid="quote-total">{INR2(calc.total)}</span></div>
        </div>
      </Section>

      <Section title="What the customer reads" action={null}>
        <div><Label htmlFor="q-intro">Introduction</Label><Textarea id="q-intro" rows={3} className="mt-1.5" value={intro} onChange={(e) => setIntro(e.target.value)} placeholder="A short note at the top of the quote" /></div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div><Label htmlFor="q-inc">Inclusions</Label><Textarea id="q-inc" rows={5} className="mt-1.5" placeholder="One per line" value={inclusions} onChange={(e) => setInclusions(e.target.value)} /></div>
          <div><Label htmlFor="q-exc">Exclusions</Label><Textarea id="q-exc" rows={5} className="mt-1.5" placeholder="One per line" value={exclusions} onChange={(e) => setExclusions(e.target.value)} /></div>
        </div>
        <div><Label htmlFor="q-terms">Terms &amp; conditions</Label><Textarea id="q-terms" rows={4} className="mt-1.5" value={terms} onChange={(e) => setTerms(e.target.value)} /></div>
      </Section>

      <Section title="Itinerary" action={<Button type="button" size="sm" variant="outline" onClick={() => setItinerary((l) => [...l, { title: "", description: "" }])} data-testid="quote-add-day"><Plus size={14} /> Add day</Button>}>
        {itinerary.length === 0 && <p className="text-sm text-muted-foreground">No itinerary. Pick a package above to copy its days, or add them by hand.</p>}
        {itinerary.map((d, i) => (
          <div key={i} className="grid gap-2 rounded border border-border p-3 sm:grid-cols-[auto_1fr_auto]">
            <div className="pt-2 text-sm font-semibold text-muted-foreground">Day {i + 1}</div>
            <div className="space-y-2">
              <Input aria-label={`Day ${i + 1} title`} placeholder="Title" value={d.title} onChange={(e) => setItinerary((l) => l.map((x, k) => (k === i ? { ...x, title: e.target.value } : x)))} />
              <Textarea aria-label={`Day ${i + 1} details`} rows={2} value={d.description ?? ""} onChange={(e) => setItinerary((l) => l.map((x, k) => (k === i ? { ...x, description: e.target.value } : x)))} />
            </div>
            <Button type="button" size="icon" variant="ghost" aria-label="Remove day" onClick={() => setItinerary((l) => l.filter((_, k) => k !== i))}><Trash2 size={15} /></Button>
          </div>
        ))}
      </Section>

      <div className="flex justify-end gap-2 pb-4">
        <Button variant="outline" onClick={() => router.back()} disabled={saving}>Cancel</Button>
        <Button onClick={save} disabled={saving}>{saving && <Loader2 size={14} className="animate-spin" />} {editing ? "Save quote" : "Create quote"}</Button>
      </div>
    </div>
  );
}
