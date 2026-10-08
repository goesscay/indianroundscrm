"use client";

import { memo, useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore, type KeyboardEvent } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { BellRing, ChevronDown, ChevronUp, ExternalLink, MessageSquare, MessageSquarePlus, Search, Users, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useAuth, useCurrentUser } from "@/context/AuthContext";
import { useChatUnread } from "@/context/ChatUnreadContext";
import { useChatDock } from "@/context/ChatDockContext";
import { useChatRealtime } from "@/context/ChatRealtimeContext";
import LiveDot from "./LiveDot";
import { cn } from "@/lib/utils";
import type { Conversation } from "@/types";
import ChatThread from "./ChatThread";
import GroupDialog from "./GroupDialog";
import NewChatDialog from "./NewChatDialog";
import { initials, relativeTime } from "./util";

const LIST_POLL_MS = 8000;
const LIST_POLL_CONNECTED_MS = 45000;
const MIN_QUERY = "(min-width: 768px)";

/** Desktop-only flag; server snapshot is false so there is no hydration mismatch. */
function useIsDesktop(): boolean {
  return useSyncExternalStore(
    (cb) => {
      const mq = window.matchMedia(MIN_QUERY);
      mq.addEventListener("change", cb);
      return () => mq.removeEventListener("change", cb);
    },
    () => window.matchMedia(MIN_QUERY).matches,
    () => false
  );
}

function Avatar({ conv }: { conv: Conversation }) {
  return (
    <span className="w-10 h-10 shrink-0 rounded bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900 text-[11px] font-bold flex items-center justify-center">
      {conv.type === "group" ? <Users size={16} /> : initials(conv.name)}
    </span>
  );
}

const DockConvRow = memo(function DockConvRow({ c, meId, open, onOpen }: { c: Conversation; meId: string; open: boolean; onOpen: (c: Conversation) => void }) {
  const lm = c.last_message;
  const preview = lm
    ? lm.deleted
      ? "Message deleted"
      : `${c.type === "group" || lm.sender_id === meId ? (lm.sender_id === meId ? "You" : lm.sender_name) + ": " : ""}${lm.body}`
    : "No messages yet";
  const unread = c.unread_count > 0 && !open;
  return (
    <button
      role="listitem"
      type="button"
      onClick={() => onOpen(c)}
      data-testid={`chat-dock-conv-${c.id}`}
      className="w-full flex items-center gap-2.5 px-3 py-2 text-left hover:bg-neutral-50 dark:hover:bg-white/5"
    >
      <Avatar conv={c} />
      <span className="min-w-0 flex-1">
        <span className="flex items-baseline justify-between gap-2">
          <span className={cn("text-sm truncate", unread ? "font-bold text-neutral-950 dark:text-white" : "font-semibold text-neutral-900 dark:text-neutral-100")}>{c.name}</span>
          <span className="text-[10px] text-neutral-400 dark:text-neutral-400 shrink-0">{relativeTime(lm?.created_at ?? c.updated_at)}</span>
        </span>
        <span className="flex items-center justify-between gap-2 mt-0.5">
          <span className={cn("text-xs truncate", unread ? "text-neutral-800 dark:text-neutral-200" : "text-neutral-500 dark:text-neutral-400")}>{preview}</span>
          {unread && (
            <span className="shrink-0 min-w-[18px] h-[18px] px-1 rounded-sm bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900 text-[10px] font-bold flex items-center justify-center">
              {c.unread_count > 99 ? "99+" : c.unread_count}
            </span>
          )}
        </span>
      </span>
    </button>
  );
});

export default function ChatDock() {
  const pathname = usePathname();
  const isDesktop = useIsDesktop();
  const { can } = useAuth();
  if (!can("chat.use") || !isDesktop || pathname === "/chat" || pathname.startsWith("/chat/")) return null;
  return <DockInner />;
}

function DockInner() {
  const { user, can } = useCurrentUser();
  const isAdmin = can("chat.create_groups");
  const { total, refresh: refreshUnread } = useChatUnread();
  const { connected } = useChatRealtime();
  const { convs, loaded, windows, panelOpen, loadConvs, upsertConv, setPanelOpen, openWindow, toggleMinimize, closeWindow, alerts, setAlerts, desktop, enableDesktop } = useChatDock();
  const [alertsOpen, setAlertsOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [newChatOpen, setNewChatOpen] = useState(false);
  const [newGroupOpen, setNewGroupOpen] = useState(false);
  const [pulse, setPulse] = useState(false);
  const prevTotal = useRef(total);

  const openIds = useMemo(() => new Set(windows.filter((w) => !w.minimized).map((w) => w.conversationId)), [windows]);
  const needList = panelOpen || windows.length > 0;

  useEffect(() => {
    void loadConvs();
  }, [loadConvs]);

  useEffect(() => {
    if (!needList) return;
    const tick = () => {
      if (document.visibilityState === "visible") void loadConvs();
    };
    const t = setInterval(tick, connected ? LIST_POLL_CONNECTED_MS : LIST_POLL_MS);
    document.addEventListener("visibilitychange", tick);
    return () => {
      clearInterval(t);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [needList, loadConvs, connected]);

  // Brief pulse when the unread total grows.
  useEffect(() => {
    let t: ReturnType<typeof setTimeout> | undefined;
    if (total > prevTotal.current && !panelOpen) {
      setPulse(true);
      t = setTimeout(() => setPulse(false), 2500);
    }
    prevTotal.current = total;
    return () => {
      if (t) clearTimeout(t);
    };
  }, [total, panelOpen]);

  // Drop windows whose conversation no longer exists (e.g. group deleted).
  useEffect(() => {
    if (!loaded) return;
    windows.forEach((w) => {
      if (!convs.some((c) => c.id === w.conversationId)) closeWindow(w.conversationId);
    });
  }, [loaded, convs, windows, closeWindow]);

  const filtered = useMemo(() => {
    const s = search.trim().toLowerCase();
    return s ? convs.filter((c) => c.name.toLowerCase().includes(s)) : convs;
  }, [convs, search]);

  const onActivity = useCallback(() => {
    void loadConvs();
    void refreshUnread();
  }, [loadConvs, refreshUnread]);

  const onPanelKey = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key === "Escape") {
      e.stopPropagation();
      setPanelOpen(false);
    }
  };

  const unreadLabel = total > 99 ? "99+" : String(total);

  return (
    <div className="fixed bottom-0 right-4 z-40 flex items-end gap-3 pointer-events-none" data-testid="chat-dock">
      {windows.map((w) => {
        const conv = convs.find((c) => c.id === w.conversationId);
        if (!conv) return null;
        const unread = conv.unread_count > 0 && w.minimized;
        return (
          <section
            key={w.conversationId}
            data-testid={`chat-dock-window-${w.conversationId}`}
            aria-label={`Chat with ${conv.name}`}
            className={cn(
              "pointer-events-auto flex flex-col bg-card border border-border border-b-0 shadow-[0_2px_12px_rgba(0,0,0,0.12)]",
              w.minimized ? "w-56 h-10" : "w-80 h-[420px]"
            )}
          >
            <header className="flex items-center gap-1.5 px-2 h-10 shrink-0 bg-neutral-900 text-white dark:bg-neutral-800 dark:text-neutral-100">
              <button
                type="button"
                onClick={() => toggleMinimize(w.conversationId)}
                className="flex items-center gap-2 min-w-0 flex-1 text-left h-full"
                aria-label={w.minimized ? `Expand chat with ${conv.name}` : `Minimize chat with ${conv.name}`}
                aria-expanded={!w.minimized}
              >
                <span className="w-6 h-6 shrink-0 rounded-sm bg-white/15 text-[10px] font-bold flex items-center justify-center">
                  {conv.type === "group" ? <Users size={12} /> : initials(conv.name)}
                </span>
                <span className="text-sm font-semibold truncate">{conv.name}</span>
                {unread && <span data-testid={`chat-dock-unread-${w.conversationId}`} className="w-2 h-2 shrink-0 rounded-full bg-white" aria-label="Unread messages" />}
              </button>
              <Link href={`/chat?c=${conv.id}`} aria-label="Open in full view" title="Open in full view" className="p-1 rounded-sm hover:bg-white/15">
                <ExternalLink size={14} />
              </Link>
              <button
                type="button"
                onClick={() => toggleMinimize(w.conversationId)}
                aria-label={w.minimized ? "Expand" : "Minimize"}
                data-testid={`chat-dock-minimize-${w.conversationId}`}
                className="p-1 rounded-sm hover:bg-white/15"
              >
                {w.minimized ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
              </button>
              <button
                type="button"
                onClick={() => closeWindow(w.conversationId)}
                aria-label="Close chat"
                data-testid={`chat-dock-close-${w.conversationId}`}
                className="p-1 rounded-sm hover:bg-white/15"
              >
                <X size={14} />
              </button>
            </header>
            {!w.minimized && (
              <div className="flex-1 min-h-0">
                <ChatThread conversation={conv} meId={user.id} isAdmin={isAdmin} embedded onBack={() => {}} onManage={() => {}} onActivity={onActivity} />
              </div>
            )}
          </section>
        );
      })}

      {panelOpen ? (
        <div
          role="dialog"
          aria-label="Chat"
          data-testid="chat-dock-panel"
          onKeyDown={onPanelKey}
          className="pointer-events-auto w-80 h-[420px] flex flex-col bg-card border border-border border-b-0 shadow-[0_2px_12px_rgba(0,0,0,0.12)]"
        >
          <header className="flex items-center gap-1.5 px-3 h-10 shrink-0 bg-neutral-900 text-white dark:bg-neutral-800 dark:text-neutral-100">
            <MessageSquare size={14} />
            <span className="text-sm font-semibold flex-1 flex items-center gap-1.5">Chat <LiveDot /></span>
            <button type="button" onClick={() => setAlertsOpen((o) => !o)} aria-label="Message alerts" aria-expanded={alertsOpen} title="Message alerts" data-testid="chat-dock-alerts-btn" className="p-1 rounded-sm hover:bg-white/15">
              <BellRing size={14} />
            </button>
            <Link href="/chat" aria-label="Open full view" title="Open full view" data-testid="chat-dock-full" className="p-1 rounded-sm hover:bg-white/15">
              <ExternalLink size={14} />
            </Link>
            <button type="button" onClick={() => setPanelOpen(false)} aria-label="Minimize chat" data-testid="chat-dock-panel-minimize" className="p-1 rounded-sm hover:bg-white/15">
              <ChevronDown size={14} />
            </button>
          </header>
          {alertsOpen && (
            <div className="border-b border-border bg-secondary/40 p-3 text-xs" data-testid="chat-dock-alerts">
              <div className="mb-1.5 font-semibold">When a message arrives</div>
              {([["autoOpen", "Open the chat window"], ["sound", "Play a sound"], ["desktop", "Notify me when this tab is in the background"]] as const).map(([k, label]) => (
                <label key={k} className="flex cursor-pointer items-center gap-2 py-0.5">
                  <input type="checkbox" className="accent-black dark:accent-white" checked={alerts[k]} onChange={(e) => setAlerts({ ...alerts, [k]: e.target.checked })} data-testid={`chat-alert-${k}`} />
                  {label}
                </label>
              ))}
              {alerts.desktop && desktop === "default" && <button type="button" onClick={() => void enableDesktop()} className="mt-1.5 underline" data-testid="chat-alert-enable">Allow notifications in this browser</button>}
              {alerts.desktop && desktop === "denied" && <p className="mt-1.5 text-muted-foreground">Notifications are blocked in this browser. Allow them in the site settings.</p>}
            </div>
          )}
          <div className="p-2 border-b border-border space-y-2">
            <div className="flex gap-1.5">
              <Button size="sm" className="flex-1" onClick={() => setNewChatOpen(true)} data-testid="chat-dock-new-chat">
                <MessageSquarePlus /> New chat
              </Button>
              {isAdmin && (
                <Button size="sm" variant="outline" className="flex-1" onClick={() => setNewGroupOpen(true)} data-testid="chat-dock-new-group">
                  <Users /> New group
                </Button>
              )}
            </div>
            <div className="relative">
              <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-neutral-400 dark:text-neutral-400" />
              <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search conversations" aria-label="Search conversations" className="pl-8 h-8" data-testid="chat-dock-search" />
            </div>
          </div>
          <div className="flex-1 overflow-y-auto divide-y divide-border" role="list" aria-label="Conversations" data-testid="chat-dock-list">
            {!loaded && <div className="p-4 text-sm text-neutral-400 dark:text-neutral-400">Loading...</div>}
            {loaded && filtered.length === 0 && (
              <div className="p-6 text-center text-sm text-neutral-400 dark:text-neutral-400">{convs.length === 0 ? "No conversations yet." : "No matches"}</div>
            )}
            {filtered.map((c) => (
              <DockConvRow key={c.id} c={c} meId={user.id} open={openIds.has(c.id)} onOpen={openWindow} />
            ))}
          </div>
        </div>
      ) : (
        <button
          type="button"
          onClick={() => setPanelOpen(true)}
          aria-label={total > 0 ? `Open chat, ${total} unread` : "Open chat"}
          data-testid="chat-dock-toggle"
          className={cn(
            "pointer-events-auto flex items-center gap-2 w-56 h-10 px-3 bg-neutral-900 text-white dark:bg-neutral-800 dark:text-neutral-100 border border-neutral-900 dark:border-neutral-700 border-b-0 text-sm font-semibold hover:bg-neutral-800 dark:hover:bg-neutral-700",
            pulse && "animate-pulse"
          )}
        >
          <MessageSquare size={14} />
          <span className="flex-1 text-left">Chat</span>
          {total > 0 && (
            <span data-testid="chat-dock-badge" className="min-w-[18px] h-[18px] px-1 rounded-sm bg-white text-neutral-900 text-[10px] font-bold flex items-center justify-center">
              {unreadLabel}
            </span>
          )}
          <ChevronUp size={14} />
        </button>
      )}

      <NewChatDialog
        open={newChatOpen}
        onOpenChange={setNewChatOpen}
        onOpened={(c) => {
          upsertConv(c);
          openWindow(c);
        }}
      />
      {isAdmin && (
        <GroupDialog
          open={newGroupOpen}
          onOpenChange={setNewGroupOpen}
          meId={user.id}
          onSaved={(c) => {
            upsertConv(c);
            openWindow(c);
          }}
        />
      )}
    </div>
  );
}
