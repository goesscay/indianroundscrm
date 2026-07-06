import { useEffect, useState, useMemo } from "react";
import { useNavigate } from "react-router-dom";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "sonner";
import { AlertTriangle } from "lucide-react";

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

const EMPTY = {
  customer_name: "", phone: "", email: "", travel_date: "", destination: "",
  hotel_category: "", meal_plan: "", package_type: "", budget: "",
  adults: 2, children: 0, child_ages_arr: [], rooms: 1, room_type: "",
  departure_from: "", special_requests: "", lead_source: "",
};

export default function LeadForm() {
  const nav = useNavigate();
  const [master, setMaster] = useState({});
  const [f, setF] = useState(EMPTY);
  const [busy, setBusy] = useState(false);
  const [ageErrors, setAgeErrors] = useState({});
  const [duplicates, setDuplicates] = useState([]);
  const [dupBusy, setDupBusy] = useState(false);

  useEffect(() => {
    (async () => {
      const { data } = await api.get("/master");
      const grouped = data.reduce((acc, m) => { (acc[m.category] = acc[m.category] || []).push(m.value); return acc; }, {});
      setMaster(grouped);
    })();
  }, []);

  // Sync child_ages_arr length with children count
  useEffect(() => {
    setF((p) => {
      const n = Math.max(0, Math.min(10, Number(p.children) || 0));
      const arr = Array.from({ length: n }, (_, i) => (p.child_ages_arr[i] ?? ""));
      if (arr.length === p.child_ages_arr.length && arr.every((v, i) => v === p.child_ages_arr[i])) return p;
      return { ...p, child_ages_arr: arr };
    });
  }, [f.children]);

  // Duplicate detection debounced
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

  const submit = async (e) => {
    e.preventDefault();
    if (!f.customer_name || !f.phone || !f.destination) {
      toast.error("Customer name, phone and destination are required");
      return;
    }
    if (f.children > 0 && !validateAllAges()) {
      toast.error("Fix child age errors before submitting");
      return;
    }
    setBusy(true);
    try {
      const payload = {
        ...f,
        adults: Number(f.adults),
        children: Number(f.children),
        rooms: Number(f.rooms),
        child_ages: f.child_ages_arr.join(", "),
      };
      delete payload.child_ages_arr;
      const { data } = await api.post("/leads", payload);
      toast.success(`Lead ${data.lead_id} created`);
      nav(`/leads/${data.lead_id}`);
    } catch (err) { toast.error(formatApiError(err)); }
    finally { setBusy(false); }
  };

  const saveDraft = () => { localStorage.setItem("ir_lead_draft", JSON.stringify(f)); toast.success("Draft saved locally"); };
  const loadDraft = () => {
    const d = localStorage.getItem("ir_lead_draft");
    if (d) { setF({ ...EMPTY, ...JSON.parse(d) }); toast.success("Draft loaded"); }
    else toast.info("No draft found");
  };

  const kids = useMemo(() => Number(f.children) || 0, [f.children]);

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
        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Customer</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <F label="Customer Name" required><Input value={f.customer_name} onChange={set("customer_name")} data-testid="f-customer-name" /></F>
            <F label="Phone" required><Input value={f.phone} onChange={set("phone")} data-testid="f-phone" /></F>
            <F label="Email"><Input type="email" value={f.email} onChange={set("email")} data-testid="f-email" /></F>
            <F label="Lead Source"><SelectMaster options={master.lead_source} value={f.lead_source} onChange={set("lead_source")} placeholder="Website / Instagram…" testId="f-lead-source" /></F>
            <F label="Departure From"><SelectMaster options={master.departure_city} value={f.departure_from} onChange={set("departure_from")} placeholder="City" testId="f-departure" /></F>
            <F label="Travel Date"><Input type="date" value={f.travel_date} onChange={set("travel_date")} data-testid="f-travel-date" /></F>
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

        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Trip</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <F label="Destination" required><SelectMaster options={master.destination} value={f.destination} onChange={set("destination")} placeholder="Choose destination" testId="f-destination" /></F>
            <F label="Package Type"><SelectMaster options={master.package_type} value={f.package_type} onChange={set("package_type")} placeholder="Family / Honeymoon…" testId="f-package-type" /></F>
            <F label="Hotel Category"><SelectMaster options={master.hotel_category} value={f.hotel_category} onChange={set("hotel_category")} placeholder="4 Star, Villa…" testId="f-hotel-cat" /></F>
            <F label="Meal Plan"><SelectMaster options={master.meal_plan} value={f.meal_plan} onChange={set("meal_plan")} placeholder="CP / MAP / AP…" testId="f-meal-plan" /></F>
            <F label="Room Type"><SelectMaster options={master.room_type} value={f.room_type} onChange={set("room_type")} placeholder="Double / Suite…" testId="f-room-type" /></F>
            <F label="Budget (INR)"><Input value={f.budget} onChange={set("budget")} placeholder="e.g. 80,000 – 1,20,000" data-testid="f-budget" /></F>
          </div>
        </Card>

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

        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Special Requests</h3>
          <Textarea rows={4} value={f.special_requests} onChange={set("special_requests")} placeholder="Any preferences, allergies, celebrations…" data-testid="f-special" />
        </Card>

        <div className="flex justify-end gap-3 pb-24">
          <Button type="button" variant="outline" onClick={() => { setF(EMPTY); setAgeErrors({}); }} data-testid="reset-btn">Reset</Button>
          <Button type="submit" disabled={busy} className="px-8" style={{ background: "#106EBE" }} data-testid="submit-lead">
            {busy ? "Saving…" : "Create Lead →"}
          </Button>
        </div>
      </form>
    </div>
  );
}
