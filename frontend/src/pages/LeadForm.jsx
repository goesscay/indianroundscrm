import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "sonner";

const F = ({ label, children, testId }) => (
  <div>
    <Label className="label-caps">{label}</Label>
    <div className="mt-1.5" data-testid={testId}>{children}</div>
  </div>
);

export default function LeadForm() {
  const nav = useNavigate();
  const [master, setMaster] = useState({});
  const [f, setF] = useState({
    customer_name: "", phone: "", email: "", travel_date: "", destination: "",
    hotel_category: "", meal_plan: "", package_type: "", budget: "",
    adults: 2, children: 0, child_ages: "", rooms: 1, room_type: "",
    departure_from: "", special_requests: "", lead_source: "",
  });
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    (async () => {
      const { data } = await api.get("/master");
      const grouped = data.reduce((acc, m) => { (acc[m.category] = acc[m.category] || []).push(m.value); return acc; }, {});
      setMaster(grouped);
    })();
  }, []);

  const set = (k) => (v) => setF((p) => ({ ...p, [k]: v?.target ? v.target.value : v }));

  const submit = async (e) => {
    e.preventDefault();
    if (!f.customer_name || !f.phone || !f.destination) {
      toast.error("Customer name, phone and destination are required");
      return;
    }
    setBusy(true);
    try {
      const { data } = await api.post("/leads", { ...f, adults: Number(f.adults), children: Number(f.children), rooms: Number(f.rooms) });
      toast.success(`Lead ${data.lead_id} created`);
      nav(`/leads/${data.lead_id}`);
    } catch (err) { toast.error(formatApiError(err)); }
    finally { setBusy(false); }
  };

  const saveDraft = () => {
    localStorage.setItem("ir_lead_draft", JSON.stringify(f));
    toast.success("Draft saved locally");
  };
  const loadDraft = () => {
    const d = localStorage.getItem("ir_lead_draft");
    if (d) { setF(JSON.parse(d)); toast.success("Draft loaded"); }
    else toast.info("No draft found");
  };

  const SelectMaster = ({ category, value, onChange, placeholder, testId }) => (
    <Select value={value || undefined} onValueChange={onChange}>
      <SelectTrigger className="h-10" data-testid={testId}><SelectValue placeholder={placeholder} /></SelectTrigger>
      <SelectContent>
        {(master[category] || []).map(v => <SelectItem key={v} value={v}>{v}</SelectItem>)}
      </SelectContent>
    </Select>
  );

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
            <F label="Customer Name *"><Input value={f.customer_name} onChange={set("customer_name")} data-testid="f-customer-name" /></F>
            <F label="Phone *"><Input value={f.phone} onChange={set("phone")} data-testid="f-phone" /></F>
            <F label="Email"><Input type="email" value={f.email} onChange={set("email")} data-testid="f-email" /></F>
            <F label="Lead Source"><SelectMaster category="lead_source" value={f.lead_source} onChange={set("lead_source")} placeholder="Website / Instagram…" testId="f-lead-source" /></F>
            <F label="Departure From"><SelectMaster category="departure_city" value={f.departure_from} onChange={set("departure_from")} placeholder="City" testId="f-departure" /></F>
            <F label="Travel Date"><Input type="date" value={f.travel_date} onChange={set("travel_date")} data-testid="f-travel-date" /></F>
          </div>
        </Card>

        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Trip</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <F label="Destination *"><SelectMaster category="destination" value={f.destination} onChange={set("destination")} placeholder="Choose destination" testId="f-destination" /></F>
            <F label="Package Type"><SelectMaster category="package_type" value={f.package_type} onChange={set("package_type")} placeholder="Family / Honeymoon…" testId="f-package-type" /></F>
            <F label="Hotel Category"><SelectMaster category="hotel_category" value={f.hotel_category} onChange={set("hotel_category")} placeholder="4 Star, Villa…" testId="f-hotel-cat" /></F>
            <F label="Meal Plan"><SelectMaster category="meal_plan" value={f.meal_plan} onChange={set("meal_plan")} placeholder="CP / MAP / AP…" testId="f-meal-plan" /></F>
            <F label="Room Type"><SelectMaster category="room_type" value={f.room_type} onChange={set("room_type")} placeholder="Double / Suite…" testId="f-room-type" /></F>
            <F label="Budget (INR)"><Input value={f.budget} onChange={set("budget")} placeholder="e.g. 80,000 – 1,20,000" data-testid="f-budget" /></F>
          </div>
        </Card>

        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Guests</h3>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <F label="Adults"><Input type="number" min="1" value={f.adults} onChange={set("adults")} data-testid="f-adults" /></F>
            <F label="Children"><Input type="number" min="0" value={f.children} onChange={set("children")} data-testid="f-children" /></F>
            <F label="Child Ages"><Input value={f.child_ages} onChange={set("child_ages")} placeholder="e.g. 5, 8" data-testid="f-child-ages" /></F>
            <F label="Rooms"><Input type="number" min="1" value={f.rooms} onChange={set("rooms")} data-testid="f-rooms" /></F>
          </div>
        </Card>

        <Card className="p-6 rounded-lg">
          <h3 className="font-display text-xl font-bold mb-4">Special Requests</h3>
          <Textarea rows={4} value={f.special_requests} onChange={set("special_requests")} placeholder="Any preferences, allergies, celebrations…" data-testid="f-special" />
        </Card>

        <div className="flex justify-end gap-3 pb-24">
          <Button type="button" variant="outline" onClick={() => setF({ customer_name: "", phone: "", email: "", travel_date: "", destination: "", hotel_category: "", meal_plan: "", package_type: "", budget: "", adults: 2, children: 0, child_ages: "", rooms: 1, room_type: "", departure_from: "", special_requests: "", lead_source: "" })} data-testid="reset-btn">Reset</Button>
          <Button type="submit" disabled={busy} className="px-8" style={{ background: "#106EBE" }} data-testid="submit-lead">
            {busy ? "Saving…" : "Create Lead →"}
          </Button>
        </div>
      </form>
    </div>
  );
}
