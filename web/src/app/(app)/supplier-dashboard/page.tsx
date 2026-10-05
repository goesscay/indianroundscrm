"use client";

import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { formatApiError } from "@/lib/api";
import type { LucideIcon } from "lucide-react";
import type { RsqLaneKey, RsqLanes, RsqRequest } from "@/types";
import { Mail, RefreshCw, ArrowRight, Clock, MessageSquare, CheckCircle2 } from "lucide-react";

interface Lane {
  key: RsqLaneKey;
  label: string;
  icon: LucideIcon;
  accent: string;
  dot: string;
  pulse: boolean;
  hint: string;
}

const LANES: Lane[] = [
  { key: "new", label: "New Requests", icon: Mail, accent: "border-l-amber-400 bg-amber-50/40", dot: "bg-amber-500", pulse: true, hint: "Awaiting Ops to send supplier mail" },
  { key: "pending_reply", label: "Pending Supplier Replies", icon: Clock, accent: "border-l-red-400 bg-red-50/40", dot: "bg-red-500", pulse: true, hint: "Mail sent — waiting for supplier" },
  { key: "response_received", label: "Responses Received", icon: MessageSquare, accent: "border-l-blue-400 bg-blue-50/40", dot: "bg-blue-500", pulse: false, hint: "Reply received — review & quote customer" },
  { key: "completed", label: "Completed", icon: CheckCircle2, accent: "border-l-emerald-400 bg-emerald-50/40", dot: "bg-emerald-500", pulse: false, hint: "Quote sent / booked / closed" },
];

const fmtDate = (iso?: string | null) => {
  if (!iso) return "—";
  try { return new Date(iso).toLocaleString("en-IN", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }); }
  catch { return iso.slice(0, 16); }
};

function LaneCard({ lane, items }: { lane: Lane; items: RsqRequest[] }) {
  const Icon = lane.icon;
  const lm = useLoadMore(items, 10);
  return (
    <Card className={`p-4 rounded-lg border-l-4 ${lane.accent}`} data-testid={`lane-${lane.key}`}>
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          <Icon size={18} />
          <div className="font-display font-bold">{lane.label}</div>
          <span className={`inline-flex items-center justify-center min-w-[24px] h-6 px-2 rounded-full text-xs font-bold bg-white border border-border`}>{items.length}</span>
        </div>
      </div>
      <div className="text-[11px] text-muted-foreground mb-3">{lane.hint}</div>
      <div className="space-y-2 max-h-[560px] overflow-y-auto pr-1">
        {items.length === 0 && (
          <div className="text-sm text-muted-foreground text-center py-8 border border-dashed border-border rounded-md">
            Nothing here yet
          </div>
        )}
        {lm.visible.map((r) => (
          <Link key={r.id || r.lead_id} href={`/leads/${r.lead_id}`} className="block" data-testid={`rsq-card-${r.lead_id}`}>
            <div className="p-3 bg-white rounded-md border border-border hover:border-[#0A0A0A] hover:shadow-sm transition-all">
              <div className="flex items-center justify-between gap-2">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="font-mono font-bold text-sm">{r.lead_id}</span>
                    {lane.pulse && <span className={`inline-block w-1.5 h-1.5 rounded-full ${lane.dot} animate-ping`} />}
                  </div>
                  <div className="text-sm font-semibold mt-0.5 truncate">{r.customer_name || "—"}</div>
                  <div className="text-xs text-muted-foreground truncate">{r.destination || "—"} · Staff {r.staff_name || "—"}</div>
                </div>
                <ArrowRight size={16} className="text-muted-foreground shrink-0" />
              </div>
              <div className="flex items-center justify-between mt-2 text-[11px] text-muted-foreground">
                <span>
                  {lane.key === "new" && `Raised ${fmtDate(r.requested_at)} by ${r.requested_by_name || ""}`}
                  {lane.key === "pending_reply" && `Sent ${fmtDate(r.sent_at)} · ${r.supplier_count} supplier${r.supplier_count === 1 ? "" : "s"}`}
                  {lane.key === "response_received" && `${r.reply_count}/${r.supplier_count} replied · first ${fmtDate(r.first_reply_at)}`}
                  {lane.key === "completed" && `Completed ${fmtDate(r.completed_at)}`}
                </span>
                {r.note && <span className="italic text-muted-foreground truncate max-w-[140px]" title={r.note}>“{r.note}”</span>}
              </div>
            </div>
          </Link>
        ))}
        <LoadMore lm={lm} />
      </div>
    </Card>
  );
}

export default function SupplierDashboard() {
  const [data, setData] = useState<RsqLanes>({ new: [], pending_reply: [], response_received: [], completed: [] });
  const [loading, setLoading] = useState(true);
  const [lastRefresh, setLastRefresh] = useState(new Date());

  const load = async () => {
    try {
      const { data } = await api.get<RsqLanes>("/rsq-requests");
      setData(data);
      setLastRefresh(new Date());
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setLoading(false); }
  };

  useEffect(() => {
    load();
    const t = setInterval(load, 30000);
    return () => clearInterval(t);
  }, []);

  const totals = {
    active: (data.new?.length || 0) + (data.pending_reply?.length || 0) + (data.response_received?.length || 0),
    completed: data.completed?.length || 0,
  };

  return (
    <div className="space-y-6" data-testid="supplier-dashboard-page">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <div className="label-caps">Operations · Workflow</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight">Supplier Dashboard</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Manage all supplier quotation requests in one place. Auto-refreshes every 30 seconds.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <div className="text-right hidden sm:block">
            <div className="text-xs text-muted-foreground">Active</div>
            <div className="font-display font-black text-2xl leading-none">{totals.active}</div>
          </div>
          <div className="text-right hidden sm:block">
            <div className="text-xs text-muted-foreground">Completed</div>
            <div className="font-display font-black text-2xl leading-none text-emerald-600">{totals.completed}</div>
          </div>
          <Button variant="outline" onClick={load} data-testid="refresh-btn">
            <RefreshCw size={14} className={loading ? "animate-spin" : ""} /> Refresh
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        {LANES.map((lane) => (
          <LaneCard key={lane.key} lane={lane} items={data[lane.key] || []} />
        ))}
      </div>

      <div className="text-xs text-muted-foreground text-center">
        Last refreshed {lastRefresh.toLocaleTimeString("en-IN")}
      </div>
    </div>
  );
}
