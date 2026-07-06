import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "sonner";
import { X } from "lucide-react";

const CATEGORIES = [
  { key: "destination", label: "Destinations" },
  { key: "hotel_category", label: "Hotel Categories" },
  { key: "meal_plan", label: "Meal Plans" },
  { key: "package_type", label: "Package Types" },
  { key: "room_type", label: "Room Types" },
  { key: "departure_city", label: "Departure Cities" },
  { key: "payment_mode", label: "Payment Modes" },
  { key: "lead_source", label: "Lead Sources" },
];

export default function MasterData() {
  const [items, setItems] = useState([]);
  const [category, setCategory] = useState("destination");
  const [value, setValue] = useState("");

  const load = async () => {
    try { const { data } = await api.get("/master"); setItems(data); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  useEffect(() => { load(); }, []);

  const add = async (e) => {
    e.preventDefault();
    if (!value.trim()) return;
    try { await api.post("/master", { category, value: value.trim() }); toast.success("Added"); setValue(""); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  const remove = async (id) => {
    try { await api.delete(`/master/${id}`); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <div className="space-y-6" data-testid="master-page">
      <div>
        <div className="label-caps">Configuration</div>
        <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">Master Data</h1>
      </div>

      <Card className="p-6 rounded-lg">
        <form onSubmit={add} className="flex flex-wrap gap-3 items-end">
          <div className="flex-1 min-w-[200px]">
            <Select value={category} onValueChange={setCategory}>
              <SelectTrigger className="h-10" data-testid="m-category"><SelectValue /></SelectTrigger>
              <SelectContent>
                {CATEGORIES.map(c => <SelectItem key={c.key} value={c.key}>{c.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="flex-1 min-w-[200px]">
            <Input value={value} onChange={e => setValue(e.target.value)} placeholder="Add new value…" data-testid="m-value" />
          </div>
          <Button type="submit" style={{ background: "#106EBE" }} data-testid="m-add">Add</Button>
        </form>
      </Card>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {CATEGORIES.map(c => {
          const rows = items.filter(i => i.category === c.key);
          return (
            <Card key={c.key} className="p-5 rounded-lg" data-testid={`m-cat-${c.key}`}>
              <div className="label-caps">{c.label}</div>
              <div className="mt-3 flex flex-wrap gap-2">
                {rows.length === 0 && <div className="text-xs text-muted-foreground">No items</div>}
                {rows.map(r => (
                  <span key={r.id} className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-secondary text-sm">
                    {r.value}
                    <button onClick={() => remove(r.id)} className="text-muted-foreground hover:text-destructive"><X size={12} /></button>
                  </span>
                ))}
              </div>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
