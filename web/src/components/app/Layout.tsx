"use client";

/* eslint-disable @next/next/no-img-element */
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import { api } from "@/lib/api";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  LayoutDashboard, Users, ClipboardList, PlusCircle, Truck, Database,
  Activity, LogOut, Sparkles, Calendar, Bell, Inbox, Shield, Menu, X
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

  const [open, setOpen] = useState(false);
  useEffect(() => setOpen(false), [pathname]);

  const initials = (user.name || user.email || "?").split(/[\s@]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

  const navList = (
    <nav className="flex-1 overflow-y-auto sidebar-scroll px-2.5 py-3 space-y-0.5">
      {items.map((it) => {
        const active = isActive(it.to);
        return (
          <Link
            key={it.to}
            href={it.to}
            data-testid={it.testId}
            className={cn(
              "group relative flex items-center gap-2.5 px-3 py-2 rounded-md text-[13px] font-medium transition-all",
              active
                ? "bg-white text-black shadow-sm"
                : "text-white/60 hover:bg-white/10 hover:text-white"
            )}
          >
            <it.icon size={16} className={active ? "text-black" : "text-white/50 group-hover:text-white"} />
            {it.label}
          </Link>
        );
      })}
    </nav>
  );

  const userBlock = (
    <div className="p-2.5 border-t border-sidebar-border">
      <div className="flex items-center gap-2.5 px-2 py-2">
        <Link href="/profile" className="flex items-center gap-2.5 min-w-0 flex-1 hover:opacity-80" data-testid="profile-link">
          <div className="w-8 h-8 shrink-0 rounded-full bg-white text-black flex items-center justify-center text-[11px] font-black">{initials}</div>
          <div className="min-w-0">
            <div className="text-[13px] font-semibold text-white truncate leading-tight">{user.name || user.email}</div>
            <div className="text-[10px] uppercase tracking-widest text-white/40 mt-0.5">{user.role}</div>
          </div>
        </Link>
        <Popover>
          <PopoverTrigger asChild>
            <button className="relative p-1.5 rounded-md text-white/70 hover:text-white hover:bg-white/10" data-testid="bell-btn">
              <Bell size={16} />
              {alerts.length > 0 && (
                <span className="absolute -top-0.5 -right-0.5 bg-white text-black text-[9px] font-black rounded-full min-w-[15px] h-[15px] flex items-center justify-center px-1">
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
            {alerts.length === 0 && <div className="p-4 text-sm text-muted-foreground text-center">All caught up!</div>}
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
      <button
        onClick={doLogout}
        className="w-full flex items-center gap-2.5 px-3 py-2 rounded-md text-[13px] font-medium text-white/60 hover:text-white hover:bg-white/10 transition-colors"
        data-testid="logout-btn"
      >
        <LogOut size={16} />
        Logout
      </button>
    </div>
  );

  const brand = (
    <div className="px-4 pt-5 pb-4 border-b border-sidebar-border">
      <img src="/logo-dark.png" alt="Indian Rounds" className="w-full max-w-[148px] h-auto" />
      <div className="mt-2 text-[10px] uppercase tracking-[0.28em] text-white/40">Travel CRM</div>
    </div>
  );

  return (
    <div className="min-h-screen bg-background flex">
      {/* Sidebar (desktop) */}
      <aside className="hidden md:flex md:w-56 lg:w-60 shrink-0 flex-col bg-sidebar text-sidebar-foreground sticky top-0 h-screen" data-testid="sidebar">
        {brand}
        {navList}
        {userBlock}
      </aside>

      {/* Mobile top bar */}
      <div className="md:hidden fixed top-0 inset-x-0 z-40 bg-sidebar text-white h-12 px-3 flex items-center justify-between">
        <button onClick={() => setOpen(true)} className="p-1.5 -ml-1 rounded-md hover:bg-white/10" aria-label="Open menu" data-testid="mobile-menu-btn">
          <Menu size={20} />
        </button>
        <img src="/logo-dark.png" alt="Indian Rounds" className="h-7 w-auto" />
        <button onClick={doLogout} className="p-1.5 -mr-1 rounded-md hover:bg-white/10" aria-label="Logout" data-testid="logout-btn-mobile">
          <LogOut size={18} />
        </button>
      </div>

      {/* Mobile drawer */}
      {open && (
        <div className="md:hidden fixed inset-0 z-50 flex">
          <div className="absolute inset-0 bg-black/60" onClick={() => setOpen(false)} />
          <aside className="relative w-64 max-w-[80%] h-full flex flex-col bg-sidebar text-sidebar-foreground shadow-2xl">
            <button onClick={() => setOpen(false)} className="absolute top-3 right-3 p-1.5 rounded-md text-white/70 hover:bg-white/10" aria-label="Close menu">
              <X size={18} />
            </button>
            {brand}
            {navList}
            {userBlock}
          </aside>
        </div>
      )}

      <main className="flex-1 min-w-0 pt-12 md:pt-0">
        <div className="p-3 sm:p-4 md:p-6 max-w-[1440px] mx-auto">{children}</div>
      </main>
    </div>
  );
}
