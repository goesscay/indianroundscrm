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
  LayoutDashboard, Users, ClipboardList, Truck, Database, Activity, LogOut, Sparkles,
  Calendar, Bell, Inbox, Shield, Menu, X, PanelLeftClose, PanelLeftOpen,
} from "lucide-react";
import { cn } from "@/lib/utils";
import type { Notification, Role } from "@/types";

interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  testId: string;
  group: string;
}

const DASH: NavItem = { to: "/", label: "Dashboard", icon: LayoutDashboard, testId: "nav-dashboard", group: "Workspace" };
const LEADS: NavItem = { to: "/leads", label: "Leads", icon: ClipboardList, testId: "nav-leads", group: "Sales" };
const CAL: NavItem = { to: "/calendar", label: "Calendar", icon: Calendar, testId: "nav-calendar", group: "Sales" };
const SUPD: NavItem = { to: "/supplier-dashboard", label: "Supplier Dashboard", icon: Inbox, testId: "nav-supplier-dashboard", group: "Operations" };
const SUPS: NavItem = { to: "/suppliers", label: "Suppliers", icon: Truck, testId: "nav-suppliers", group: "Operations" };
const AI: NavItem = { to: "/ai", label: "AI Assistant", icon: Sparkles, testId: "nav-ai", group: "Tools" };

const NAV: Record<Role, NavItem[]> = {
  admin: [
    DASH, LEADS, CAL, SUPD, SUPS,
    { to: "/users", label: "Users", icon: Users, testId: "nav-users", group: "Administration" },
    { to: "/master", label: "Master Data", icon: Database, testId: "nav-master", group: "Administration" },
    { to: "/activity", label: "Activity Log", icon: Activity, testId: "nav-activity", group: "Administration" },
    { to: "/security-log", label: "Security Log", icon: Shield, testId: "nav-security-log", group: "Administration" },
    AI,
  ],
  operations: [DASH, LEADS, CAL, SUPD, SUPS, AI],
  sales: [DASH, LEADS, CAL, AI],
};

const COLLAPSE_KEY = "ir-sidebar-collapsed";

export default function Layout({ children }: { children: ReactNode }) {
  const { user, logout } = useCurrentUser();
  const router = useRouter();
  const pathname = usePathname();
  const items = NAV[user.role] || [];
  const [alerts, setAlerts] = useState<Notification[]>([]);
  const [open, setOpen] = useState(false); // mobile drawer
  const [collapsed, setCollapsed] = useState(false); // desktop rail

  // Exact match for "/", prefix match for everything else (so /leads/new keeps "Leads" lit).
  const isActive = (to: string) =>
    to === "/" ? pathname === "/" : pathname === to || pathname.startsWith(`${to}/`);

  useEffect(() => {
    try {
      setCollapsed(localStorage.getItem(COLLAPSE_KEY) === "1");
    } catch {
      /* storage unavailable */
    }
  }, []);

  useEffect(() => setOpen(false), [pathname]);

  const toggleCollapsed = () => {
    setCollapsed((c) => {
      try {
        localStorage.setItem(COLLAPSE_KEY, c ? "0" : "1");
      } catch {
        /* storage unavailable */
      }
      return !c;
    });
  };

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

  const initials = (user.name || user.email || "?")
    .split(/[\s@]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

  // `rail` = icon-only desktop mode. The mobile drawer always renders expanded.
  const renderNav = (rail: boolean) => {
    let lastGroup = "";
    return (
      <nav className="flex-1 overflow-y-auto sidebar-scroll px-2 py-2">
        {items.map((it) => {
          const active = isActive(it.to);
          const showGroup = it.group !== lastGroup;
          lastGroup = it.group;
          return (
            <div key={it.to}>
              {showGroup &&
                (rail ? (
                  <div className="mx-2 my-2 border-t border-sidebar-border first:hidden" />
                ) : (
                  <div className="px-3 pt-4 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-white/35">
                    {it.group}
                  </div>
                ))}
              <Link
                href={it.to}
                title={rail ? it.label : undefined}
                data-testid={it.testId}
                className={cn(
                  "group flex items-center rounded text-[13px] font-medium transition-colors",
                  rail ? "justify-center h-9 w-9 mx-auto my-0.5" : "gap-2.5 px-3 py-2",
                  active ? "bg-white text-black" : "text-white/60 hover:bg-white/10 hover:text-white"
                )}
              >
                <it.icon size={16} className={active ? "text-black" : "text-white/50 group-hover:text-white"} />
                {!rail && it.label}
              </Link>
            </div>
          );
        })}
      </nav>
    );
  };

  const bell = (
    <Popover>
      <PopoverTrigger asChild>
        <button className="relative p-1.5 rounded text-white/70 hover:text-white hover:bg-white/10" data-testid="bell-btn" title="Notifications">
          <Bell size={16} />
          {alerts.length > 0 && (
            <span className="absolute -top-0.5 -right-0.5 bg-white text-black text-[9px] font-bold rounded-sm min-w-[15px] h-[15px] flex items-center justify-center px-1">
              {alerts.length}
            </span>
          )}
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-80 max-h-96 overflow-y-auto p-0" side="right" align="end" data-testid="bell-popover">
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
  );

  const renderUser = (rail: boolean) => (
    <div className="p-2 border-t border-sidebar-border">
      {rail ? (
        <div className="flex flex-col items-center gap-1">
          <Link href="/profile" title={user.name || user.email} data-testid="profile-link"
                className="w-8 h-8 rounded bg-white text-black flex items-center justify-center text-[11px] font-bold hover:opacity-80">
            {initials}
          </Link>
          {bell}
          <button onClick={doLogout} title="Logout" className="p-1.5 rounded text-white/60 hover:text-white hover:bg-white/10" data-testid="logout-btn">
            <LogOut size={16} />
          </button>
        </div>
      ) : (
        <>
          <div className="flex items-center gap-2.5 px-2 py-2">
            <Link href="/profile" className="flex items-center gap-2.5 min-w-0 flex-1 hover:opacity-80" data-testid="profile-link">
              <div className="w-8 h-8 shrink-0 rounded bg-white text-black flex items-center justify-center text-[11px] font-bold">{initials}</div>
              <div className="min-w-0">
                <div className="text-[13px] font-semibold text-white truncate leading-tight">{user.name || user.email}</div>
                <div className="text-[10px] uppercase tracking-widest text-white/40 mt-0.5">{user.role}</div>
              </div>
            </Link>
            {bell}
          </div>
          <button
            onClick={doLogout}
            className="w-full flex items-center gap-2.5 px-3 py-2 rounded text-[13px] font-medium text-white/60 hover:text-white hover:bg-white/10 transition-colors"
            data-testid="logout-btn"
          >
            <LogOut size={16} />
            Logout
          </button>
        </>
      )}
    </div>
  );

  return (
    <div className="min-h-screen bg-background flex">
      {/* Sidebar (desktop): expanded = wordmark + toggle, collapsed = R mark + toggle */}
      <aside
        className={cn(
          "hidden md:flex shrink-0 flex-col bg-sidebar text-sidebar-foreground sticky top-0 h-screen transition-[width] duration-200",
          collapsed ? "w-14" : "w-56 lg:w-60"
        )}
        data-testid="sidebar"
      >
        {collapsed ? (
          <div className="flex flex-col items-center gap-2 pt-4 pb-3 border-b border-sidebar-border">
            <img src="/logo-mark.png" alt="Indian Rounds" className="w-7 h-auto" />
            <button onClick={toggleCollapsed} title="Expand sidebar" aria-label="Expand sidebar" data-testid="sidebar-toggle"
                    className="p-1.5 rounded text-white/60 hover:text-white hover:bg-white/10">
              <PanelLeftOpen size={16} />
            </button>
          </div>
        ) : (
          <div className="flex items-center justify-between gap-2 pl-4 pr-2 h-14 border-b border-sidebar-border">
            <img src="/logo-word.png" alt="Indian Rounds" className="h-8 w-auto max-w-[130px] object-contain object-left" />
            <button onClick={toggleCollapsed} title="Collapse sidebar" aria-label="Collapse sidebar" data-testid="sidebar-toggle"
                    className="p-1.5 rounded text-white/60 hover:text-white hover:bg-white/10">
              <PanelLeftClose size={16} />
            </button>
          </div>
        )}
        {renderNav(collapsed)}
        {renderUser(collapsed)}
      </aside>

      {/* Mobile top bar */}
      <div className="md:hidden fixed top-0 inset-x-0 z-40 bg-sidebar text-white h-12 px-3 flex items-center justify-between">
        <button onClick={() => setOpen(true)} className="p-1.5 -ml-1 rounded hover:bg-white/10" aria-label="Open menu" data-testid="mobile-menu-btn">
          <Menu size={20} />
        </button>
        <img src="/logo-word.png" alt="Indian Rounds" className="h-6 w-auto" />
        <button onClick={doLogout} className="p-1.5 -mr-1 rounded hover:bg-white/10" aria-label="Logout" data-testid="logout-btn-mobile">
          <LogOut size={18} />
        </button>
      </div>

      {/* Mobile drawer */}
      {open && (
        <div className="md:hidden fixed inset-0 z-50 flex">
          <div className="absolute inset-0 bg-black/60" onClick={() => setOpen(false)} />
          <aside className="relative w-64 max-w-[80%] h-full flex flex-col bg-sidebar text-sidebar-foreground shadow-2xl">
            <div className="flex items-center justify-between pl-4 pr-2 h-14 border-b border-sidebar-border">
              <img src="/logo-word.png" alt="Indian Rounds" className="h-8 w-auto max-w-[130px] object-contain object-left" />
              <button onClick={() => setOpen(false)} className="p-1.5 rounded text-white/70 hover:bg-white/10" aria-label="Close menu">
                <X size={18} />
              </button>
            </div>
            {renderNav(false)}
            {renderUser(false)}
          </aside>
        </div>
      )}

      <main className="flex-1 min-w-0 pt-12 md:pt-0">
        <div className="p-3 sm:p-4 md:p-6 max-w-[1440px] mx-auto">{children}</div>
      </main>
    </div>
  );
}
