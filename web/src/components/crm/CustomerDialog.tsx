"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Loader2, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { Customer, CustomerContact, CustomerTraveller, CustomerType } from "@/types";
import { CUSTOMER_TYPES, useCrmOptions } from "./shared";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Edit this customer; omit to create. `customer.contacts/travellers` must be loaded (detail response). */
  customer?: Customer | null;
  onSaved: (c: Customer) => void;
}

const NONE = "none";
const field = "mt-1.5 h-10";

function Section({ title, children, action }: { title: string; children: React.ReactNode; action?: React.ReactNode }) {
  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between border-b border-border pb-1.5">
        <h4 className="label-caps">{title}</h4>
        {action}
      </div>
      {children}
    </div>
  );
}

export default function CustomerDialog({ open, onOpenChange, customer, onSaved }: Props) {
  const { can, user } = useCurrentUser();
  const options = useCrmOptions();
  const editing = !!customer;

  const [f, setF] = useState<Record<string, string>>({});
  const [type, setType] = useState<CustomerType>("individual");
  const [status, setStatus] = useState("active");
  const [owner, setOwner] = useState(NONE);
  const [contacts, setContacts] = useState<CustomerContact[]>([]);
  const [travellers, setTravellers] = useState<CustomerTraveller[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [dupMsg, setDupMsg] = useState("");

  useEffect(() => {
    if (!open) return;
    setError(""); setDupMsg(""); setSaving(false);
    const c = customer;
    setF({
      name: c?.name ?? "", company: c?.company ?? "", phone: c?.phone ?? "", whatsapp: c?.whatsapp ?? "", email: c?.email ?? "",
      gstin: c?.gstin ?? "", address_line: c?.address_line ?? "", city: c?.city ?? "", state: c?.state ?? "", pincode: c?.pincode ?? "",
      country: c?.country ?? "", source: c?.source ?? "", notes: c?.notes ?? "",
    });
    setType(c?.type ?? "individual"); setStatus(c?.status ?? "active");
    setOwner(c ? (c.owner?.id ?? NONE) : (user.id ? String(user.id) : NONE));
    setContacts((c?.contacts ?? []).map((x) => ({ ...x })));
    setTravellers((c?.travellers ?? []).map((x) => ({ ...x })));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, customer?.id]);

  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setF((p) => ({ ...p, [k]: e.target.value }));
  const canSeePassport = can("customers.view_passports");

  const submit = async (e?: FormEvent, allowDuplicate = false) => {
    e?.preventDefault();
    if (!f.name.trim()) { setError("Please enter the customer's name."); return; }
    setSaving(true); setError(""); setDupMsg("");
    const body = {
      ...f, name: f.name.trim(), type, status, owner_id: owner === NONE ? null : owner,
      contacts: contacts.filter((x) => x.name?.trim()),
      travellers: travellers.filter((x) => x.name?.trim()),
      ...(allowDuplicate ? { allow_duplicate: true } : {}),
    };
    try {
      const { data } = editing
        ? await api.patch<Customer>(`/customers/${customer!.id}`, body)
        : await api.post<Customer>("/customers", body);
      toast.success(editing ? "Customer updated" : `Customer ${data.customer_no} created`);
      onSaved(data);
      onOpenChange(false);
    } catch (err) {
      const msg = formatApiError(err);
      if (!editing && /already exists/i.test(msg)) setDupMsg(msg); else setError(msg);
    } finally { setSaving(false); }
  };

  const upd = <T,>(list: T[], setList: (l: T[]) => void, i: number, patch: Partial<T>) =>
    setList(list.map((x, idx) => (idx === i ? { ...x, ...patch } : x)));

  return (
    <Dialog open={open} onOpenChange={(o) => !saving && onOpenChange(o)}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto" data-testid="customer-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">{editing ? `Edit ${customer!.name}` : "New customer"}</DialogTitle>
          <DialogDescription>{editing ? customer!.customer_no : "Only the name is required. Phone and e-mail are used to spot repeat customers."}</DialogDescription>
        </DialogHeader>

        <form onSubmit={(e) => submit(e)} className="space-y-6">
          <Section title="Customer">
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Label htmlFor="c-name">Name *</Label>
                <Input id="c-name" className={field} value={f.name ?? ""} onChange={set("name")} autoFocus data-testid="customer-name" />
              </div>
              <div>
                <Label>Type</Label>
                <Select value={type} onValueChange={(v) => setType(v as CustomerType)}>
                  <SelectTrigger className={field} data-testid="customer-type"><SelectValue /></SelectTrigger>
                  <SelectContent>{CUSTOMER_TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}</SelectContent>
                </Select>
              </div>
              <div>
                <Label>Owner</Label>
                <Select value={owner} onValueChange={setOwner}>
                  <SelectTrigger className={field} data-testid="customer-owner"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NONE}>Unassigned</SelectItem>
                    {(options?.owners ?? []).map((o) => <SelectItem key={o.id} value={o.id}>{o.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              {(type === "corporate" || type === "agent" || f.company) && (
                <div>
                  <Label htmlFor="c-company">Company</Label>
                  <Input id="c-company" className={field} value={f.company ?? ""} onChange={set("company")} />
                </div>
              )}
              {(type === "corporate" || type === "agent" || f.gstin) && (
                <div>
                  <Label htmlFor="c-gstin">GSTIN</Label>
                  <Input id="c-gstin" className={field} value={f.gstin ?? ""} onChange={set("gstin")} maxLength={20} />
                </div>
              )}
              <div>
                <Label htmlFor="c-phone">Phone</Label>
                <Input id="c-phone" className={field} value={f.phone ?? ""} onChange={set("phone")} inputMode="tel" data-testid="customer-phone" />
              </div>
              <div>
                <Label htmlFor="c-wa">WhatsApp</Label>
                <Input id="c-wa" className={field} value={f.whatsapp ?? ""} onChange={set("whatsapp")} inputMode="tel" placeholder="If different" />
              </div>
              <div className="sm:col-span-2">
                <Label htmlFor="c-email">E-mail</Label>
                <Input id="c-email" type="email" className={field} value={f.email ?? ""} onChange={set("email")} data-testid="customer-email" />
              </div>
              {editing && (
                <div>
                  <Label>Status</Label>
                  <Select value={status} onValueChange={setStatus}>
                    <SelectTrigger className={field}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="active">Active</SelectItem>
                      <SelectItem value="inactive">Inactive</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              )}
              <div>
                <Label htmlFor="c-source">Source</Label>
                <Input id="c-source" className={field} value={f.source ?? ""} onChange={set("source")} list="crm-sources" placeholder="Referral, Instagram…" />
                <datalist id="crm-sources">{(options?.sources ?? []).map((s) => <option key={s} value={s} />)}</datalist>
              </div>
            </div>
          </Section>

          <Section title="Address (used on invoices)">
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="sm:col-span-2"><Label htmlFor="c-addr">Street / building</Label><Input id="c-addr" className={field} value={f.address_line ?? ""} onChange={set("address_line")} /></div>
              <div><Label htmlFor="c-city">City</Label><Input id="c-city" className={field} value={f.city ?? ""} onChange={set("city")} /></div>
              <div><Label htmlFor="c-state">State</Label><Input id="c-state" className={field} value={f.state ?? ""} onChange={set("state")} placeholder="Decides GST on invoices" /></div>
              <div><Label htmlFor="c-pin">PIN code</Label><Input id="c-pin" className={field} value={f.pincode ?? ""} onChange={set("pincode")} /></div>
              <div><Label htmlFor="c-country">Country</Label><Input id="c-country" className={field} value={f.country ?? ""} onChange={set("country")} /></div>
            </div>
          </Section>

          {(type === "corporate" || type === "agent" || contacts.length > 0) && (
            <Section title="Contact people" action={
              <Button type="button" size="sm" variant="outline" onClick={() => setContacts((l) => [...l, { name: "", is_primary: l.length === 0 }])}><Plus size={14} /> Add</Button>}>
              {contacts.length === 0 && <p className="text-sm text-muted-foreground">Add the people you deal with at this company.</p>}
              {contacts.map((c, i) => (
                <div key={i} className="grid gap-2 rounded border border-border p-3 sm:grid-cols-[1.2fr_1fr_1fr_1.2fr_auto]">
                  <Input aria-label="Contact name" placeholder="Name" value={c.name} onChange={(e) => upd(contacts, setContacts, i, { name: e.target.value })} />
                  <Input aria-label="Designation" placeholder="Designation" value={c.designation ?? ""} onChange={(e) => upd(contacts, setContacts, i, { designation: e.target.value })} />
                  <Input aria-label="Contact phone" placeholder="Phone" value={c.phone ?? ""} onChange={(e) => upd(contacts, setContacts, i, { phone: e.target.value })} />
                  <Input aria-label="Contact e-mail" placeholder="E-mail" value={c.email ?? ""} onChange={(e) => upd(contacts, setContacts, i, { email: e.target.value })} />
                  <Button type="button" size="icon" variant="ghost" aria-label="Remove contact" onClick={() => setContacts((l) => l.filter((_, k) => k !== i))}><Trash2 size={15} /></Button>
                </div>
              ))}
            </Section>
          )}

          <Section title="Travellers" action={
            <Button type="button" size="sm" variant="outline" onClick={() => setTravellers((l) => [...l, { name: "" }])} data-testid="add-traveller"><Plus size={14} /> Add traveller</Button>}>
            {travellers.length === 0 && <p className="text-sm text-muted-foreground">People who travel with this customer: names, passports and preferences for bookings.</p>}
            {travellers.map((t, i) => (
              <div key={i} className="space-y-2 rounded border border-border p-3">
                <div className="grid gap-2 sm:grid-cols-[1.4fr_1fr_1fr_auto]">
                  <Input aria-label="Traveller name" placeholder="Full name (as on passport)" value={t.name} onChange={(e) => upd(travellers, setTravellers, i, { name: e.target.value })} />
                  <Input aria-label="Relation" placeholder="Relation" value={t.relation ?? ""} onChange={(e) => upd(travellers, setTravellers, i, { relation: e.target.value })} />
                  <Input aria-label="Date of birth" type="date" value={t.dob ?? ""} onChange={(e) => upd(travellers, setTravellers, i, { dob: e.target.value })} />
                  <Button type="button" size="icon" variant="ghost" aria-label="Remove traveller" onClick={() => setTravellers((l) => l.filter((_, k) => k !== i))}><Trash2 size={15} /></Button>
                </div>
                <div className="grid gap-2 sm:grid-cols-3">
                  <Input aria-label="Passport number" placeholder={canSeePassport ? "Passport number" : "Passport number (hidden)"}
                         value={t.passport_no ?? ""} disabled={!!t.passport_masked && !canSeePassport}
                         onChange={(e) => upd(travellers, setTravellers, i, { passport_no: e.target.value, passport_masked: false })} />
                  <Input aria-label="Passport expiry" type="date" title="Passport expiry" value={t.passport_expiry ?? ""} onChange={(e) => upd(travellers, setTravellers, i, { passport_expiry: e.target.value })} />
                  <Input aria-label="Nationality" placeholder="Nationality" value={t.nationality ?? ""} onChange={(e) => upd(travellers, setTravellers, i, { nationality: e.target.value })} />
                </div>
                <Input aria-label="Preferences" placeholder="Preferences (meals, seats, room type…)" value={t.preferences ?? ""} onChange={(e) => upd(travellers, setTravellers, i, { preferences: e.target.value })} />
              </div>
            ))}
          </Section>

          <Section title="Notes">
            <Textarea rows={3} value={f.notes ?? ""} onChange={set("notes")} placeholder="Anything worth remembering about this customer" />
          </Section>

          {dupMsg && (
            <div className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200" role="alert" data-testid="customer-duplicate">
              {dupMsg}
              <div className="mt-2 flex gap-2">
                <Button type="button" size="sm" variant="outline" onClick={() => submit(undefined, true)} disabled={saving}>Create anyway</Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setDupMsg("")}>Cancel</Button>
              </div>
            </div>
          )}
          {error && <div className="text-sm text-destructive" role="alert">{error}</div>}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" disabled={saving} data-testid="customer-save">
              {saving && <Loader2 size={14} className="animate-spin" />} {editing ? "Save changes" : "Create customer"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
