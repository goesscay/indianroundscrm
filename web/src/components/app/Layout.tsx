"use client";

/* eslint-disable @next/next/no-img-element */
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import { api } from "@/lib/api";
import { useChatUnread } from "@/context/ChatUnreadContext";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  LayoutDashboard, Users, ClipboardList, Truck, Database, Activity, LogOut, Sparkles,
  Calendar, Bell, Inbox, Shield, Menu, X, PanelLeftClose, PanelLeftOpen, ChevronDown, User as UserIcon, MessageSquare, ShieldCheck,
} from "lucide-react";
import { cn } from "@/lib/utils";
import type { Notification } from "@/types";

interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  testId: string;
  group: string;
  /** Shown only when the user holds ANY of these permissions. */
  perms: string[];
}

// Order matters: items are grouped by consecutive `group`.
const NAV: NavItem[] = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard, testId: "nav-dashboard", group: "Workspace", perms: ["dashboard.view"] },
  { to: "/leads", label: "Leads", icon: ClipboardList, testId: "nav-leads", group: "Sales", perms: ["leads.view_all", "leads.create", "leads.edit", "leads.edit_limited"] },
  { to: "/calendar", label: "Calendar", icon: Calendar, testId: "nav-calendar", group: "Sales", perms: ["calendar.view"] },
  { to: "/chat", label: "Chat", icon: MessageSquare, testId: "nav-chat", group: "Team", perms: ["chat.use"] },
  { to: "/supplier-dashboard", label: "Supplier Dashboard", icon: Inbox, testId: "nav-supplier-dashboard", group: "Operations", perms: ["supplier_dashboard.view"] },
  { to: "/suppliers", label: "Suppliers", icon: Truck, testId: "nav-suppliers", group: "Operations", perms: ["suppliers.view"] },
  { to: "/ai", label: "AI Assistant", icon: Sparkles, testId: "nav-ai", group: "Tools", perms: ["ai.use"] },
  { to: "/users", label: "Users", icon: Users, testId: "nav-users", group: "Administration", perms: ["users.view"] },
  { to: "/roles", label: "Roles & Permissions", icon: ShieldCheck, testId: "nav-roles", group: "Administration", perms: ["roles.manage"] },
  { to: "/master", label: "Master Data", icon: Database, testId: "nav-master", group: "Administration", perms: ["master.view"] },
  { to: "/activity", label: "Activity Log", icon: Activity, testId: "nav-activity", group: "Administration", perms: ["activity.view"] },
  { to: "/security-log", label: "Security Log", icon: Shield, testId: "nav-security-log", group: "Administration", perms: ["security.view"] },
];

const COLLAPSE_KEY = "ir-sidebar-collapsed";

export default function Layout({ children }: { children: ReactNode }) {
  const { user, logout, can } = useCurrentUser();
  const router = useRouter();
  const pathname = usePathname();
  const items = NAV.filter((it) => can(...it.perms));
  const { total: chatUnread } = useChatUnread();
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
                  <div className="px-3 pt-4 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-neutral-400">
                    {it.group}
                  </div>
                ))}
              <Link
                href={it.to}
                title={rail ? it.label : undefined}
                data-testid={it.testId}
                className={cn(
                  "group relative flex items-center rounded text-[13px] font-medium transition-colors",
                  rail ? "justify-center h-9 w-9 mx-auto my-0.5" : "gap-2.5 px-3 py-2",
                  active ? "bg-neutral-900 text-white shadow-sm" : "text-neutral-600 hover:bg-neutral-100 hover:text-neutral-950"
                )}
              >
                <it.icon size={16} className={active ? "text-white" : "text-neutral-400 group-hover:text-neutral-900"} />
                {!rail && <span className="flex-1">{it.label}</span>}
                {it.testId === "nav-chat" && chatUnread > 0 &&
                  (rail ? (
                    <span data-testid="chat-unread-dot" className="absolute top-0.5 right-0.5 min-w-[14px] h-[14px] px-0.5 rounded-sm bg-neutral-900 text-white text-[9px] font-bold flex items-center justify-center ring-2 ring-white">
                      {chatUnread > 9 ? "9+" : chatUnread}
                    </span>
                  ) : (
                    <span data-testid="chat-unread-badge" className={cn("min-w-[18px] h-[18px] px-1 rounded-sm text-[10px] font-bold flex items-center justify-center", active ? "bg-white text-neutral-900" : "bg-neutral-900 text-white")}>
                      {chatUnread > 99 ? "99+" : chatUnread}
                    </span>
                  ))}
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
        <button className="relative p-1.5 rounded text-neutral-500 hover:text-neutral-950 hover:bg-neutral-100" data-testid="bell-btn" title="Notifications">
          <Bell size={16} />
          {alerts.length > 0 && (
            <span className="absolute -top-0.5 -right-0.5 bg-neutral-900 text-white text-[9px] font-bold rounded-sm min-w-[15px] h-[15px] flex items-center justify-center px-1">
              {alerts.length}
            </span>
          )}
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-80 max-h-96 overflow-y-auto p-0" side="bottom" align="end" sideOffset={8} data-testid="bell-popover">
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

  const [menuOpenD, setMenuOpenD] = useState(false); // desktop header
  const [menuOpenM, setMenuOpenM] = useState(false); // mobile top bar
  const pageTitle =
    [...items].filter((it) => isActive(it.to)).sort((x, y) => y.to.length - x.to.length)[0]?.label ??
    (pathname.startsWith("/profile") ? "Profile" : "");

  const renderUserMenu = (menuOpen: boolean, setMenuOpen: (v: boolean) => void) => (
    <Popover open={menuOpen} onOpenChange={setMenuOpen}>
      <PopoverTrigger asChild>
        <button
          className="flex items-center gap-2 pl-1.5 pr-2 py-1 rounded hover:bg-neutral-100 transition-colors"
          data-testid="user-menu-btn" aria-label="Account menu"
        >
          <span className="w-8 h-8 rounded-full bg-neutral-900 text-white flex items-center justify-center text-[11px] font-bold">{initials}</span>
          <span className="hidden lg:block text-left leading-tight">
            <span className="block text-[13px] font-semibold text-neutral-900 max-w-[140px] truncate">{user.name || user.email}</span>
            <span className="block text-[10px] uppercase tracking-widest text-neutral-400">{user.role_name || user.role}</span>
          </span>
          <ChevronDown size={14} className="hidden lg:block text-neutral-400" />
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={8} className="w-64 p-0" data-testid="user-menu">
        <div className="p-3 border-b border-border">
          <div className="flex items-center gap-3">
            <span className="w-10 h-10 shrink-0 rounded-full bg-neutral-900 text-white flex items-center justify-center text-sm font-bold">{initials}</span>
            <div className="min-w-0">
              <div className="text-sm font-semibold truncate">{user.name || user.email}</div>
              <div className="text-xs text-muted-foreground truncate">{user.email}</div>
              <span className="inline-block mt-1 text-[10px] uppercase tracking-widest font-semibold px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600">{user.role_name || user.role}</span>
            </div>
          </div>
        </div>
        <div className="p-1.5">
          <Link href="/profile" onClick={() => setMenuOpen(false)} data-testid="profile-link"
                className="flex items-center gap-2.5 px-2.5 py-2 rounded text-[13px] font-medium text-neutral-700 hover:bg-neutral-100">
            <UserIcon size={15} /> My profile
          </Link>
          <button onClick={doLogout} data-testid="logout-btn"
                  className="w-full flex items-center gap-2.5 px-2.5 py-2 rounded text-[13px] font-medium text-neutral-700 hover:bg-neutral-100">
            <LogOut size={15} /> Logout
          </button>
        </div>
      </PopoverContent>
    </Popover>
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
            <img src="/logo-mark-dark.png" alt="Indian Rounds" className="w-7 h-auto" />
            <button onClick={toggleCollapsed} title="Expand sidebar" aria-label="Expand sidebar" data-testid="sidebar-toggle"
                    className="p-1.5 rounded text-neutral-500 hover:text-neutral-950 hover:bg-neutral-100">
              <PanelLeftOpen size={16} />
            </button>
          </div>
        ) : (
          <div className="flex items-center justify-between gap-2 pl-4 pr-2 h-14 border-b border-sidebar-border">
            <img src="/logo-word-dark.png" alt="Indian Rounds" className="h-8 w-auto max-w-[130px] object-contain object-left" />
            <button onClick={toggleCollapsed} title="Collapse sidebar" aria-label="Collapse sidebar" data-testid="sidebar-toggle"
                    className="p-1.5 rounded text-neutral-500 hover:text-neutral-950 hover:bg-neutral-100">
              <PanelLeftClose size={16} />
            </button>
          </div>
        )}
        {renderNav(collapsed)}
      </aside>

      {/* Mobile top bar */}
      <div className="md:hidden fixed top-0 inset-x-0 z-40 bg-sidebar text-neutral-900 border-b border-sidebar-border h-12 px-3 flex items-center justify-between">
        <button onClick={() => setOpen(true)} className="p-1.5 -ml-1 rounded hover:bg-neutral-100" aria-label="Open menu" data-testid="mobile-menu-btn">
          <Menu size={20} />
        </button>
        <img src="/logo-word-dark.png" alt="Indian Rounds" className="h-6 w-auto" />
        {can("chat.use") && <Link href="/chat" aria-label={`Chat${chatUnread > 0 ? `, ${chatUnread} unread` : ""}`} data-testid="chat-link-mobile" className="relative p-1.5 rounded hover:bg-neutral-100 ml-auto mr-1">
          <MessageSquare size={18} />
          {chatUnread > 0 && (
            <span className="absolute -top-0.5 -right-0.5 bg-neutral-900 text-white text-[9px] font-bold rounded-sm min-w-[15px] h-[15px] flex items-center justify-center px-1">
              {chatUnread > 99 ? "99+" : chatUnread}
            </span>
          )}
        </Link>}
        {bell}
        {renderUserMenu(menuOpenM, setMenuOpenM)}
      </div>

      {/* Mobile drawer */}
      {open && (
        <div className="md:hidden fixed inset-0 z-50 flex">
          <div className="absolute inset-0 bg-black/40" onClick={() => setOpen(false)} />
          <aside className="relative w-64 max-w-[80%] h-full flex flex-col bg-sidebar text-sidebar-foreground shadow-2xl border-r border-sidebar-border">
            <div className="flex items-center justify-between pl-4 pr-2 h-14 border-b border-sidebar-border">
              <img src="/logo-word-dark.png" alt="Indian Rounds" className="h-8 w-auto max-w-[130px] object-contain object-left" />
              <button onClick={() => setOpen(false)} className="p-1.5 rounded text-neutral-500 hover:bg-neutral-100" aria-label="Close menu">
                <X size={18} />
              </button>
            </div>
            {renderNav(false)}
          </aside>
        </div>
      )}

      <div className="flex-1 min-w-0 flex flex-col">
        {/* Desktop header */}
        <header className="hidden md:flex sticky top-0 z-30 h-14 items-center justify-between gap-4 px-6 bg-white border-b border-border" data-testid="app-header">
          <h2 className="font-display text-[15px] tracking-tight text-neutral-900 truncate">{pageTitle}</h2>
          <div className="flex items-center gap-1.5">
            {bell}
            <span className="w-px h-6 bg-border mx-1" />
            {renderUserMenu(menuOpenD, setMenuOpenD)}
          </div>
        </header>
        <main className="flex-1 min-w-0 pt-12 md:pt-0">
          <div className="p-3 sm:p-4 md:p-6 max-w-[1440px] mx-auto">{children}</div>
        </main>
      </div>
    </div>
  );
}
