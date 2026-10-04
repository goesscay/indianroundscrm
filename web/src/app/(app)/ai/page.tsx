"use client";

import { useState, type ChangeEvent } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Sparkles } from "lucide-react";
import { toast } from "sonner";

export default function AIAssistant() {
  const [f, setF] = useState({
    destination: "Bali", days: 5, budget: "1,20,000",
    package_type: "Family", adults: 2, children: 0,
    hotel_category: "4 Star", meal_plan: "AP",
  });
  const [busy, setBusy] = useState(false);
  const [out, setOut] = useState("");
  const [type, setType] = useState("itinerary");

  const run = async (t: string) => {
    setType(t); setBusy(true); setOut("");
    try {
      const endpoint = t === "itinerary" ? "/ai/itinerary" : "/ai/quote-recommend";
      const { data } = await api.post<{ content: string }>(endpoint, {
        ...f, days: Number(f.days), adults: Number(f.adults), children: Number(f.children),
      });
      setOut(data.content);
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const set = (k: keyof typeof f) => (e: ChangeEvent<HTMLInputElement>) => setF(p => ({ ...p, [k]: e.target.value }));

  return (
    <div className="space-y-6" data-testid="ai-page">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <div className="label-caps">AI Studio</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">
            Powered by Claude Sonnet 4.5
          </h1>
          <p className="text-sm text-muted-foreground mt-2">
            Generate day-by-day itineraries and smart quote recommendations for any destination.
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card className="p-6 rounded-lg space-y-3">
          <h3 className="font-display text-xl font-bold">Inputs</h3>
          <div className="grid grid-cols-2 gap-3">
            <div className="col-span-2"><Label className="label-caps">Destination</Label><Input className="mt-1.5" value={f.destination} onChange={set("destination")} data-testid="ai-destination" /></div>
            <div><Label className="label-caps">Days</Label><Input type="number" min="1" className="mt-1.5" value={f.days} onChange={set("days")} data-testid="ai-days" /></div>
            <div><Label className="label-caps">Budget (INR)</Label><Input className="mt-1.5" value={f.budget} onChange={set("budget")} data-testid="ai-budget" /></div>
            <div><Label className="label-caps">Adults</Label><Input type="number" min="1" className="mt-1.5" value={f.adults} onChange={set("adults")} data-testid="ai-adults" /></div>
            <div><Label className="label-caps">Children</Label><Input type="number" min="0" className="mt-1.5" value={f.children} onChange={set("children")} data-testid="ai-children" /></div>
            <div><Label className="label-caps">Package Type</Label><Input className="mt-1.5" value={f.package_type} onChange={set("package_type")} data-testid="ai-package" /></div>
            <div><Label className="label-caps">Hotel Category</Label><Input className="mt-1.5" value={f.hotel_category} onChange={set("hotel_category")} data-testid="ai-hotel" /></div>
          </div>
          <div className="flex gap-2 pt-2">
            <Button onClick={() => run("itinerary")} disabled={busy} className="flex-1" style={{ background: "#106EBE" }} data-testid="ai-run-itinerary">
              <Sparkles size={16} /> Generate Itinerary
            </Button>
            <Button onClick={() => run("quote")} disabled={busy} variant="outline" className="flex-1" data-testid="ai-run-quote">
              <Sparkles size={16} /> Suggest Quote
            </Button>
          </div>
        </Card>

        <Card className="p-6 rounded-lg">
          <Tabs value={type} onValueChange={setType}>
            <TabsList>
              <TabsTrigger value="itinerary">Itinerary</TabsTrigger>
              <TabsTrigger value="quote">Quote</TabsTrigger>
            </TabsList>
            <TabsContent value={type} className="mt-4">
              {busy && <div className="text-sm text-muted-foreground animate-pulse">Consulting Claude Sonnet 4.5…</div>}
              {!busy && !out && <div className="text-sm text-muted-foreground">Fill inputs and hit Generate.</div>}
              {!busy && out && (
                <div className="whitespace-pre-wrap text-sm leading-relaxed max-h-[500px] overflow-y-auto" data-testid="ai-output">
                  {out}
                </div>
              )}
            </TabsContent>
          </Tabs>
        </Card>
      </div>
    </div>
  );
}
