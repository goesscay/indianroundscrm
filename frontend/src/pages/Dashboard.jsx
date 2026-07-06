import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { useAuth } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import {
  ResponsiveContainer, BarChart, Bar, XAxis, YAxis, Tooltip, CartesianGrid,
  PieChart, Pie, Cell, LineChart, Line, Legend
} from "recharts";
import { TrendingUp, Users2, IndianRupee, CalendarClock, Clock, CheckCircle2, XCircle, Layers } from "lucide-react";
import { useNavigate, Link } from "react-router-dom";

const INR = (n) => "₹" + (Number(n) || 0).toLocaleString("en-IN", { maximumFractionDigits: 0 });

function Kpi({ label, value, icon: Icon, tone = "default", testId }) {
  const tones = {
    default: "bg-card",
    primary: "bg-[#106EBE] text-white",
    accent: "bg-[#0FFCBE] text-[#024a37]",
  };
  const isDark = tone !== "default";
  return (
    <Card className={`p-6 rounded-lg border border-border ${tones[tone]} transition-all hover:-translate-y-0.5 hover:shadow-sm`} data-testid={testId}>
      <div className="flex items-start justify-between">
        <div>
          <div className={`label-caps ${isDark ? "!text-white/70" : ""}`}>{label}</div>
          <div className={`kpi-value mt-2 ${isDark ? "" : "text-foreground"}`}>{value}</div>
        </div>
        <Icon size={22} className={isDark ? "opacity-70" : "text-muted-foreground"} />
      </div>
    </Card>
  );
}

export default function Dashboard() {
  const { user } = useAuth();
  const [stats, setStats] = useState(null);
  const [leaderboard, setLeaderboard] = useState([]);
  const [err, setErr] = useState("");
  const nav = useNavigate();

  useEffect(() => {
    (async () => {
      try {
        const { data } = await api.get("/dashboard/stats");
        setStats(data);
        if (user.role === "admin") {
          const lb = await api.get("/dashboard/leaderboard");
          setLeaderboard(lb.data);
        }
      } catch (e) { setErr(formatApiError(e)); }
    })();
  }, [user.role]);

  if (!stats) {
    return <div className="text-muted-foreground text-sm">Loading dashboard…{err && ` · ${err}`}</div>;
  }

  const statusChart = Object.entries(stats.status_counts || {}).map(([k, v]) => ({ name: k, value: v }));
  const COLORS = ["#106EBE", "#0FFCBE", "#8B5CF6", "#F59E0B", "#EC4899", "#10B981", "#EF4444", "#3B82F6"];

  const title = user.role === "sales" ? "My Dashboard" : user.role === "operations" ? "Operations Dashboard" : "Master Dashboard";

  return (
    <div className="space-y-6" data-testid="dashboard-page">
      <div className="flex items-end justify-between flex-wrap gap-4">
        <div>
          <div className="label-caps">Overview</div>
          <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">{title}</h1>
          <p className="text-sm text-muted-foreground mt-2">Welcome back, {user.name}.</p>
        </div>
        {user.role !== "operations" && (
          <Button onClick={() => nav("/leads/new")} className="h-11 px-5" style={{ background: "#106EBE" }} data-testid="dashboard-new-lead">
            + New Lead
          </Button>
        )}
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <Kpi label="Total Leads" value={stats.total_leads} icon={Layers} testId="kpi-total-leads" />
        <Kpi label="Today's Leads" value={stats.today_leads} icon={CalendarClock} tone="primary" testId="kpi-today" />
        <Kpi label="Booked" value={stats.booked} icon={CheckCircle2} tone="accent" testId="kpi-booked" />
        <Kpi label="Quote Pending" value={stats.quote_pending} icon={Clock} testId="kpi-quote-pending" />
        <Kpi label="Revenue" value={INR(stats.revenue)} icon={IndianRupee} testId="kpi-revenue" />
        <Kpi label="Profit" value={INR(stats.profit)} icon={TrendingUp} testId="kpi-profit" />
        <Kpi label="Follow-ups Today" value={stats.followups_today} icon={Users2} testId="kpi-followups" />
        <Kpi label="Cancelled" value={stats.cancelled} icon={XCircle} testId="kpi-cancelled" />
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
                <Line type="monotone" dataKey="leads" stroke="#106EBE" strokeWidth={2} />
                <Line type="monotone" dataKey="revenue" stroke="#0FFCBE" strokeWidth={2} />
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
                <Bar dataKey="count" fill="#106EBE" radius={[6, 6, 0, 0]} />
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
                    <div className={`w-8 h-8 rounded-md flex items-center justify-center font-black text-sm ${i === 0 ? "bg-[#0FFCBE] text-[#024a37]" : "bg-secondary"}`}>
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
                <Link to="/leads/new"><Button className="h-11" style={{ background: "#106EBE" }} data-testid="quick-new-lead">+ Create Lead</Button></Link>
              )}
              <Link to="/leads"><Button variant="outline" className="h-11" data-testid="quick-view-leads">View Leads</Button></Link>
              <Link to="/ai"><Button variant="outline" className="h-11" data-testid="quick-ai">AI Assistant</Button></Link>
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}
