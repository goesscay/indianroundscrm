"use client";

import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Card } from "@/components/ui/card";
import type { ComponentType } from "react";
import type { ContactEvent } from "@/types";
import { Phone, MessageCircle, Mail, MessageSquare, MapPin, FileText, IndianRupee, Activity } from "lucide-react";

const ICONS: Record<string, ComponentType<{ size?: number }>> = {
  call: Phone, whatsapp: MessageCircle, sms: MessageSquare, email: Mail,
  itinerary_shared: FileText, payment_link_sent: IndianRupee, location: MapPin,
};
const COLORS: Record<string, string> = {
  call: "#0A0A0A", whatsapp: "#25D366", sms: "#8B5CF6", email: "#F59E0B",
  itinerary_shared: "#3B82F6", payment_link_sent: "#10B981", location: "#EF4444",
};
const LABELS: Record<string, string> = {
  call: "Called", whatsapp: "WhatsApp opened", sms: "SMS sent",
  email: "Email opened", itinerary_shared: "Itinerary shared", payment_link_sent: "Payment link sent",
};

export default function ContactTimeline({ leadId, refreshKey }: { leadId: string; refreshKey?: number }) {
  const [events, setEvents] = useState<ContactEvent[]>([]);
  const lm = useLoadMore(events, 10);
  useEffect(() => {
    (async () => {
      try { const { data } = await api.get<ContactEvent[]>(`/leads/${leadId}/contact-events`); setEvents(data); }
      catch { /* ignore */ }
    })();
  }, [leadId, refreshKey]);

  return (
    <Card className="p-6 rounded-lg lg:col-span-3" data-testid="contact-timeline">
      <div className="flex items-center gap-2 mb-4">
        <Activity size={18} className="text-[#0A0A0A]" />
        <h3 className="font-display text-xl font-bold">Contact History</h3>
        <span className="label-caps ml-auto">{events.length} interaction{events.length !== 1 ? "s" : ""}</span>
      </div>
      {events.length === 0 && <div className="text-sm text-muted-foreground">No interactions yet. Every Call / WhatsApp / SMS / Email click is auto-logged here.</div>}
      <div className="relative">
        {events.length > 0 && <div className="absolute left-4 top-1 bottom-1 w-px bg-border" />}
        <div className="space-y-3">
          {lm.visible.map((e) => {
            const Icon = ICONS[e.kind] || Activity;
            const color = COLORS[e.kind] || "#64748B";
            const label = LABELS[e.kind] || e.kind;
            return (
              <div key={e.id} className="flex gap-3 items-start" data-testid={`timeline-${e.id}`}>
                <div className="w-8 h-8 rounded-full flex items-center justify-center shrink-0 z-10"
                     style={{ background: color + "20", color: color }}>
                  <Icon size={14} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium">
                    <span style={{ color }}>{label}</span>
                    {e.target && <span className="text-muted-foreground text-xs ml-1.5">· {e.target}</span>}
                  </div>
                  <div className="label-caps mt-0.5">
                    {e.user_name} · {(e.timestamp || "").slice(0, 16).replace("T", " ")}
                  </div>
                  {e.note && <div className="text-xs text-muted-foreground mt-1">{e.note}</div>}
                </div>
              </div>
            );
          })}
        </div>
      </div>
      <LoadMore lm={lm} className="mt-3" />
    </Card>
  );
}

export const logContact = async (leadId: string, kind: string, target = "") => {
  try { await api.post(`/leads/${leadId}/contact-events`, { kind, target, channel: "customer" }); }
  catch { /* silent */ }
};
