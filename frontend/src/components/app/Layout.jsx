import { NavLink, useNavigate } from "react-router-dom";
import { useAuth } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import {
  LayoutDashboard, Users, ClipboardList, PlusCircle, Truck, Database,
  Activity, LogOut, Sparkles, Mail, Calendar
} from "lucide-react";
import { cn } from "@/lib/utils";

const NAV = {
  admin: [
    { to: "/", label: "Dashboard", icon: LayoutDashboard, testId: "nav-dashboard" },
    { to: "/leads", label: "All Leads", icon: ClipboardList, testId: "nav-leads" },
    { to: "/leads/new", label: "New Lead", icon: PlusCircle, testId: "nav-new-lead" },
    { to: "/calendar", label: "Calendar", icon: Calendar, testId: "nav-calendar" },
    { to: "/suppliers", label: "Suppliers", icon: Truck, testId: "nav-suppliers" },
    { to: "/users", label: "Users", icon: Users, testId: "nav-users" },
    { to: "/master", label: "Master Data", icon: Database, testId: "nav-master" },
    { to: "/activity", label: "Activity Log", icon: Activity, testId: "nav-activity" },
    { to: "/ai", label: "AI Assistant", icon: Sparkles, testId: "nav-ai" },
  ],
  operations: [
    { to: "/", label: "Dashboard", icon: LayoutDashboard, testId: "nav-dashboard" },
    { to: "/leads", label: "All Leads", icon: ClipboardList, testId: "nav-leads" },
    { to: "/calendar", label: "Calendar", icon: Calendar, testId: "nav-calendar" },
    { to: "/suppliers", label: "Suppliers", icon: Truck, testId: "nav-suppliers" },
    { to: "/ai", label: "AI Assistant", icon: Sparkles, testId: "nav-ai" },
  ],
  sales: [
    { to: "/", label: "Dashboard", icon: LayoutDashboard, testId: "nav-dashboard" },
    { to: "/leads", label: "My Leads", icon: ClipboardList, testId: "nav-leads" },
    { to: "/leads/new", label: "New Lead", icon: PlusCircle, testId: "nav-new-lead" },
    { to: "/calendar", label: "Calendar", icon: Calendar, testId: "nav-calendar" },
    { to: "/ai", label: "AI Assistant", icon: Sparkles, testId: "nav-ai" },
  ],
};

export default function Layout({ children }) {
  const { user, logout } = useAuth();
  const nav = useNavigate();
  const items = NAV[user.role] || [];

  return (
    <div className="min-h-screen bg-background flex">
      {/* Sidebar */}
      <aside className="hidden md:flex md:w-64 flex-col border-r border-border bg-card" data-testid="sidebar">
        <div className="p-6 border-b border-border">
          <div className="flex items-center gap-2">
            <div className="w-9 h-9 rounded-md flex items-center justify-center" style={{ background: "#106EBE" }}>
              <Mail className="text-white" size={18} />
            </div>
            <div>
              <div className="font-display text-lg font-black tracking-tight text-foreground leading-none">INDIAN ROUNDS</div>
              <div className="label-caps mt-1">Travel CRM</div>
            </div>
          </div>
        </div>
        <nav className="flex-1 p-3 space-y-1">
          {items.map((it) => (
            <NavLink
              key={it.to}
              to={it.to}
              end={it.to === "/"}
              data-testid={it.testId}
              className={({ isActive }) =>
                cn(
                  "flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors",
                  isActive
                    ? "bg-[#106EBE] text-white"
                    : "text-foreground/80 hover:bg-secondary hover:text-foreground"
                )
              }
            >
              <it.icon size={18} />
              {it.label}
            </NavLink>
          ))}
        </nav>
        <div className="p-3 border-t border-border">
          <div className="px-3 pb-2">
            <div className="text-sm font-semibold truncate">{user.name || user.email}</div>
            <div className="label-caps mt-0.5">{user.role}</div>
          </div>
          <Button
            variant="outline"
            className="w-full justify-start"
            onClick={async () => { await logout(); nav("/login"); }}
            data-testid="logout-btn"
          >
            <LogOut size={16} />
            Logout
          </Button>
        </div>
      </aside>

      {/* Mobile top bar */}
      <div className="md:hidden fixed top-0 inset-x-0 z-40 bg-card border-b border-border px-4 py-3 flex items-center justify-between">
        <div className="font-display font-black tracking-tight">INDIAN ROUNDS</div>
        <Button size="sm" variant="outline" onClick={async () => { await logout(); nav("/login"); }} data-testid="logout-btn-mobile">
          <LogOut size={14} />
        </Button>
      </div>

      <main className="flex-1 min-w-0 pt-14 md:pt-0">
        {/* Mobile nav pills */}
        <div className="md:hidden overflow-x-auto whitespace-nowrap px-4 py-3 border-b border-border bg-card">
          {items.map((it) => (
            <NavLink
              key={it.to}
              to={it.to}
              end={it.to === "/"}
              className={({ isActive }) =>
                cn(
                  "inline-block px-3 py-1.5 mr-2 rounded-full text-xs font-semibold border",
                  isActive ? "bg-[#106EBE] text-white border-[#106EBE]" : "border-border text-foreground/70"
                )
              }
            >
              {it.label}
            </NavLink>
          ))}
        </div>

        <div className="p-4 md:p-8 max-w-[1400px] mx-auto">{children}</div>
      </main>
    </div>
  );
}
