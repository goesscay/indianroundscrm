"use client";

/* eslint-disable @next/next/no-img-element */
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  LayoutDashboard, Users, ClipboardList, PlusCircle, Truck, Database,
  Activity, LogOut, Sparkles, Calendar, Bell, Inbox, Shield
} from "lucide-react";
import { cn } from "@/lib/utils";
import type { Notification, Role } from "@/types";

interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  testId: string;
}

const NAV: Record<Role, NavItem[]> = {
  admin: [
    { to: "/", label: "Dashboard", icon: LayoutDashboard, testId: "nav-dashboard" },
    { to: "/leads", label: "All Leads", icon: ClipboardList, testId: "nav-leads" },
    { to: "/leads/new", label: "New Lead", icon: PlusCircle, testId: "nav-new-lead" },
    { to: "/calendar", label: "Calendar", icon: Calendar, testId: "nav-calendar" },
    { to: "/supplier-dashboard", label: "Supplier Dashboard", icon: Inbox, testId: "nav-supplier-dashboard" },
    { to: "/suppliers", label: "Suppliers", icon: Truck, testId: "nav-suppliers" },
    { to: "/users", label: "Users", icon: Users, testId: "nav-users" },
    { to: "/master", label: "Master Data", icon: Database, testId: "nav-master" },
    { to: "/activity", label: "Activity Log", icon: Activity, testId: "nav-activity" },
    { to: "/security-log", label: "Security Log", icon: Shield, testId: "nav-security-log" },
    { to: "/ai", label: "AI Assistant", icon: Sparkles, testId: "nav-ai" },
  ],
  operations: [
    { to: "/", label: "Dashboard", icon: LayoutDashboard, testId: "nav-dashboard" },
    { to: "/leads", label: "All Leads", icon: ClipboardList, testId: "nav-leads" },
    { to: "/calendar", label: "Calendar", icon: Calendar, testId: "nav-calendar" },
    { to: "/supplier-dashboard", label: "Supplier Dashboard", icon: Inbox, testId: "nav-supplier-dashboard" },
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

export default function Layout({ children }: { children: ReactNode }) {
  const { user, logout } = useCurrentUser();
  const router = useRouter();
  const pathname = usePathname();
  const items = NAV[user.role] || [];
  const [alerts, setAlerts] = useState<Notification[]>([]);

  // react-router's NavLink: exact match for "/", prefix match for everything else.
  const isActive = (to: string) =>
    to === "/" ? pathname === "/" : pathname === to || pathname.startsWith(`${to}/`);

  useEffect(() => {
    let mounted = true;
    const fetchAlerts = async () => {
      try {
        const { data } = await api.get<Notification[]>("/notifications");
        if (mounted) setAlerts(data);
      } catch {
        /* ignore */
      }
    };
    void fetchAlerts();
    const t = setInterval(fetchAlerts, 30000);
    return () => {
      mounted = false;
      clearInterval(t);
    };
  }, []);

  const doLogout = async () => {
    await logout();
    router.push("/login");
  };

  return (
    <div className="min-h-screen bg-background flex">
      {/* Sidebar */}
      <aside className="hidden md:flex md:w-64 flex-col border-r border-border bg-card" data-testid="sidebar">
        <div className="p-6 border-b border-border">
          <div className="flex items-center gap-3">
            <div className="w-12 h-12 rounded-md flex items-center justify-center overflow-hidden bg-white border border-border shadow-sm">
              <img src="/logo.png" alt="IR" className="w-full h-full object-contain p-1" />
            </div>
            <div>
              <div className="font-display text-lg font-black tracking-tight text-foreground leading-none">INDIAN ROUNDS</div>
              <div className="label-caps mt-1">Travel CRM</div>
            </div>
          </div>
        </div>
        <nav className="flex-1 p-3 space-y-1">
          {items.map((it) => (
            <Link
              key={it.to}
              href={it.to}
              data-testid={it.testId}
              className={cn(
                "flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors",
                isActive(it.to)
                  ? "bg-[#106EBE] text-white"
                  : "text-foreground/80 hover:bg-secondary hover:text-foreground"
              )}
            >
              <it.icon size={18} />
              {it.label}
            </Link>
          ))}
        </nav>
        <div className="p-3 border-t border-border">
          <div className="px-3 pb-2 flex items-center justify-between">
            <Link href="/profile" className="min-w-0 hover:opacity-80" data-testid="profile-link">
              <div className="text-sm font-semibold truncate">{user.name || user.email}</div>
              <div className="label-caps mt-0.5">{user.role}</div>
            </Link>
            <Popover>
              <PopoverTrigger asChild>
                <button className="relative p-1.5 rounded-md hover:bg-secondary" data-testid="bell-btn">
                  <Bell size={18} />
                  {alerts.length > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[9px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-1">
                      {alerts.length}
                    </span>
                  )}
                </button>
              </PopoverTrigger>
              <PopoverContent className="w-80 max-h-96 overflow-y-auto p-0" side="top" data-testid="bell-popover">
                <div className="p-3 border-b border-border">
                  <div className="font-display font-bold">Notifications</div>
                  <div className="text-xs text-muted-foreground">Auto-refreshes every 30s</div>
                </div>
                {alerts.length === 0 && <div className="p-4 text-sm text-muted-foreground text-center">All caught up! 🎉</div>}
                <div className="divide-y divide-border">
                  {alerts.map((a, i) => (
                    <button
                      key={i}
                      onClick={() => a.lead_id && router.push(`/leads/${a.lead_id}`)}
                      className="w-full text-left p-3 hover:bg-secondary/60 transition-colors"
                      data-testid={`alert-${i}`}
                    >
                      <div className="flex items-start gap-2">
                        <span className="text-lg">{a.icon}</span>
                        <div className="min-w-0 flex-1">
                          <div className="text-sm font-semibold truncate">{a.title}</div>
                          <div className="text-xs text-muted-foreground truncate">{a.detail}</div>
                        </div>
                      </div>
                    </button>
                  ))}
                </div>
              </PopoverContent>
            </Popover>
          </div>
          <Button variant="outline" className="w-full justify-start" onClick={doLogout} data-testid="logout-btn">
            <LogOut size={16} />
            Logout
          </Button>
        </div>
      </aside>

      {/* Mobile top bar */}
      <div className="md:hidden fixed top-0 inset-x-0 z-40 bg-card border-b border-border px-4 py-3 flex items-center justify-between">
        <div className="font-display font-black tracking-tight">INDIAN ROUNDS</div>
        <Button size="sm" variant="outline" onClick={doLogout} data-testid="logout-btn-mobile">
          <LogOut size={14} />
        </Button>
      </div>

      <main className="flex-1 min-w-0 pt-14 md:pt-0">
        {/* Mobile nav pills */}
        <div className="md:hidden overflow-x-auto whitespace-nowrap px-4 py-3 border-b border-border bg-card">
          {items.map((it) => (
            <Link
              key={it.to}
              href={it.to}
              className={cn(
                "inline-block px-3 py-1.5 mr-2 rounded-full text-xs font-semibold border",
                isActive(it.to) ? "bg-[#106EBE] text-white border-[#106EBE]" : "border-border text-foreground/70"
              )}
            >
              {it.label}
            </Link>
          ))}
        </div>

        <div className="p-4 md:p-8 max-w-[1400px] mx-auto">{children}</div>
      </main>
    </div>
  );
}
