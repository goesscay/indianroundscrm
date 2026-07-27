import { useEffect, useState, useMemo } from "react";
import { useNavigate } from "react-router-dom";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { toast } from "sonner";
import { AlertTriangle, Plus, X } from "lucide-react";
import { useAuth } from "@/context/AuthContext";

const F = ({ label, required, children, testId }) => (
  <div>
    <Label className="label-caps">{label}{required && <span className="text-destructive"> *</span>}</Label>
    <div className="mt-1.5" data-testid={testId}>{children}</div>
  </div>
);

function SelectMaster({ options, value, onChange, placeholder, testId }) {
  return (
    <Select value={value || undefined} onValueChange={onChange}>
      <SelectTrigger className="h-10" data-testid={testId}><SelectValue placeholder={placeholder} /></SelectTrigger>
      <SelectContent>
        {(options || []).map(v => <SelectItem key={v} value={v}>{v}</SelectItem>)}
      </SelectContent>
    </Select>
  );
}

const PASSPORT_REASONS = [
  "Passport Not Applied",
  "Passport Application in Progress",
  "Passport Expired",
  "Passport Renewal in Progress",
  "Customer Will Apply Later",
];

const EMPTY = {
  customer_name: "", phone: "", email: "", travel_date: "", duration_days: "",
  destination: "", hotel_category: "", meal_plan: "", package_type: "", budget: "",
  adults: 2, children: 0, child_ages_arr: [], rooms: 1, room_type: "",
  departure_from: "", special_requests: "", lead_source: "",
  stay_cities: [],   // [{city, nights}]
  passport_available: null,  // true / false / null
  passport_reason: "",
};

// Format ISO date + N days later
const addDaysISO = (isoDate, days) => {
  if (!isoDate || !days) return "";
  try {
    const d = new Date(isoDate);
    if (isNaN(d)) return "";
    d.setDate(d.getDate() + (Number(days) - 1));
    return d.toISOString().slice(0, 10);
  } catch { return ""; }
};

export default function LeadForm() {
  const nav = useNavigate();
  const { user } = useAuth();
  const [master, setMaster] = useState({});
  const [destCities, setDestCities] = useState({});
  const [f, setF] = useState(EMPTY);
  const [busy, setBusy] = useState(false);
  const [ageErrors, setAgeErrors] = useState({});
  const [duplicates, setDuplicates] = useState([]);
  const [dupBusy, setDupBusy] = useState(false);
  const [phoneErr, setPhoneErr] = useState("");
  const [blockDialog, setBlockDialog] = useState(null);
  const [customCity, setCustomCity] = useState("");
  const [otherReason, setOtherReason] = useState(false);

  const cleanPhone = (raw) => (raw || "").replace(/\D/g, "").replace(/^91(?=\d{10}$)/, "").replace(/^0(?=\d{10}$)/, "");
  const validPhone = (raw) => {
    const d = cleanPhone(raw);
    if (d.length !== 10) return "Please enter a valid 10-digit mobile number.";
    if (!/^[6-9]/.test(d)) return "Indian mobile must start with 6, 7, 8 or 9.";
    return "";
  };

  useEffect(() => {
    (async () => {
      const [m, dc] = await Promise.all([
        api.get("/master"),
        api.get("/destination-cities"),
      ]);
      const grouped = m.data.reduce((acc, x) => { (acc[x.category] = acc[x.category] || []).push(x.value); return acc; }, {});
      setMaster(grouped);
      setDestCities(dc.data || {});
    })();
  }, []);

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
        const { data } = await api.get("/leads/duplicate-check", { params: { phone: p, email: e } });
        setDuplicates(data.matches || []);
      } catch { /* ignore */ }
      finally { setDupBusy(false); }
    }, 500);
    return () => clearTimeout(t);
  }, [f.phone, f.email]);

  const set = (k) => (v) => setF((p) => ({ ...p, [k]: v?.target ? v.target.value : v }));

  const setChildAge = (idx, val) => {
    setF((p) => {
      const arr = [...p.child_ages_arr];
      arr[idx] = val;
      return { ...p, child_ages_arr: arr };
    });
    setAgeErrors((prev) => ({ ...prev, [idx]: validateAge(val) }));
  };

  const validateAge = (val) => {
    if (val === "" || val === null || val === undefined) return "Age required";
    if (!/^-?\d+$/.test(String(val))) return "Whole number only";
    const n = parseInt(val, 10);
    if (n < 0) return "Cannot be negative";
    if (n > 12) return "Max age is 12";
    return "";
  };

  const validateAllAges = () => {
    const errs = {};
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
  const toggleCity = (city, checked) => {
    setF(p => {
      const rest = p.stay_cities.filter(sc => sc.city !== city);
      return { ...p, stay_cities: checked ? [...rest, { city, nights: null }] : rest };
    });
  };
  const setCityNights = (city, nights) => {
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
  const removeCity = (city) => {
    setF(p => ({ ...p, stay_cities: p.stay_cities.filter(sc => sc.city !== city) }));
  };

  // ------- Passport handlers -------
  const setPassport = (val) => {
    setF(p => ({
      ...p,
      passport_available: val,
      passport_reason: val === false ? p.passport_reason : "",
    }));
    if (val !== false) setOtherReason(false);
  };
  const setReason = (v) => {
    if (v === "__other__") {
      setOtherReason(true);
      setF(p => ({ ...p, passport_reason: "" }));
      return;
    }
    setOtherReason(false);
    setF(p => ({ ...p, passport_reason: v }));
  };

  const submit = async (e, force = false) => {
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
      const payload = {
        ...f,
        phone: cleanPhone(f.phone),
        adults: Number(f.adults),
        children: Number(f.children),
        rooms: Number(f.rooms),
        duration_days: f.duration_days ? Number(f.duration_days) : null,
        child_ages: f.child_ages_arr.join(", "),
        stay_cities: f.stay_cities.filter(sc => sc.city && sc.city.trim())
                                  .map(sc => ({ city: sc.city.trim(), nights: sc.nights || null })),
        passport_available: f.passport_available,
        passport_reason: passportReason,
      };
      delete payload.child_ages_arr;
      const url = force ? "/leads?force=true" : "/leads";
      const { data } = await api.post(url, payload);
      // If the destination was auto-allocated to a different sales consultant, sales won't see the lead in their list
      if (user.role === "sales" && data.staff_id && data.staff_id !== user.id) {
        toast.success(`Lead ${data.lead_id} created — assigned to ${data.staff_name || "another consultant"}`);
        nav("/leads");
      } else {
        toast.success(`Lead ${data.lead_id} created`);
        nav(`/leads/${data.lead_id}`);
      }
    } catch (err) {
      const detail = err?.response?.data?.detail;
      if (err?.response?.status === 409 && detail?.existing) {
        setBlockDialog(detail);
      } else {
        toast.error(formatApiError(err));
      }
    }
    finally { setBusy(false); }
  };

  const saveDraft = () => { localStorage.setItem("ir_lead_draft", JSON.stringify(f)); toast.success("Draft saved locally"); };
  const loadDraft = () => {
    const d = localStorage.getItem("ir_lead_draft");
    if (d) { setF({ ...EMPTY, ...JSON.parse(d) }); toast.success("Draft loaded"); }
    else toast.info("No draft found");
  };

  const kids = useMemo(() => Number(f.children) || 0, [f.children]);
  const reasonIsPreset = PASSPORT_REASONS.includes(f.passport_reason);
  const showManualReasonInput = otherReason || (f.passport_reason && !reasonIsPreset);
  const reasonSelectValue = showManualReasonInput
    ? "__other__"
    : (reasonIsPreset ? f.passport_reason : undefined);

  return (
    <div className="space-y-6" data-testid="new-lead-page">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <div className="label-caps">Sales entry</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">New Lead</h1>
          <p className="text-sm text-muted-foreground mt-2">Lead ID auto-generated on save (e.g. IR07001).</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={loadDraft} data-testid="load-draft">Load Draft</Button>
          <Button variant="outline" onClick={saveDraft} data-testid="save-draft">Save Draft</Button>
        </div>
      </div>

      <form onSubmit={submit} className="space-y-6">
        {/* Customer */}
        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Customer</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
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
            <F label="Departure From"><SelectMaster options={master.departure_city} value={f.departure_from} onChange={set("departure_from")} placeholder="City" testId="f-departure" /></F>
          </div>
          {duplicates.length > 0 && (
            <div className="mt-4 p-4 rounded-md border border-amber-300 bg-amber-50" data-testid="duplicate-warning">
              <div className="flex items-center gap-2 text-amber-900 font-semibold text-sm">
                <AlertTriangle size={16} /> Possible duplicate — {duplicates.length} existing lead(s) match this phone/email
              </div>
              <div className="mt-2 space-y-1">
                {duplicates.slice(0, 3).map((d) => (
                  <div key={d.lead_id} className="text-xs text-amber-900 font-mono">
                    <span className="font-bold">{d.lead_id}</span> · {d.customer_name} · {d.destination} · {d.lead_status} · by {d.staff_name}
                  </div>
                ))}
              </div>
              <div className="text-[11px] text-amber-800 mt-2">You can still submit — this is just a warning.</div>
            </div>
          )}
          {dupBusy && <div className="text-xs text-muted-foreground mt-2">Checking for duplicates…</div>}
        </Card>

        {/* Trip */}
        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Trip</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <F label="Destination" required>
              <SelectMaster options={master.destination} value={f.destination}
                            onChange={(v) => setF(p => ({ ...p, destination: v, stay_cities: [] }))}
                            placeholder="Choose destination" testId="f-destination" />
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
          <Card className="p-6 rounded-lg" data-testid="stay-cities-card">
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
                           className={`flex items-center gap-2 px-3 py-2 rounded-md border cursor-pointer transition-colors ${sel ? "border-[#106EBE] bg-[#106EBE]/5" : "border-border hover:bg-secondary/40"}`}
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
                  <span key={sc.city} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-[#106EBE]/10 text-sm border border-[#106EBE]/30">
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
              <div className="mt-4 p-3 rounded-md border border-amber-300 bg-amber-50 text-xs text-amber-900" data-testid="stay-mismatch-warning">
                <AlertTriangle size={12} className="inline -mt-0.5 mr-1" />
                Stay nights ({totalStayNights}N) do not match the selected trip duration ({nights}N).
                Please check the details. You can still save — this is a warning only.
              </div>
            )}
          </Card>
        )}

        {/* Guests */}
        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Guests</h3>
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
        <Card className="p-6 rounded-lg" data-testid="passport-card">
          <h3 className="font-display text-xl font-bold mb-1">Passport Available?</h3>
          <p className="text-xs text-muted-foreground mb-4">Required for international travel processing.</p>
          <div className="flex gap-3 flex-wrap">
            <button type="button" onClick={() => setPassport(true)}
                    className={`px-6 h-11 rounded-md border font-semibold text-sm transition-colors ${f.passport_available === true ? "bg-emerald-500 text-white border-emerald-500" : "bg-white border-border hover:bg-secondary"}`}
                    data-testid="f-passport-yes">
              YES
            </button>
            <button type="button" onClick={() => setPassport(false)}
                    className={`px-6 h-11 rounded-md border font-semibold text-sm transition-colors ${f.passport_available === false ? "bg-red-500 text-white border-red-500" : "bg-white border-border hover:bg-secondary"}`}
                    data-testid="f-passport-no">
              NO
            </button>
          </div>

          {f.passport_available === false && (
            <div className="mt-5 space-y-3" data-testid="passport-reason-section">
              <div>
                <Label className="label-caps">Why is passport not available?</Label>
                <Select value={reasonSelectValue} onValueChange={setReason}>
                  <SelectTrigger className="h-10 mt-1.5" data-testid="f-passport-reason-select">
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

        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Special Requests</h3>
          <Textarea rows={4} value={f.special_requests} onChange={set("special_requests")} placeholder="Any preferences, allergies, celebrations…" data-testid="f-special" />
        </Card>

        <div className="flex justify-end gap-3 pb-24">
          <Button type="button" variant="outline" onClick={() => { setF(EMPTY); setAgeErrors({}); }} data-testid="reset-btn">Reset</Button>
          <Button type="submit" disabled={busy || !!validPhone(f.phone)} className="px-8" style={{ background: "#106EBE" }} data-testid="submit-lead">
            {busy ? "Saving…" : "Create Lead →"}
          </Button>
        </div>
      </form>

      <Dialog open={!!blockDialog} onOpenChange={(o) => !o && setBlockDialog(null)}>
        <DialogContent data-testid="dup-block-dialog">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-amber-700">
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
              <div><span className="label-caps">Last Follow-up:</span> {(blockDialog.existing.follow_up_date || "—").slice(0,10)}</div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setBlockDialog(null)}>Cancel</Button>
            <Button onClick={() => { const lid = blockDialog?.existing?.lead_id; setBlockDialog(null); if (lid) nav(`/leads/${lid}`); }} data-testid="open-existing-lead">Open Existing</Button>
            {user.role === "admin" && (
              <Button style={{ background: "#106EBE" }} onClick={() => { setBlockDialog(null); submit(null, true); }} data-testid="force-create-lead">
                Create anyway (Admin)
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
