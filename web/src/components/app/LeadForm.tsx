"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState, useMemo, type ChangeEvent, type FormEvent, type ReactNode } from "react";
import { api, apiErrorStatus, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Combobox } from "@/components/ui/combobox";
import { mergePlaces, myDestinations, placesFirst, tidyPlace } from "@/lib/places";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { toast } from "sonner";
import { AlertTriangle, ExternalLink, Plus, X } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import type { DestinationCities, DuplicateInfo, Lead, LeadCreate, StayCity } from "@/types";

interface LeadFormState extends Omit<LeadCreate, "duration_days" | "child_ages" | "lead_date" | "stay_cities"> {
  owner_id: string; // lead owner: the consultant whose list the lead appears in
  duration_days: string | number;
  child_ages_arr: string[];
  stay_cities: StayCity[];
}

interface ExistingLeadBlock {
  message?: string;
  existing?: DuplicateInfo;
}

const F = ({ label, required, children, testId }: { label: string; required?: boolean; children: ReactNode; testId?: string }) => (
  <div>
    <Label className="label-caps">{label}{required && <span className="text-destructive"> *</span>}</Label>
    <div className="mt-1" data-testid={testId}>{children}</div>
  </div>
);

function SelectMaster({ options, value, onChange, placeholder, testId, custom }: {
  options?: string[]; value?: string; onChange: (v: string) => void; placeholder?: string; testId?: string;
  /** Let the person type a place that is not in the list. */
  custom?: boolean;
}) {
  return (
    <Combobox
      options={options || []} value={value} placeholder={placeholder} testId={testId} allowCustom={custom}
      onChange={(v) => onChange(custom ? tidyPlace(v) : v)}
      searchPlaceholder={custom ? "Type to search, or type a new place…" : "Type to search…"}
    />
  );
}

const PASSPORT_REASONS = [
  "Passport Not Applied",
  "Passport Application in Progress",
  "Passport Expired",
  "Passport Renewal in Progress",
  "Customer Will Apply Later",
];

const EMPTY: LeadFormState = {
  customer_name: "", phone: "", email: "", travel_date: "", duration_days: "",
  destination: "", hotel_category: "", meal_plan: "", package_type: "", budget: "",
  adults: 2, children: 0, child_ages_arr: [], rooms: 1, room_type: "",
  departure_from: "", special_requests: "", lead_source: "",
  stay_cities: [],   // [{city, nights}]
  passport_available: null,  // true / false / null
  passport_reason: "",
  owner_id: "",
};

// Format ISO date + N days later
const addDaysISO = (isoDate: string, days: string | number) => {
  if (!isoDate || !days) return "";
  try {
    const d = new Date(isoDate);
    if (isNaN(d.getTime())) return "";
    d.setDate(d.getDate() + (Number(days) - 1));
    return d.toISOString().slice(0, 10);
  } catch { return ""; }
};

/** Create a lead, or edit an existing one when `leadId` is given. */
export default function LeadForm({ leadId }: { leadId?: string }) {
  const editing = !!leadId;
  const router = useRouter();
  const { user, can } = useCurrentUser();
  const [master, setMaster] = useState<Record<string, string[]>>({});
  const [used, setUsed] = useState<Record<string, string[]>>({});
  const [destCities, setDestCities] = useState<DestinationCities>({});
  const [f, setF] = useState<LeadFormState>(EMPTY);
  const [busy, setBusy] = useState(false);
  const [ageErrors, setAgeErrors] = useState<Record<number, string>>({});
  const [duplicates, setDuplicates] = useState<DuplicateInfo[]>([]);
  const [dupBusy, setDupBusy] = useState(false);
  const [phoneErr, setPhoneErr] = useState("");
  const [blockDialog, setBlockDialog] = useState<ExistingLeadBlock | null>(null);
  const [customCity, setCustomCity] = useState("");
  const [otherReason, setOtherReason] = useState(false);
  const [loaded, setLoaded] = useState(!leadId);
  const [loadError, setLoadError] = useState("");
  const [leadNo, setLeadNo] = useState(leadId ?? "");
  const [owners, setOwners] = useState<{ id: string; name: string; code: string }[]>([]);
  const [ownerName, setOwnerName] = useState(""); // edit mode: current owner, read-only here (use Reassign on the lead page)
  const canPickOwner = can("leads.reassign");
  // default owner: the logged-in sales person. Operations / Admin leave it on Auto (the destination's allocated consultant) unless they pick
  const defaultOwner = !canPickOwner && owners.some((o) => o.id === user.id) ? user.id : "";

  const cleanPhone = (raw: string) => (raw || "").replace(/\D/g, "").replace(/^91(?=\d{10}$)/, "").replace(/^0(?=\d{10}$)/, "");
  const validPhone = (raw: string) => {
    const d = cleanPhone(raw);
    if (d.length !== 10) return "Please enter a valid 10-digit mobile number.";
    if (!/^[6-9]/.test(d)) return "Indian mobile must start with 6, 7, 8 or 9.";
    return "";
  };

  useEffect(() => {
    // Dropdown values come from /lead-options (open to anyone who can create or edit leads), not from the admin-only Master Data endpoints.
    api.get<{ master: Record<string, string[]>; used?: Record<string, string[]>; cities: DestinationCities; owners?: { id: string; name: string; code: string }[] }>("/lead-options")
      .then(({ data }) => { setMaster(data.master || {}); setUsed(data.used || {}); setDestCities(data.cities || {}); setOwners(data.owners || []); })
      .catch((e) => toast.error(`Could not load the dropdown lists: ${formatApiError(e)}`));
  }, []);

  useEffect(() => {
    if (!leadId) return;
    let off = false;
    api.get<Lead>(`/leads/${leadId}`).then(({ data: l }) => {
      if (off) return;
      const ages = (l.child_ages || "").split(/[\s,]+/).filter(Boolean);
      setLeadNo(l.lead_id);
      setF({
        customer_name: l.customer_name || "", phone: l.phone || "", email: l.email || "", travel_date: (l.travel_date || "").slice(0, 10),
        duration_days: l.duration_days ?? "", destination: l.destination || "", hotel_category: l.hotel_category || "", meal_plan: l.meal_plan || "",
        package_type: l.package_type || "", budget: l.budget || "", adults: l.adults ?? 1, children: l.children ?? 0, child_ages_arr: ages,
        rooms: l.rooms ?? 1, room_type: l.room_type || "", departure_from: l.departure_from || "", special_requests: l.special_requests || "",
        lead_source: l.lead_source || "", stay_cities: (l.stay_cities || []).map((c) => ({ city: c.city, nights: c.nights ?? null })),
        passport_available: l.passport_available ?? null, passport_reason: l.passport_reason || "", owner_id: l.staff_id ? String(l.staff_id) : "",
      });
      setOwnerName(l.staff_name || "");
      setOtherReason(!!l.passport_reason && !PASSPORT_REASONS.includes(l.passport_reason));
      setLoaded(true);
    }).catch((e) => { if (!off) setLoadError(formatApiError(e)); });
    return () => { off = true; };
  }, [leadId]);

  useEffect(() => {
    setF((p) => {
      const n = Math.max(0, Math.min(10, Number(p.children) || 0));
      const arr = Array.from({ length: n }, (_, i) => (p.child_ages_arr[i] ?? ""));
      if (arr.length === p.child_ages_arr.length && arr.every((v, i) => v === p.child_ages_arr[i])) return p;
      return { ...p, child_ages_arr: arr };
    });
  }, [f.children]);

  useEffect(() => {
    const p = (f.phone || "").trim();
    const e = (f.email || "").trim();
    if (!p && !e) { setDuplicates([]); return; }
    const t = setTimeout(async () => {
      setDupBusy(true);
      try {
        const { data } = await api.get<{ matches?: DuplicateInfo[] }>("/leads/duplicate-check", { params: { phone: p, email: e } });
        setDuplicates((data.matches || []).filter((m) => m.lead_id !== leadId));
      } catch { /* ignore */ }
      finally { setDupBusy(false); }
    }, 500);
    return () => clearTimeout(t);
  }, [f.phone, f.email, leadId]);

  // once the consultants are known, the owner defaults to the logged-in person (create mode)
  useEffect(() => { if (!editing && defaultOwner) setF((p) => (p.owner_id ? p : { ...p, owner_id: defaultOwner })); }, [editing, defaultOwner]);
  // the destination this person works, unless they pick another
  const mine = myDestinations(user);
  const myDestination = mine[0] ?? "";   // the primary one
  useEffect(() => { if (!editing && myDestination) setF((p) => (p.destination ? p : { ...p, destination: myDestination })); }, [editing, myDestination]);

  const set = (k: keyof LeadFormState) => (v: string | ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    setF((p) => ({ ...p, [k]: typeof v === "string" ? v : v.target.value }));

  const setChildAge = (idx: number, val: string) => {
    setF((p) => {
      const arr = [...p.child_ages_arr];
      arr[idx] = val;
      return { ...p, child_ages_arr: arr };
    });
    setAgeErrors((prev) => ({ ...prev, [idx]: validateAge(val) }));
  };

  const validateAge = (val: string | number | null | undefined) => {
    if (val === "" || val === null || val === undefined) return "Age required";
    if (!/^-?\d+$/.test(String(val))) return "Whole number only";
    const n = parseInt(String(val), 10);
    if (n < 0) return "Cannot be negative";
    if (n > 12) return "Max age is 12";
    return "";
  };

  const validateAllAges = () => {
    const errs: Record<number, string> = {};
    let ok = true;
    f.child_ages_arr.forEach((v, i) => {
      const e = validateAge(v);
      if (e) { errs[i] = e; ok = false; }
    });
    setAgeErrors(errs);
    return ok;
  };

  // ------- Derived values -------
  const nights = f.duration_days ? Math.max(0, Number(f.duration_days) - 1) : 0;
  const durationLabel = f.duration_days ? `${nights}N / ${Number(f.duration_days)}D` : "";
  const tripEndDate = addDaysISO(f.travel_date, f.duration_days);
  const availableCities = destCities[f.destination] || [];
  const staySummary = (f.stay_cities || []).map(sc => sc.nights ? `${sc.city} ${sc.nights}N` : sc.city).join(" + ");
  const totalStayNights = (f.stay_cities || []).reduce((s, sc) => s + (Number(sc.nights) || 0), 0);
  const nightsMismatch = f.duration_days && totalStayNights > 0 && totalStayNights !== nights;

  // ------- City handlers -------
  const toggleCity = (city: string, checked: boolean) => {
    setF(p => {
      const rest = p.stay_cities.filter(sc => sc.city !== city);
      return { ...p, stay_cities: checked ? [...rest, { city, nights: null }] : rest };
    });
  };
  const setCityNights = (city: string, nights: string) => {
    setF(p => ({
      ...p,
      stay_cities: p.stay_cities.map(sc => sc.city === city ? { ...sc, nights: nights ? Number(nights) : null } : sc),
    }));
  };
  const addCustomCity = () => {
    const v = customCity.trim();
    if (!v) return;
    if (f.stay_cities.some(sc => sc.city.toLowerCase() === v.toLowerCase())) { toast.info("City already added"); return; }
    setF(p => ({ ...p, stay_cities: [...p.stay_cities, { city: v, nights: null }] }));
    setCustomCity("");
  };
  const removeCity = (city: string) => {
    setF(p => ({ ...p, stay_cities: p.stay_cities.filter(sc => sc.city !== city) }));
  };

  // ------- Passport handlers -------
  const setPassport = (val: boolean | null) => {
    setF(p => ({
      ...p,
      passport_available: val,
      passport_reason: val === false ? p.passport_reason : "",
    }));
    if (val !== false) setOtherReason(false);
  };
  const setReason = (v: string) => {
    if (v === "__other__") {
      setOtherReason(true);
      setF(p => ({ ...p, passport_reason: "" }));
      return;
    }
    setOtherReason(false);
    setF(p => ({ ...p, passport_reason: v }));
  };

  const addAnother = useRef(false); // set by "Save and add another"; kept across the duplicate-warning dialog

  const submit = async (e: FormEvent | null, force = false) => {
    if (e) e.preventDefault();
    const pErr = validPhone(f.phone);
    if (pErr) { setPhoneErr(pErr); toast.error(pErr); return; }
    if (!f.customer_name || !f.destination) {
      toast.error("Customer name and destination are required");
      return;
    }
    if (f.children > 0 && !validateAllAges()) {
      toast.error("Fix child age errors before submitting");
      return;
    }
    setBusy(true);
    try {
      const passportReason = f.passport_available === false
        ? (f.passport_reason || "").trim()
        : "";
      const { child_ages_arr: ageList, owner_id: ownerId, ...rest } = f;
      const payload = {
        ...rest,
        phone: cleanPhone(f.phone),
        adults: Number(f.adults),
        children: Number(f.children),
        rooms: Number(f.rooms),
        duration_days: f.duration_days ? Number(f.duration_days) : null,
        child_ages: ageList.join(", "),
        stay_cities: f.stay_cities.filter(sc => sc.city && sc.city.trim())
                                  .map(sc => ({ city: sc.city.trim(), nights: sc.nights || null })),
        passport_available: f.passport_available,
        passport_reason: passportReason,
        ...(!editing && ownerId ? { owner_id: ownerId } : {}),
      };
      if (editing) {
        await api.patch<Lead>(`/leads/${leadId}`, payload);
        toast.success(`Lead ${leadNo} updated`);
        router.push(`/leads/${leadId}`);
        return;
      }
      const url = force ? "/leads?force=true" : "/leads";
      const { data } = await api.post<Lead>(url, payload);
      if (addAnother.current) {
        toast.success(`Lead ${data.lead_id} saved. Add the next one.`);
        setF({ ...EMPTY, owner_id: f.owner_id, destination: myDestination }); setAgeErrors({}); setPhoneErr(""); setOtherReason(false);
        window.scrollTo({ top: 0, behavior: "smooth" });
        return;
      }
      // If the destination was auto-allocated to a different sales consultant, sales won't see the lead in their list
      if (!can("leads.view_all") && data.staff_id && data.staff_id !== user.id) {
        toast.success(`Lead ${data.lead_id} created — assigned to ${data.staff_name || "another consultant"}`);
        router.push("/leads");
      } else {
        toast.success(`Lead ${data.lead_id} created`);
        router.push(`/leads/${data.lead_id}`);
      }
    } catch (err) {
      const detail = (err as { response?: { data?: { detail?: ExistingLeadBlock } } }).response?.data?.detail;
      if (!editing && apiErrorStatus(err) === 409 && detail && typeof detail === "object" && detail.existing) {
        setBlockDialog(detail);
      } else {
        toast.error(formatApiError(err));
      }
    }
    finally { setBusy(false); }
  };

  const kids = useMemo(() => Number(f.children) || 0, [f.children]);
  const reasonIsPreset = PASSPORT_REASONS.includes(f.passport_reason);
  const showManualReasonInput = otherReason || (f.passport_reason && !reasonIsPreset);
  const reasonSelectValue = showManualReasonInput
    ? "__other__"
    : (reasonIsPreset ? f.passport_reason : undefined);

  if (loadError) return <div className="space-y-3"><Button variant="ghost" size="sm" onClick={() => router.push("/leads")}>Back to leads</Button><div className="text-sm text-destructive">{loadError}</div></div>;
  if (!loaded) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;

  return (
    <div className="space-y-4" data-testid="new-lead-page">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <div className="label-caps">{editing ? "Lead detail" : "Sales entry"}</div>
          <h1 className="font-display text-2xl sm:text-3xl font-black tracking-tight mt-0.5">{editing ? `Edit Lead ${leadNo}` : "New Lead"}</h1>
          <p className="text-xs text-muted-foreground mt-1">{editing ? "Change any detail and save. The lead keeps its ID, status and history." : "Lead ID auto-generated on save (e.g. IR07001)."}</p>
        </div>
        {!editing && (
          <Button asChild variant="outline" size="sm">
            <a href="/leads/new" target="_blank" title="Open another blank lead form in a new tab" data-testid="new-lead-window">
              <ExternalLink size={14} /> Open in new tab
            </a>
          </Button>
        )}
      </div>

      <form onSubmit={submit} className="space-y-3">
        {/* Customer */}
        <Card className="p-4 rounded-lg">
          <h3 className="font-display text-base font-bold mb-3">Customer</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-4 gap-x-3 gap-y-2.5">
            <F label="Lead Owner" testId="f-owner-wrap">
              {editing ? <Input value={ownerName || "—"} disabled data-testid="f-owner" />
                : canPickOwner
                  ? <Combobox options={owners.map((o) => ({ value: o.id, label: o.code ? `${o.name} · ${o.code}` : o.name }))} value={f.owner_id}
                              onChange={set("owner_id")} placeholder="Auto: by destination" searchPlaceholder="Search consultant…" clearLabel="Auto: by destination" clearValue="auto" testId="f-owner" />
                  : <Input value={user.name} disabled data-testid="f-owner" />}
            </F>
            <F label="Customer Name" required><Input value={f.customer_name} onChange={set("customer_name")} data-testid="f-customer-name" /></F>
            <F label="Mobile Number" required>
              <Input
                value={f.phone}
                onChange={(e) => { const v = e.target.value; setF(p => ({ ...p, phone: v })); setPhoneErr(validPhone(v)); }}
                placeholder="10-digit Indian mobile"
                inputMode="numeric"
                maxLength={13}
                className={phoneErr ? "border-destructive" : ""}
                data-testid="f-phone"
              />
              {phoneErr && <div className="text-xs text-destructive mt-1" data-testid="f-phone-err">{phoneErr}</div>}
            </F>
            <F label="Email"><Input type="email" value={f.email} onChange={set("email")} data-testid="f-email" /></F>
            <F label="Lead Source"><SelectMaster options={master.lead_source} value={f.lead_source} onChange={set("lead_source")} placeholder="Website / Instagram…" testId="f-lead-source" /></F>
            <F label="Departure From"><SelectMaster custom options={mergePlaces(master.departure_city, used.departure_city)} value={f.departure_from} onChange={set("departure_from")} placeholder="Choose or type a city" testId="f-departure" /></F>
          </div>
          {duplicates.length > 0 && (
            <div className="mt-3 p-3 rounded-md border border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40" data-testid="duplicate-warning">
              <div className="flex items-center gap-2 text-amber-900 dark:text-amber-300 font-semibold text-sm">
                <AlertTriangle size={16} /> Possible duplicate — {duplicates.length} existing lead(s) match this phone/email
              </div>
              <div className="mt-2 space-y-1">
                {duplicates.slice(0, 3).map((d) => (
                  <div key={d.lead_id} className="text-xs text-amber-900 dark:text-amber-300 font-mono">
                    <span className="font-bold">{d.lead_id}</span> · {d.customer_name} · {d.destination} · {d.lead_status} · by {d.staff_name}
                  </div>
                ))}
              </div>
              <div className="text-[11px] text-amber-800 dark:text-amber-300 mt-2">You can still submit — this is just a warning.</div>
            </div>
          )}
          {dupBusy && <div className="text-xs text-muted-foreground mt-2">Checking for duplicates…</div>}
        </Card>

        {/* Trip */}
        <Card className="p-4 rounded-lg">
          <h3 className="font-display text-base font-bold mb-3">Trip</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-4 gap-x-3 gap-y-2.5">
            <F label="Destination" required>
              <SelectMaster custom options={placesFirst(mergePlaces(master.destination, used.destination), mine)} value={f.destination}
                            onChange={(v) => setF(p => ({ ...p, destination: v, stay_cities: [] }))}
                            placeholder="Choose or type a destination" testId="f-destination" />
            </F>
            <F label="Travel Start Date">
              <Input type="date" value={f.travel_date} onChange={set("travel_date")} data-testid="f-travel-date" />
            </F>
            <F label="No. of Days">
              <Input type="number" min="1" max="90" value={f.duration_days}
                     onChange={(e) => set("duration_days")(e.target.value.replace(/\D/g, ""))}
                     placeholder="e.g. 4" data-testid="f-days" />
            </F>
            <div className="md:col-span-3 grid grid-cols-1 sm:grid-cols-2 gap-4 p-3 rounded-md bg-secondary/40 border border-border" data-testid="trip-derived">
              <div>
                <div className="label-caps">Trip End Date (auto)</div>
                <div className="mt-1 font-display text-lg font-bold" data-testid="derived-end-date">{tripEndDate || "—"}</div>
              </div>
              <div>
                <div className="label-caps">Duration (auto)</div>
                <div className="mt-1 font-display text-lg font-bold" data-testid="derived-duration">{durationLabel || "—"}</div>
              </div>
            </div>
            <F label="Package Type"><SelectMaster options={master.package_type} value={f.package_type} onChange={set("package_type")} placeholder="Family / Honeymoon…" testId="f-package-type" /></F>
            <F label="Hotel Category"><SelectMaster options={master.hotel_category} value={f.hotel_category} onChange={set("hotel_category")} placeholder="4 Star, Villa…" testId="f-hotel-cat" /></F>
            <F label="Meal Plan"><SelectMaster options={master.meal_plan} value={f.meal_plan} onChange={set("meal_plan")} placeholder="CP / MAP / AP…" testId="f-meal-plan" /></F>
            <F label="Room Type"><SelectMaster options={master.room_type} value={f.room_type} onChange={set("room_type")} placeholder="Double / Suite…" testId="f-room-type" /></F>
            <F label="Budget (INR)"><Input value={f.budget} onChange={set("budget")} placeholder="e.g. 80,000 – 1,20,000" data-testid="f-budget" /></F>
          </div>
        </Card>

        {/* Stay Cities */}
        {f.destination && (
          <Card className="p-4 rounded-lg" data-testid="stay-cities-card">
            <div className="flex items-end justify-between flex-wrap gap-3 mb-1">
              <div>
                <h3 className="font-display text-xl font-bold">Which city / area will you stay in?</h3>
                <p className="text-xs text-muted-foreground mt-1">Pick one or more cities for {f.destination}. Nights per city is optional.</p>
              </div>
              {staySummary && (
                <div className="text-right">
                  <div className="label-caps">Stay summary</div>
                  <div className="font-display font-bold" data-testid="stay-summary">{staySummary}</div>
                </div>
              )}
            </div>

            {availableCities.length === 0 && (
              <div className="text-xs text-muted-foreground mt-3 py-3 px-4 rounded-md border border-dashed border-border">
                No preset cities for {f.destination} yet. Admin can add cities from Master Data → Destination Cities. Use the manual entry below.
              </div>
            )}

            {availableCities.length > 0 && (
              <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2 mt-3">
                {availableCities.map(city => {
                  const sel = f.stay_cities.find(sc => sc.city === city);
                  return (
                    <label key={city}
                           className={`flex items-center gap-2 px-3 py-2 rounded-md border cursor-pointer transition-colors ${sel ? "border-[#0A0A0A] dark:border-white/70 bg-[#0A0A0A]/5 dark:bg-white/5" : "border-border hover:bg-secondary/40"}`}
                           data-testid={`f-city-${city}`}>
                      <Checkbox checked={!!sel} onCheckedChange={(v) => toggleCity(city, !!v)} />
                      <span className="flex-1 text-sm font-medium">{city}</span>
                      {sel && (
                        <Input
                          type="number" min="1" max="30"
                          value={sel.nights || ""}
                          onChange={(e) => setCityNights(city, e.target.value)}
                          onClick={(e) => e.preventDefault()}
                          placeholder="N"
                          className="h-7 w-14 text-xs"
                          data-testid={`f-city-nights-${city}`}
                        />
                      )}
                    </label>
                  );
                })}
              </div>
            )}

            {/* Custom city input */}
            <div className="mt-4">
              <Label className="label-caps">+ Other / Manual Entry</Label>
              <div className="flex gap-2 mt-1.5">
                <Input value={customCity} onChange={e => setCustomCity(e.target.value)}
                       onKeyDown={e => e.key === "Enter" && (e.preventDefault(), addCustomCity())}
                       placeholder="Type a city not in the list…" data-testid="f-custom-city" />
                <Button type="button" onClick={addCustomCity} variant="outline" data-testid="f-add-custom-city">
                  <Plus size={14} /> Add
                </Button>
              </div>
            </div>

            {/* Chips of custom cities (not in preset list) */}
            {f.stay_cities.filter(sc => !availableCities.includes(sc.city)).length > 0 && (
              <div className="flex flex-wrap gap-2 mt-3" data-testid="f-custom-city-chips">
                {f.stay_cities.filter(sc => !availableCities.includes(sc.city)).map(sc => (
                  <span key={sc.city} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-[#0A0A0A]/10 dark:bg-white/10 text-sm border border-[#0A0A0A]/30 dark:border-white/30">
                    <span className="font-medium">{sc.city}</span>
                    <Input type="number" min="1" max="30" value={sc.nights || ""}
                           onChange={(e) => setCityNights(sc.city, e.target.value)}
                           placeholder="N" className="h-6 w-12 text-xs" />
                    <button type="button" onClick={() => removeCity(sc.city)} className="text-muted-foreground hover:text-destructive"><X size={12} /></button>
                  </span>
                ))}
              </div>
            )}

            {nightsMismatch && (
              <div className="mt-4 p-3 rounded-md border border-amber-300 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40 text-xs text-amber-900 dark:text-amber-300" data-testid="stay-mismatch-warning">
                <AlertTriangle size={12} className="inline -mt-0.5 mr-1" />
                Stay nights ({totalStayNights}N) do not match the selected trip duration ({nights}N).
                Please check the details. You can still save — this is a warning only.
              </div>
            )}
          </Card>
        )}

        {/* Guests */}
        <Card className="p-4 rounded-lg">
          <h3 className="font-display text-base font-bold mb-3">Guests</h3>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <F label="Adults"><Input type="number" min="1" value={f.adults} onChange={set("adults")} data-testid="f-adults" /></F>
            <F label="Number of Children">
              <Input
                type="number" min="0" max="10" value={f.children}
                onChange={(e) => {
                  const n = Math.max(0, Math.min(10, parseInt(e.target.value || "0", 10) || 0));
                  setF((p) => ({ ...p, children: n }));
                }}
                data-testid="f-children"
              />
            </F>
            <F label="Rooms"><Input type="number" min="1" value={f.rooms} onChange={set("rooms")} data-testid="f-rooms" /></F>
          </div>

          {kids > 0 && (
            <div className="mt-6" data-testid="child-ages-section">
              <div className="label-caps mb-3">Child Ages · 0–12 years</div>
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
                {f.child_ages_arr.map((v, i) => (
                  <div key={i}>
                    <Label className="label-caps">Child {i + 1} Age <span className="text-destructive">*</span></Label>
                    <Input
                      type="number" min="0" max="12" step="1"
                      value={v}
                      onChange={(e) => setChildAge(i, e.target.value)}
                      onBlur={(e) => setChildAge(i, e.target.value)}
                      className={`mt-1.5 ${ageErrors[i] ? "border-destructive" : ""}`}
                      data-testid={`f-child-age-${i}`}
                    />
                    {ageErrors[i] && <div className="text-xs text-destructive mt-1" data-testid={`f-child-age-err-${i}`}>{ageErrors[i]}</div>}
                  </div>
                ))}
              </div>
            </div>
          )}
        </Card>

        {/* Passport */}
        <Card className="p-4 rounded-lg" data-testid="passport-card">
          <h3 className="font-display text-xl font-bold mb-1">Passport Available?</h3>
          <p className="text-xs text-muted-foreground mb-4">Required for international travel processing.</p>
          <div className="flex gap-3 flex-wrap">
            <button type="button" onClick={() => setPassport(true)}
                    className={`px-6 h-11 rounded-md border font-semibold text-sm transition-colors ${f.passport_available === true ? "bg-emerald-500 text-white border-emerald-500" : "bg-card border-border hover:bg-secondary"}`}
                    data-testid="f-passport-yes">
              YES
            </button>
            <button type="button" onClick={() => setPassport(false)}
                    className={`px-6 h-11 rounded-md border font-semibold text-sm transition-colors ${f.passport_available === false ? "bg-red-500 text-white border-red-500" : "bg-card border-border hover:bg-secondary"}`}
                    data-testid="f-passport-no">
              NO
            </button>
          </div>

          {f.passport_available === false && (
            <div className="mt-5 space-y-3" data-testid="passport-reason-section">
              <div>
                <Label className="label-caps">Why is passport not available?</Label>
                <Select value={reasonSelectValue} onValueChange={setReason}>
                  <SelectTrigger className="mt-1" data-testid="f-passport-reason-select">
                    <SelectValue placeholder="Choose a reason" />
                  </SelectTrigger>
                  <SelectContent>
                    {PASSPORT_REASONS.map(r => <SelectItem key={r} value={r}>{r}</SelectItem>)}
                    <SelectItem value="__other__">+ Other / Enter Manually</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              {showManualReasonInput && (
                <div>
                  <Label className="label-caps">Enter reason</Label>
                  <Input className="mt-1.5"
                         value={f.passport_reason}
                         onChange={e => setF(p => ({ ...p, passport_reason: e.target.value }))}
                         placeholder="Custom reason…"
                         data-testid="f-passport-reason-custom" />
                </div>
              )}
            </div>
          )}
        </Card>

        <Card className="p-4 rounded-lg">
          <h3 className="font-display text-base font-bold mb-3">Special Requests</h3>
          <Textarea rows={2} value={f.special_requests} onChange={set("special_requests")} placeholder="Any preferences, allergies, celebrations…" data-testid="f-special" />
        </Card>

        {/* sticky action bar: always in reach however long the form is */}
        <div className="sticky bottom-0 z-20 -mx-3 sm:-mx-4 md:-mx-6 mt-4 flex items-center justify-end gap-2 border-t border-border bg-background/95 px-3 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur sm:px-4 md:px-6" data-testid="lead-action-bar">
          <Button type="button" variant="ghost" onClick={() => router.push(editing ? `/leads/${leadId}` : "/leads")} data-testid={editing ? "edit-cancel" : "cancel-lead"}>Cancel</Button>
          {!editing && (
            <Button type="button" variant="outline" disabled={busy || !!validPhone(f.phone)} onClick={() => { addAnother.current = true; void submit(null); }} data-testid="save-add-another">
              Save and add another
            </Button>
          )}
          <Button type="submit" disabled={busy || !!validPhone(f.phone)} onClick={() => { addAnother.current = false; }} className="px-6" data-testid="submit-lead">
            {busy ? "Saving…" : editing ? "Save changes" : "Save lead"}
          </Button>
        </div>
      </form>

      <Dialog open={!!blockDialog} onOpenChange={(o) => !o && setBlockDialog(null)}>
        <DialogContent data-testid="dup-block-dialog">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-amber-700 dark:text-amber-300">
              <AlertTriangle size={18} /> Lead already exists
            </DialogTitle>
          </DialogHeader>
          {blockDialog?.existing && (
            <div className="text-sm space-y-1.5 border border-border rounded-md p-4 bg-secondary/40">
              <div><span className="label-caps">Lead ID:</span> <span className="font-mono font-bold">{blockDialog.existing.lead_id}</span></div>
              <div><span className="label-caps">Customer:</span> {blockDialog.existing.customer_name}</div>
              <div><span className="label-caps">Sales Consultant:</span> {blockDialog.existing.staff_name}</div>
              <div><span className="label-caps">Destination:</span> {blockDialog.existing.destination}</div>
              <div><span className="label-caps">Status:</span> {blockDialog.existing.lead_status}</div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setBlockDialog(null)}>Cancel</Button>
            <Button onClick={() => { const lid = blockDialog?.existing?.lead_id; setBlockDialog(null); if (lid) router.push(`/leads/${lid}`); }} data-testid="open-existing-lead">Open Existing</Button>
            {can("users.manage") && (
              <Button onClick={() => { setBlockDialog(null); submit(null, true); }} data-testid="force-create-lead">
                Create anyway (Admin)
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
