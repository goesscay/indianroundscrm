"use client";

import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { ConvertPreview, CustomerType, DealStage } from "@/types";
import { CUSTOMER_TYPES, STAGES } from "./shared";

interface Result { customer: { id: string; customer_no: string; name: string; created: boolean }; deal: { id: string; deal_no: string; name: string; stage: string } | null }

interface Props {
  leadId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConverted: (r: Result) => void;
}

const NEW = "new";
const field = "mt-1.5 h-10";

export default function ConvertLeadDialog({ leadId, open, onOpenChange, onConverted }: Props) {
  const [pre, setPre] = useState<ConvertPreview | null>(null);
  const [loadErr, setLoadErr] = useState("");
  const [target, setTarget] = useState<string>(NEW);
  const [cName, setCName] = useState("");
  const [cPhone, setCPhone] = useState("");
  const [cEmail, setCEmail] = useState("");
  const [cType, setCType] = useState<CustomerType>("individual");
  const [makeDeal, setMakeDeal] = useState(true);
  const [dName, setDName] = useState("");
  const [dStage, setDStage] = useState<DealStage>("enquiry");
  const [dAmount, setDAmount] = useState("");
  const [dClose, setDClose] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setPre(null); setLoadErr(""); setError(""); setSaving(false);
    api.get<ConvertPreview>(`/leads/${leadId}/convert-preview`).then(({ data }) => {
      setPre(data);
      const c = data.customer_suggestion; const d = data.deal_suggestion;
      setTarget(data.customer ? data.customer.id : (data.matches.find((m) => m.visible)?.id ?? NEW));
      setCName(c.name ?? ""); setCPhone(c.phone ?? ""); setCEmail(c.email ?? ""); setCType(c.type ?? "individual");
      setMakeDeal(true); setDName(d.name ?? ""); setDStage((d.stage as DealStage) ?? "enquiry");
      setDAmount(d.selling_amount ? String(d.selling_amount) : ""); setDClose(d.expected_close ?? "");
    }).catch((e) => setLoadErr(formatApiError(e)));
  }, [open, leadId]);

  const submit = async () => {
    if (!pre) return;
    if (target === NEW && !cName.trim()) { setError("Enter the customer's name."); return; }
    setSaving(true); setError("");
    const customerPart = pre.customer ? {} : target === NEW
      ? { customer: { name: cName.trim(), phone: cPhone.trim(), email: cEmail.trim(), type: cType, owner_id: pre.customer_suggestion.owner_id } }
      : { customer_id: target };
    const deal = makeDeal ? { name: dName.trim() || undefined, stage: dStage, selling_amount: dAmount.trim() === "" ? undefined : Number(dAmount), expected_close: dClose || null } : undefined;
    try {
      const { data } = await api.post<Result>(`/leads/${leadId}/convert`, { ...customerPart, create_deal: makeDeal, ...(deal ? { deal } : {}) });
      toast.success(data.deal ? `Converted: ${data.customer.customer_no} and ${data.deal.deal_no}` : `Customer ${data.customer.customer_no} linked`);
      onConverted(data);
      onOpenChange(false);
    } catch (e) { setError(formatApiError(e)); }
    finally { setSaving(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto" data-testid="convert-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">Convert lead {leadId}</DialogTitle>
          <DialogDescription>Create the customer and the deal for this trip. The lead stays as the history of how they came to you.</DialogDescription>
        </DialogHeader>

        {loadErr && <div className="text-sm text-destructive" role="alert">{loadErr}</div>}
        {!pre && !loadErr && <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground"><Loader2 size={16} className="animate-spin" /> Checking for an existing customer…</div>}

        {pre && (
          <div className="space-y-5">
            <section className="space-y-3">
              <h4 className="label-caps border-b border-border pb-1.5">Customer</h4>
              {pre.customer ? (
                <p className="text-sm">This lead is already linked to <span className="font-medium">{pre.customer.name}</span> <span className="font-mono text-xs text-muted-foreground">{pre.customer.customer_no}</span>.</p>
              ) : (
                <>
                  {pre.matches.length > 0 && (
                    <div className="rounded border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/40" data-testid="convert-matches">
                      <p className="mb-2 font-medium text-amber-900 dark:text-amber-200">We found {pre.matches.length === 1 ? "a customer" : "customers"} with this phone or e-mail.</p>
                      <div className="space-y-1.5">
                        {pre.matches.map((m) => (
                          <label key={m.id} className={`flex items-center gap-2 ${m.visible ? "cursor-pointer" : "opacity-60"}`}>
                            <input type="radio" name="convert-target" value={m.id} checked={target === m.id} disabled={!m.visible} onChange={() => setTarget(m.id)} />
                            <span className="truncate">Link to <span className="font-medium">{m.name}</span> <span className="font-mono text-xs">{m.customer_no}</span>{m.phone ? ` · ${m.phone}` : ""}{!m.visible ? " (owned by someone else)" : ""}</span>
                          </label>
                        ))}
                        <label className="flex cursor-pointer items-center gap-2">
                          <input type="radio" name="convert-target" value={NEW} checked={target === NEW} onChange={() => setTarget(NEW)} /> Create a new customer anyway
                        </label>
                      </div>
                    </div>
                  )}
                  {target === NEW && (
                    <div className="grid gap-3 sm:grid-cols-2">
                      <div className="sm:col-span-2"><Label htmlFor="cv-name">Name *</Label><Input id="cv-name" className={field} value={cName} onChange={(e) => setCName(e.target.value)} data-testid="convert-name" /></div>
                      <div><Label htmlFor="cv-phone">Phone</Label><Input id="cv-phone" className={field} value={cPhone} onChange={(e) => setCPhone(e.target.value)} /></div>
                      <div><Label htmlFor="cv-email">E-mail</Label><Input id="cv-email" className={field} value={cEmail} onChange={(e) => setCEmail(e.target.value)} /></div>
                      <div>
                        <Label>Type</Label>
                        <Select value={cType} onValueChange={(v) => setCType(v as CustomerType)}>
                          <SelectTrigger className={field}><SelectValue /></SelectTrigger>
                          <SelectContent>{CUSTOMER_TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}</SelectContent>
                        </Select>
                      </div>
                    </div>
                  )}
                </>
              )}
            </section>

            <section className="space-y-3">
              <div className="flex items-center justify-between border-b border-border pb-1.5">
                <h4 className="label-caps">Deal</h4>
                <label className="flex cursor-pointer items-center gap-2 text-sm">
                  <Checkbox checked={makeDeal} onCheckedChange={(v) => setMakeDeal(v === true)} data-testid="convert-make-deal" /> Create a deal
                </label>
              </div>
              {makeDeal ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <div className="sm:col-span-2"><Label htmlFor="cv-dname">Deal name</Label><Input id="cv-dname" className={field} value={dName} onChange={(e) => setDName(e.target.value)} /></div>
                  <div>
                    <Label>Stage</Label>
                    <Select value={dStage} onValueChange={(v) => setDStage(v as DealStage)}>
                      <SelectTrigger className={field} data-testid="convert-stage"><SelectValue /></SelectTrigger>
                      <SelectContent>{STAGES.map((s) => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}</SelectContent>
                    </Select>
                  </div>
                  <div><Label htmlFor="cv-amt">Selling amount (₹)</Label><Input id="cv-amt" type="number" min={0} className={field} value={dAmount} onChange={(e) => setDAmount(e.target.value)} /></div>
                  <div><Label htmlFor="cv-close">Expected close</Label><Input id="cv-close" type="date" className={field} value={dClose} onChange={(e) => setDClose(e.target.value)} /></div>
                </div>
              ) : <p className="text-sm text-muted-foreground">Only the customer is created now. You can convert again later to add the deal.</p>}
            </section>

            {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
          <Button onClick={submit} disabled={saving || !pre} data-testid="convert-submit">
            {saving && <Loader2 size={14} className="animate-spin" />} Convert
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
