"use client";

/* eslint-disable @next/next/no-img-element */
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import { api } from "@/lib/api";
import { useChatUnread } from "@/context/ChatUnreadContext";
import { useSupplierReplies } from "@/context/SupplierRepliesContext";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  LayoutDashboard, Users, ClipboardList, Truck, Database, Activity, LogOut, Sparkles,
  ListChecks, Plane, ClipboardCheck, Bell, Handshake, ContactRound, TrendingUp, Package, FileText, ReceiptText, Wallet, Landmark, Inbox, MailOpen, MailWarning, Shield, Menu, X, PanelLeftClose, PanelLeftOpen, ChevronDown, User as UserIcon, MessageSquare, ShieldCheck, BarChart3,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useAiPanel } from "@/components/ai/AiPanelContext";
import ThemeMenu from "@/components/app/ThemeMenu";
import type { Notification } from "@/types";

interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  testId: string;
  group: string;
  /** Shown only when the user holds ANY of these permissions. */
  perms: string[];
  /** Shown only to a Super Admin. */
  superOnly?: boolean;
}

// Order matters: items are grouped by consecutive `group`.
const NAV: NavItem[] = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard, testId: "nav-dashboard", group: "Workspace", perms: ["dashboard.view"] },
  { to: "/leads", label: "Leads", icon: ClipboardList, testId: "nav-leads", group: "Sales", perms: ["leads.view_all", "leads.create", "leads.edit", "leads.edit_limited"] },
  { to: "/customers", label: "Customers", icon: ContactRound, testId: "nav-customers", group: "Sales", perms: ["customers.view"] },
  { to: "/deals", label: "Deals", icon: Handshake, testId: "nav-deals", group: "Sales", perms: ["deals.view"] },
  { to: "/forecasts", label: "Forecasts", icon: TrendingUp, testId: "nav-forecasts", group: "Sales", perms: ["forecasts.view"] },
  { to: "/activities", label: "Activities", icon: ListChecks, testId: "nav-activities", group: "Sales", perms: ["activities.view"] },
  { to: "/packages", label: "Packages", icon: Package, testId: "nav-packages", group: "Sales", perms: ["packages.view", "packages.manage"] },
  { to: "/quotes", label: "Quotes", icon: FileText, testId: "nav-quotes", group: "Sales", perms: ["quotes.view"] },
  { to: "/invoices", label: "Invoices", icon: ReceiptText, testId: "nav-invoices", group: "Sales", perms: ["invoices.view"] },
  { to: "/receipts", label: "Receipts", icon: Wallet, testId: "nav-receipts", group: "Sales", perms: ["receipts.view"] },
  { to: "/trips", label: "Trips", icon: Plane, testId: "nav-trips", group: "Operations", perms: ["trips.view"] },
  { to: "/supplier-dashboard", label: "Supplier Dashboard", icon: Inbox, testId: "nav-supplier-dashboard", group: "Operations", perms: ["supplier_dashboard.view"] },
  { to: "/supplier-inbox", label: "Supplier Inbox", icon: MailOpen, testId: "nav-supplier-inbox", group: "Operations", perms: ["rsq.manage"] },
  { to: "/suppliers", label: "Suppliers", icon: Truck, testId: "nav-suppliers", group: "Operations", perms: ["suppliers.view"] },
  { to: "/supplier-bills", label: "Supplier Bills", icon: ReceiptText, testId: "nav-supplier-bills", group: "Operations", perms: ["finance.bills"] },
  { to: "/accounts", label: "Accounts", icon: Landmark, testId: "nav-accounts", group: "Finance", perms: ["finance.view"] },
  { to: "/team-performance", label: "Team Performance", icon: BarChart3, testId: "nav-team-performance", group: "Administration", perms: [], superOnly: true },
  { to: "/users", label: "Users", icon: Users, testId: "nav-users", group: "Administration", perms: ["users.view"] },
  { to: "/roles", label: "Roles & Permissions", icon: ShieldCheck, testId: "nav-roles", group: "Administration", perms: ["roles.manage"] },
  { to: "/trip-templates", label: "Trip Checklists", icon: ClipboardCheck, testId: "nav-trip-templates", group: "Administration", perms: ["trips.templates"] },
  { to: "/master", label: "Master Data", icon: Database, testId: "nav-master", group: "Administration", perms: ["master.view"] },
  { to: "/activity", label: "Activity Log", icon: Activity, testId: "nav-activity", group: "Administration", perms: ["activity.view"] },
  { to: "/security-log", label: "Security Log", icon: Shield, testId: "nav-security-log", group: "Administration", perms: ["security.view"] },
];

const COLLAPSE_KEY = "ir-sidebar-collapsed";
const GROUPS_KEY = "ir-nav-groups";

export default function Layout({ children }: { children: ReactNode }) {
  const { user, logout, can } = useCurrentUser();
  const router = useRouter();
  const pathname = usePathname();
  const items = NAV.filter((it) => (it.superOnly ? user?.role === "super_admin" : can(...it.perms)));
  const { total: chatUnread } = useChatUnread();
  const { counts: replyCounts, version: repliesVersion } = useSupplierReplies();
  const inboxUnread = replyCounts?.unread ?? 0;
  const inboxUnmatched = replyCounts?.unmatched ?? 0;
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

  // Collapsible module groups (expanded desktop sidebar and mobile drawer); remembered per browser.
  const [closed, setClosed] = useState<Record<string, boolean>>({});
  useEffect(() => {
    try {
      const v = JSON.parse(localStorage.getItem(GROUPS_KEY) || "{}");
      if (v && typeof v === "object") setClosed(v);
    } catch {
      /* storage unavailable or corrupt */
    }
  }, []);
  const saveClosed = (next: Record<string, boolean>) => {
    setClosed(next);
    try { localStorage.setItem(GROUPS_KEY, JSON.stringify(next)); } catch { /* storage unavailable */ }
  };
  const toggleGroup = (name: string) => saveClosed({ ...closed, [name]: !closed[name] });
  const setAllGroups = (shut: boolean) => saveClosed(Object.fromEntries(collapsibleGroups.map((g) => [g, shut])));
  // Opening a page inside a folded group unfolds it.
  useEffect(() => {
    const g = items.find((it) => isActive(it.to))?.group;
    if (g && closed[g]) saveClosed({ ...closed, [g]: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

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
    // repliesVersion: refetch immediately on realtime supplier replies / after replies are read.
  }, [repliesVersion]);

  const alertHref = (a: Notification): string | null => {
    const isReply = a.kind === "supplier_reply" || a.kind === "supplier_bounce";
    if (a.lead_id) return isReply ? `/leads/${a.lead_id}#supplier-replies` : `/leads/${a.lead_id}`;
    if (isReply && can("rsq.manage")) return "/supplier-inbox";
    return null;
  };

  const doLogout = async () => {
    await logout();
    router.push("/login");
  };

  const initials = (user.name || user.email || "?")
    .split(/[\s@]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

  const groups: { name: string; items: NavItem[] }[] = [];
  for (const it of items) {
    const g = groups[groups.length - 1];
    if (g && g.name === it.group) g.items.push(it); else groups.push({ name: it.group, items: [it] });
  }
  const collapsibleGroups = groups.filter((g) => g.items.length > 1).map((g) => g.name);
  const allClosed = collapsibleGroups.length > 0 && collapsibleGroups.every((g) => closed[g]);

  const renderItem = (it: NavItem, rail: boolean) => {
    const active = isActive(it.to);
    return (
      <Link
        href={it.to}
        title={rail ? it.label : undefined}
        data-testid={it.testId}
        className={cn(
          "group relative flex items-center rounded text-[13px] font-medium transition-colors",
          rail ? "justify-center h-9 w-9 mx-auto my-0.5" : "gap-2.5 px-3 py-2",
          active ? "bg-primary text-primary-foreground shadow-sm" : "text-sidebar-foreground/70 hover:bg-accent hover:text-foreground"
        )}
      >
        <it.icon size={16} className={active ? "text-primary-foreground" : "text-muted-foreground group-hover:text-foreground"} />
        {!rail && <span className="flex-1">{it.label}</span>}
        {it.testId === "nav-supplier-inbox" && (inboxUnread > 0 || inboxUnmatched > 0) &&
          (rail ? (
            <span data-testid="inbox-nav-dot" className="absolute top-0.5 right-0.5 min-w-[14px] h-[14px] px-0.5 rounded-sm bg-primary text-primary-foreground text-[9px] font-bold flex items-center justify-center ring-2 ring-sidebar">
    {(inboxUnread || inboxUnmatched) > 9 ? "9+" : inboxUnread || inboxUnmatched}
            </span>
          ) : (
            <span
    data-testid="inbox-nav-badge"
    title={inboxUnread > 0 ? `${inboxUnread} unread` : `${inboxUnmatched} unmatched`}
    className={cn("min-w-[18px] h-[18px] px-1 rounded-sm text-[10px] font-bold flex items-center justify-center",
      inboxUnread > 0 ? (active ? "bg-primary-foreground text-primary" : "bg-primary text-primary-foreground") : "bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300")}
            >
    {(inboxUnread || inboxUnmatched) > 99 ? "99+" : inboxUnread || inboxUnmatched}
            </span>
          ))}
      </Link>
    );
  };

  // `rail` = icon-only desktop mode (always fully shown). The mobile drawer renders expanded with collapsible groups.
  const renderNav = (rail: boolean) => (
    <nav className="flex-1 overflow-y-auto sidebar-scroll px-2 py-2" data-testid="sidebar-nav">
      {!rail && collapsibleGroups.length > 0 && (
        <div className="flex justify-end px-1 pt-1">
          <button type="button" onClick={() => setAllGroups(!allClosed)} data-testid="nav-toggle-all"
                  className="rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground hover:bg-accent hover:text-foreground">
            {allClosed ? "Expand all" : "Collapse all"}
          </button>
        </div>
      )}
      {groups.map((g, gi) => {
        const canFold = !rail && g.items.length > 1;
        const isClosed = canFold && !!closed[g.name];
        const hasActive = g.items.some((it) => isActive(it.to));
        return (
          <div key={g.name} data-testid={`nav-group-${g.name.toLowerCase()}`}>
            {rail ? (
              gi > 0 && <div className="mx-2 my-2 border-t border-sidebar-border" />
            ) : canFold ? (
              <button type="button" onClick={() => toggleGroup(g.name)} aria-expanded={!isClosed} data-testid={`nav-group-toggle-${g.name.toLowerCase()}`}
                      className="flex w-full items-center justify-between rounded px-3 pt-4 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground hover:text-foreground">
                <span className="flex items-center gap-2">{g.name}{isClosed && hasActive && <span className="h-1.5 w-1.5 rounded-full bg-primary" aria-label="Contains the current page" />}</span>
                <ChevronDown size={12} className={cn("transition-transform", isClosed && "-rotate-90")} />
              </button>
            ) : (
              <div className="px-3 pt-4 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{g.name}</div>
            )}
            {!isClosed && g.items.map((it) => <div key={it.to}>{renderItem(it, rail)}</div>)}
          </div>
        );
      })}
    </nav>
  );

  const bell = (
    <Popover>
      <PopoverTrigger asChild>
        <button className="relative p-1.5 rounded text-muted-foreground hover:text-foreground hover:bg-accent" data-testid="bell-btn" title="Notifications">
          <Bell size={16} />
          {alerts.length > 0 && (
            <span className="absolute -top-0.5 -right-0.5 bg-primary text-primary-foreground text-[9px] font-bold rounded-sm min-w-[15px] h-[15px] flex items-center justify-center px-1">
              {alerts.length}
            </span>
          )}
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-80 max-h-96 overflow-y-auto p-0" side="bottom" align="end" sideOffset={8} data-testid="bell-popover">
        <div className="p-3 border-b border-border">
          <div className="font-display font-bold">Notifications</div>
          <div className="text-xs text-muted-foreground">Updates live; refreshes every 30s</div>
        </div>
        {alerts.length === 0 && <div className="p-4 text-sm text-muted-foreground text-center">All caught up!</div>}
        <div className="divide-y divide-border">
          {alerts.map((a, i) => (
            <button
              key={i}
              onClick={() => { const h = alertHref(a); if (h) router.push(h); }}
              className="w-full text-left p-3 hover:bg-secondary/60 transition-colors"
              data-testid={`alert-${i}`}
            >
              <div className="flex items-start gap-2">
                {a.kind === "supplier_reply" || a.kind === "supplier_bounce" ? (
                  <span
                    className={cn("mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full", a.kind === "supplier_bounce" ? "bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300" : "bg-secondary text-foreground")}
                    data-testid={`alert-icon-${a.kind}`}
                  >
                    {a.kind === "supplier_bounce" ? <MailWarning size={14} aria-hidden /> : <MailOpen size={14} aria-hidden />}
                  </span>
                ) : (
                  <span className="text-lg">{a.icon}</span>
                )}
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

  const { openAi } = useAiPanel();
  const aiAllowed = can("ai.use");
  const [menuOpenD, setMenuOpenD] = useState(false); // desktop header
  const [menuOpenM, setMenuOpenM] = useState(false); // mobile top bar
  const pageTitle =
    [...items].filter((it) => isActive(it.to)).sort((x, y) => y.to.length - x.to.length)[0]?.label ??
    (pathname.startsWith("/profile") ? "Profile" : "");

  const renderUserMenu = (menuOpen: boolean, setMenuOpen: (v: boolean) => void) => (
    <Popover open={menuOpen} onOpenChange={setMenuOpen}>
      <PopoverTrigger asChild>
        <button
          className="flex items-center gap-2 pl-1.5 pr-2 py-1 rounded hover:bg-accent transition-colors"
          data-testid="user-menu-btn" aria-label="Account menu"
        >
          <span className="w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-[11px] font-bold">{initials}</span>
          <span className="hidden lg:block text-left leading-tight">
            <span className="block text-[13px] font-semibold text-foreground max-w-[140px] truncate">{user.name || user.email}</span>
            <span className="block text-[10px] uppercase tracking-widest text-muted-foreground">{user.role_name || user.role}</span>
          </span>
          <ChevronDown size={14} className="hidden lg:block text-muted-foreground" />
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={8} className="w-64 p-0" data-testid="user-menu">
        <div className="p-3 border-b border-border">
          <div className="flex items-center gap-3">
            <span className="w-10 h-10 shrink-0 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-sm font-bold">{initials}</span>
            <div className="min-w-0">
              <div className="text-sm font-semibold truncate">{user.name || user.email}</div>
              <div className="text-xs text-muted-foreground truncate">{user.email}</div>
              <span className="inline-block mt-1 text-[10px] uppercase tracking-widest font-semibold px-1.5 py-0.5 rounded bg-secondary text-muted-foreground">{user.role_name || user.role}</span>
            </div>
          </div>
        </div>
        <div className="p-1.5">
          <Link href="/profile" onClick={() => setMenuOpen(false)} data-testid="profile-link"
                className="flex items-center gap-2.5 px-2.5 py-2 rounded text-[13px] font-medium text-foreground hover:bg-accent">
            <UserIcon size={15} /> My profile
          </Link>
          <button onClick={doLogout} data-testid="logout-btn"
                  className="w-full flex items-center gap-2.5 px-2.5 py-2 rounded text-[13px] font-medium text-foreground hover:bg-accent">
            <LogOut size={15} /> Logout
          </button>
        </div>
      </PopoverContent>
    </Popover>
  );

  return (
    <div className="min-h-[100dvh] bg-background flex overflow-x-clip">
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
            <img src="/logo-mark-dark.png" alt="Indian Rounds" className="w-7 h-auto dark:hidden" />
            <img src="/logo-mark.png" alt="Indian Rounds" className="w-7 h-auto hidden dark:block" />
            <button onClick={toggleCollapsed} title="Expand sidebar" aria-label="Expand sidebar" data-testid="sidebar-toggle"
                    className="p-1.5 rounded text-muted-foreground hover:text-foreground hover:bg-accent">
              <PanelLeftOpen size={16} />
            </button>
          </div>
        ) : (
          <div className="flex items-center justify-between gap-2 pl-4 pr-2 h-14 border-b border-sidebar-border">
            <img src="/logo-word-dark.png" alt="Indian Rounds" className="h-8 w-auto max-w-[130px] object-contain object-left dark:hidden" />
            <img src="/logo-word.png" alt="Indian Rounds" className="h-8 w-auto max-w-[130px] object-contain object-left hidden dark:block" />
            <button onClick={toggleCollapsed} title="Collapse sidebar" aria-label="Collapse sidebar" data-testid="sidebar-toggle"
                    className="p-1.5 rounded text-muted-foreground hover:text-foreground hover:bg-accent">
              <PanelLeftClose size={16} />
            </button>
          </div>
        )}
        {renderNav(collapsed)}
      </aside>

      {/* Mobile top bar */}
      <div className="md:hidden fixed top-0 inset-x-0 z-40 bg-sidebar text-foreground border-b border-sidebar-border h-[calc(3rem+env(safe-area-inset-top))] pt-[env(safe-area-inset-top)] pl-[max(0.75rem,env(safe-area-inset-left))] pr-[max(0.75rem,env(safe-area-inset-right))] flex items-center justify-between">
        <button onClick={() => setOpen(true)} className="p-1.5 -ml-1 rounded hover:bg-accent" aria-label="Open menu" data-testid="mobile-menu-btn">
          <Menu size={20} />
        </button>
        <img src="/logo-word-dark.png" alt="Indian Rounds" className="h-6 w-auto dark:hidden" />
            <img src="/logo-word.png" alt="Indian Rounds" className="h-6 w-auto hidden dark:block" />
        {can("chat.use") && <Link href="/chat" aria-label={`Chat${chatUnread > 0 ? `, ${chatUnread} unread` : ""}`} data-testid="chat-link-mobile" className="relative p-1.5 rounded hover:bg-accent ml-auto mr-1">
          <MessageSquare size={18} />
          {chatUnread > 0 && (
            <span className="absolute -top-0.5 -right-0.5 bg-primary text-primary-foreground text-[9px] font-bold rounded-sm min-w-[15px] h-[15px] flex items-center justify-center px-1">
              {chatUnread > 99 ? "99+" : chatUnread}
            </span>
          )}
        </Link>}
        {aiAllowed && (
          <button onClick={openAi} data-testid="ai-open-btn-mobile" aria-label="Open Rounds AI" className="p-1.5 rounded hover:bg-accent">
            <Sparkles size={18} />
          </button>
        )}
        {bell}
        <ThemeMenu />
        {renderUserMenu(menuOpenM, setMenuOpenM)}
      </div>

      {/* Mobile drawer */}
      {open && (
        <div className="md:hidden fixed inset-0 z-50 flex">
          <div className="absolute inset-0 bg-black/40" onClick={() => setOpen(false)} />
          <aside className="relative w-64 max-w-[80%] h-full pt-[env(safe-area-inset-top)] flex flex-col bg-sidebar text-sidebar-foreground shadow-2xl border-r border-sidebar-border">
            <div className="flex items-center justify-between pl-4 pr-2 h-14 border-b border-sidebar-border">
              <img src="/logo-word-dark.png" alt="Indian Rounds" className="h-8 w-auto max-w-[130px] object-contain object-left dark:hidden" />
            <img src="/logo-word.png" alt="Indian Rounds" className="h-8 w-auto max-w-[130px] object-contain object-left hidden dark:block" />
              <button onClick={() => setOpen(false)} className="p-1.5 rounded text-muted-foreground hover:bg-accent" aria-label="Close menu">
                <X size={18} />
              </button>
            </div>
            {renderNav(false)}
          </aside>
        </div>
      )}

      <div className="flex-1 min-w-0 flex flex-col">
        {/* Desktop header */}
        <header className="hidden md:flex sticky top-0 z-30 h-14 items-center justify-between gap-4 px-6 bg-sidebar border-b border-border" data-testid="app-header">
          <h2 className="font-display text-[15px] tracking-tight text-foreground truncate">{pageTitle}</h2>
          <div className="flex items-center gap-1.5">
            {can("chat.use") && (
              <Link href="/chat" aria-label={`Chat${chatUnread > 0 ? `, ${chatUnread} unread` : ""}`} title="Chat" data-testid="chat-link"
                    className="relative mr-1 inline-flex h-8 w-8 items-center justify-center rounded border border-border text-foreground hover:bg-accent">
                <MessageSquare size={15} />
                {chatUnread > 0 && (
                  <span data-testid="chat-unread-badge" className="absolute -right-1 -top-1 flex h-[15px] min-w-[15px] items-center justify-center rounded-sm bg-primary px-1 text-[9px] font-bold text-primary-foreground">
                    {chatUnread > 99 ? "99+" : chatUnread}
                  </span>
                )}
              </Link>
            )}
            {aiAllowed && (
              <button onClick={openAi} data-testid="ai-open-btn" title="Rounds AI (Ctrl+J)"
                      className="flex items-center gap-1.5 h-8 px-2.5 mr-1 rounded border border-border text-[13px] font-medium text-foreground hover:bg-accent">
                <Sparkles size={14} /> AI
              </button>
            )}
            {bell}
            <ThemeMenu />
            <span className="w-px h-6 bg-border mx-1" />
            {renderUserMenu(menuOpenD, setMenuOpenD)}
          </div>
        </header>
        <main className="flex-1 min-w-0 pt-[calc(3rem+env(safe-area-inset-top))] md:pt-0">
          <div className="p-3 sm:p-4 md:p-6 max-w-[1440px] mx-auto">{children}</div>
        </main>
      </div>
    </div>
  );
}
