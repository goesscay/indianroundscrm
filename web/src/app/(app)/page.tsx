"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import type { DashboardStats, LeaderboardRow, SupplierSummary, UpcomingFollowup } from "@/types";
import { useEffect, useState, useRef, type DragEvent, type HTMLAttributes } from "react";
import type { LucideIcon } from "lucide-react";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import FollowupCountdown from "@/components/app/FollowupCountdown";
import {
  ResponsiveContainer, BarChart, Bar, XAxis, YAxis, Tooltip, CartesianGrid,
  PieChart, Pie, Cell, LineChart, Line, Legend
} from "recharts";
import { TrendingUp, Users2, IndianRupee, CalendarClock, Clock, CheckCircle2, XCircle, Layers, GripVertical, RotateCcw, Phone, MessageCircle } from "lucide-react";

const INR = (n: number | string | null | undefined) => "₹" + (Number(n) || 0).toLocaleString("en-IN", { maximumFractionDigits: 0 });

function Metric({ label, value, tone }: { label: string; value: string | number; tone?: "primary" | "accent" | "amber" | "red" | "orange" }) {
  const tones: Record<string, string> = {
    primary: "bg-[#EFEFEB] text-[#0A0A0A] border border-[#E0E0DA]",
    accent: "bg-[#E5E5E5] text-[#0A0A0A]",
    amber: "bg-amber-100 text-amber-900",
    red: "bg-red-100 text-red-900",
    orange: "bg-orange-100 text-orange-900",
  };
  return (
    <div className={`p-3 rounded-md ${(tone && tones[tone]) || "bg-secondary"}`}>
      <div className={`label-caps opacity-80`}>{label}</div>
      <div className="font-display font-black text-2xl tracking-tight mt-1">{value}</div>
    </div>
  );
}

interface KpiProps {
  label: string;
  value: string | number;
  icon: LucideIcon;
  tone?: "default" | "primary" | "accent";
  testId: string;
  dragHandlers?: HTMLAttributes<HTMLDivElement>;
}

function Kpi({ label, value, icon: Icon, tone = "default", testId, dragHandlers }: KpiProps) {
  const tones: Record<string, string> = {
    default: "bg-card",
    primary: "bg-[#EFEFEB] text-[#0A0A0A] border border-[#E0E0DA]",
    accent: "bg-[#E5E5E5] text-[#0A0A0A]",
  };
  const isDark = false;
  return (
    <Card
      className={`p-4 rounded-lg border border-border ${tones[tone]} transition-all hover:-translate-y-0.5 hover:shadow-sm relative group cursor-move`}
      data-testid={testId}
      draggable
      {...dragHandlers}
    >
      <GripVertical size={14} className={`absolute top-2 right-2 opacity-0 group-hover:opacity-60 transition-opacity ${isDark ? "text-white" : "text-muted-foreground"}`} />
      <div className="flex items-start justify-between">
        <div>
          <div className={`label-caps ${isDark ? "text-white/70!" : ""}`}>{label}</div>
          <div className={`kpi-value mt-2 ${isDark ? "" : "text-foreground"}`}>{value}</div>
        </div>
        <Icon size={22} className={isDark ? "opacity-70" : "text-muted-foreground"} />
      </div>
    </Card>
  );
}

const DEFAULT_ORDER = ["total_leads", "today_leads", "booked", "quote_pending", "revenue", "profit", "followups", "cancelled"];

export default function Dashboard() {
  const { user } = useCurrentUser();
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [leaderboard, setLeaderboard] = useState<LeaderboardRow[]>([]);
  const [supSummary, setSupSummary] = useState<SupplierSummary | null>(null);
  const [followups, setFollowups] = useState<UpcomingFollowup[]>([]);
  const [err, setErr] = useState("");
  const router = useRouter();
  const storageKey = `ir_kpi_order_${user.id}`;
  const [order, setOrder] = useState(() => {
    try { return (JSON.parse(localStorage.getItem(storageKey) ?? "null") as string[] | null) || DEFAULT_ORDER; }
    catch { return DEFAULT_ORDER; }
  });
  const dragIdx = useRef<number | null>(null);

  useEffect(() => { localStorage.setItem(storageKey, JSON.stringify(order)); }, [order, storageKey]);

  useEffect(() => {
    (async () => {
      try {
        const { data } = await api.get<DashboardStats>("/dashboard/stats");
        setStats(data);
        if (user.role === "admin") {
          const lb = await api.get<LeaderboardRow[]>("/dashboard/leaderboard");
          setLeaderboard(lb.data);
        }
        if (user.role === "admin" || user.role === "operations") {
          try { const sup = await api.get<SupplierSummary>("/lead-suppliers/dashboard/summary"); setSupSummary(sup.data); } catch { /* ignore */ }
        }
        try { const fu = await api.get<UpcomingFollowup[]>("/followups/upcoming?hours=72"); setFollowups(fu.data); } catch { /* ignore */ }
      } catch (e) { setErr(formatApiError(e)); }
    })();
  }, [user.role]);

  if (!stats) return <div className="text-muted-foreground text-sm">Loading dashboard…{err && ` · ${err}`}</div>;

  const kpiMap: Record<string, Omit<KpiProps, "dragHandlers">> = {
    total_leads: { label: "Total Leads", value: stats.total_leads, icon: Layers, testId: "kpi-total-leads" },
    today_leads: { label: "Today's Leads", value: stats.today_leads, icon: CalendarClock, tone: "primary", testId: "kpi-today" },
    booked: { label: "Booked", value: stats.booked, icon: CheckCircle2, tone: "accent", testId: "kpi-booked" },
    quote_pending: { label: "Quote Pending", value: stats.quote_pending, icon: Clock, testId: "kpi-quote-pending" },
    revenue: { label: "Revenue", value: INR(stats.revenue), icon: IndianRupee, testId: "kpi-revenue" },
    profit: { label: "Profit", value: INR(stats.profit), icon: TrendingUp, testId: "kpi-profit" },
    followups: { label: "Follow-ups Today", value: stats.followups_today, icon: Users2, testId: "kpi-followups" },
    cancelled: { label: "Cancelled", value: stats.cancelled, icon: XCircle, testId: "kpi-cancelled" },
  };

  const onDragStart = (i: number) => (e: DragEvent<HTMLDivElement>) => { dragIdx.current = i; e.dataTransfer.effectAllowed = "move"; };
  const onDragOver = (i: number) => (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    if (dragIdx.current === null || dragIdx.current === i) return;
    setOrder((prev) => {
      const next = [...prev];
      const [moved] = next.splice(dragIdx.current as number, 1);
      next.splice(i, 0, moved);
      dragIdx.current = i;
      return next;
    });
  };
  const onDragEnd = () => { dragIdx.current = null; };

  const statusChart = Object.entries(stats.status_counts || {}).map(([k, v]) => ({ name: k, value: v }));
  const COLORS = ["#0A0A0A", "#525252", "#8A8A8A", "#BDBDBD", "#2E2E2E", "#6B6B6B", "#A3A3A3", "#D4D4D4"];
  const title = user.role === "sales" ? "My Dashboard" : user.role === "operations" ? "Operations Dashboard" : "Master Dashboard";

  return (
    <div className="space-y-6" data-testid="dashboard-page">
      <div className="flex items-end justify-between flex-wrap gap-4">
        <div>
          <div className="label-caps">Overview</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">{title}</h1>
          <p className="text-sm text-muted-foreground mt-2">Welcome back, {user.name}. Drag KPI cards to reorder.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => setOrder(DEFAULT_ORDER)} data-testid="reset-kpi-order">
            <RotateCcw size={14} /> Reset layout
          </Button>
          {user.role !== "operations" && (
            <Button onClick={() => router.push("/leads/new")} className="h-10 px-5" style={{ background: "#0A0A0A" }} data-testid="dashboard-new-lead">+ New Lead</Button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {order.filter(k => kpiMap[k]).map((k, i) => (
          <Kpi key={k} {...kpiMap[k]} dragHandlers={{
            onDragStart: onDragStart(i),
            onDragOver: onDragOver(i),
            onDragEnd,
          }} />
        ))}
      </div>

      {supSummary && (user.role === "admin" || user.role === "operations") && (
        <Card className="p-6 rounded-lg" data-testid="supplier-summary">
          <div className="label-caps">Supplier operations</div>
          <h3 className="font-display text-xl font-bold mt-1 mb-4">Response tracker · live</h3>
          <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
            <Metric label="Sent today" value={supSummary.sent_today} tone="primary" />
            <Metric label="Awaiting" value={supSummary.awaiting_replies} tone="amber" />
            <Metric label="Quotes today" value={supSummary.quotes_received_today} tone="accent" />
            <Metric label="Overdue >24h" value={supSummary.overdue} tone="red" />
            <Metric label="Need f/up" value={supSummary.need_followup} tone="orange" />
            <Metric label="Avg reply" value={`${supSummary.avg_response_hours}h`} />
            <Metric label="No-email supp." value={supSummary.suppliers_no_email} />
          </div>
          {(supSummary.top_fastest?.length ?? 0) > 0 && (
            <div className="mt-4">
              <div className="label-caps mb-2">Fastest responders</div>
              <div className="flex flex-wrap gap-2">
                {supSummary.top_fastest?.map((f, i) => (
                  <span key={i} className="text-xs px-2.5 py-1 rounded-md bg-secondary font-medium">
                    <span className="font-bold">{f.name}</span> · {f.avg_hours}h · {f.replies} replies
                  </span>
                ))}
              </div>
            </div>
          )}
        </Card>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="p-6 rounded-lg lg:col-span-3" data-testid="followup-timer">
          <div className="flex items-center justify-between mb-3">
            <div>
              <div className="label-caps">Task timer</div>
              <h3 className="font-display text-xl font-bold mt-1">Follow-ups (next 72h)</h3>
            </div>
            <span className="label-caps">{followups.length} pending</span>
          </div>
          {followups.length === 0 && <div className="text-sm text-muted-foreground py-4">No follow-ups scheduled. Set follow-up date & time on any lead.</div>}
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            {followups.slice(0, 12).map((f) => {
              const digits = (f.phone || "").replace(/\D/g, "");
              const ten = digits.slice(-10);
              return (
                <Link key={f.lead_id} href={`/leads/${f.lead_id}`}
                      className={`p-3 rounded-md border transition-colors block ${f.overdue ? "border-red-300 bg-red-50" : "border-border hover:bg-secondary/40"}`}
                      data-testid={`followup-${f.lead_id}`}>
                  <div className="flex items-center justify-between">
                    <span className="font-mono font-bold text-sm">{f.lead_id}</span>
                    <FollowupCountdown iso={f.follow_up_at} />
                  </div>
                  <div className="font-medium text-sm mt-1 truncate">{f.customer_name}</div>
                  <div className="text-xs text-muted-foreground truncate">{f.destination} · {f.lead_status}</div>
                  {ten.length === 10 && (
                    <div className="flex gap-1 mt-2">
                      {/* Not <a>: this card is already a link, and nested anchors are invalid HTML. */}
                      <span
                        role="button" tabIndex={0} aria-label="Call"
                        onClick={(e) => { e.preventDefault(); e.stopPropagation(); window.location.href = `tel:+91${ten}`; }}
                        className="text-[#0A0A0A] p-1 hover:bg-[#0A0A0A]/10 rounded cursor-pointer"
                      ><Phone size={13} /></span>
                      <span
                        role="button" tabIndex={0} aria-label="WhatsApp"
                        onClick={(e) => { e.preventDefault(); e.stopPropagation(); window.open(`https://wa.me/91${ten}`, "_blank", "noopener,noreferrer"); }}
                        className="text-emerald-600 p-1 hover:bg-emerald-100 rounded cursor-pointer"
                      ><MessageCircle size={13} /></span>
                    </div>
                  )}
                </Link>
              );
            })}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="p-6 lg:col-span-2 rounded-lg" data-testid="chart-monthly">
          <div className="label-caps">Monthly performance</div>
          <h3 className="font-display text-xl font-bold mt-1">Leads · Revenue · Profit</h3>
          <div className="h-64 mt-4">
            <ResponsiveContainer>
              <LineChart data={stats.monthly || []}>
                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                <XAxis dataKey="month" fontSize={12} />
                <YAxis fontSize={12} />
                <Tooltip />
                <Legend />
                <Line type="monotone" dataKey="leads" stroke="#0A0A0A" strokeWidth={2} />
                <Line type="monotone" dataKey="revenue" stroke="#8A8A8A" strokeWidth={2} />
                <Line type="monotone" dataKey="profit" stroke="#8B5CF6" strokeWidth={2} />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card className="p-6 rounded-lg" data-testid="chart-status">
          <div className="label-caps">Pipeline</div>
          <h3 className="font-display text-xl font-bold mt-1">By status</h3>
          <div className="h-64 mt-4">
            <ResponsiveContainer>
              <PieChart>
                <Pie data={statusChart} dataKey="value" nameKey="name" innerRadius={45} outerRadius={80}>
                  {statusChart.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
                </Pie>
                <Tooltip />
              </PieChart>
            </ResponsiveContainer>
          </div>
          <div className="mt-3 space-y-1 max-h-24 overflow-y-auto">
            {statusChart.map((s, i) => (
              <div key={s.name} className="flex items-center justify-between text-xs">
                <span className="flex items-center gap-2">
                  <span className="w-2.5 h-2.5 rounded-full" style={{ background: COLORS[i % COLORS.length] }} />
                  {s.name}
                </span>
                <span className="font-semibold">{s.value}</span>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="p-6 rounded-lg" data-testid="chart-destinations">
          <div className="label-caps">Top destinations</div>
          <h3 className="font-display text-xl font-bold mt-1">Where they want to go</h3>
          <div className="h-56 mt-4">
            <ResponsiveContainer>
              <BarChart data={stats.top_destinations || []}>
                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                <XAxis dataKey="destination" fontSize={11} />
                <YAxis fontSize={11} />
                <Tooltip />
                <Bar dataKey="count" fill="#0A0A0A" radius={[6, 6, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        {user.role === "admin" && (
          <Card className="p-6 rounded-lg lg:col-span-2" data-testid="leaderboard">
            <div className="label-caps">Team</div>
            <h3 className="font-display text-xl font-bold mt-1">Sales leaderboard</h3>
            <div className="mt-4 divide-y divide-border">
              {leaderboard.length === 0 && <div className="text-sm text-muted-foreground py-4">No leads yet.</div>}
              {leaderboard.map((r, i) => (
                <div key={r.staff_id} className="flex items-center justify-between py-3">
                  <div className="flex items-center gap-3">
                    <div className={`w-8 h-8 rounded-md flex items-center justify-center font-black text-sm ${i === 0 ? "bg-[#E5E5E5] text-[#0A0A0A]" : "bg-secondary"}`}>
                      {i + 1}
                    </div>
                    <div>
                      <div className="font-semibold text-sm">{r.staff_name || "Unknown"}</div>
                      <div className="label-caps">{r.leads} leads · {r.booked} booked · {r.conversion_rate}%</div>
                    </div>
                  </div>
                  <div className="text-right">
                    <div className="font-display font-black text-lg tracking-tight">{INR(r.revenue)}</div>
                    <div className="label-caps">revenue</div>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        )}

        {user.role !== "admin" && (
          <Card className="p-6 rounded-lg lg:col-span-2 flex flex-col justify-between" data-testid="quick-actions">
            <div>
              <div className="label-caps">Quick actions</div>
              <h3 className="font-display text-xl font-bold mt-1">What next?</h3>
            </div>
            <div className="mt-6 flex flex-wrap gap-3">
              {user.role !== "operations" && (
                <Link href="/leads/new"><Button className="h-11" style={{ background: "#0A0A0A" }} data-testid="quick-new-lead">+ Create Lead</Button></Link>
              )}
              <Link href="/leads"><Button variant="outline" className="h-11" data-testid="quick-view-leads">View Leads</Button></Link>
              <Link href="/calendar"><Button variant="outline" className="h-11">Calendar</Button></Link>
              <Link href="/ai"><Button variant="outline" className="h-11" data-testid="quick-ai">AI Assistant</Button></Link>
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}
