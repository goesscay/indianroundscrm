"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { api } from "@/lib/api";
import { usePathname } from "next/navigation";
import { useCurrentUser } from "@/context/AuthContext";
import { useChatRealtime } from "@/context/ChatRealtimeContext";
import { useChatUnread } from "@/context/ChatUnreadContext";
import { applyDeletedMessage, applyIncomingMessage } from "@/components/chat/util";
import type { Conversation } from "@/types";
import { DEFAULT_ALERTS, desktopState, flashTitle, loadAlertSettings, notifyDesktop, playPing, requestDesktopPermission, saveAlertSettings, type ChatAlertSettings, type DesktopState } from "@/lib/chatAlerts";

export interface DockWindow {
  conversationId: string;
  minimized: boolean;
}

interface ChatDockValue {
  convs: Conversation[];
  loaded: boolean;
  windows: DockWindow[];
  panelOpen: boolean;
  loadConvs: () => Promise<void>;
  upsertConv: (c: Conversation) => void;
  setPanelOpen: (open: boolean) => void;
  openWindow: (c: Conversation) => void;
  toggleMinimize: (id: string) => void;
  closeWindow: (id: string) => void;
  /** How a new message gets noticed: open the window, a ping, a desktop notification. Remembered per person. */
  alerts: ChatAlertSettings;
  setAlerts: (s: ChatAlertSettings) => void;
  desktop: DesktopState;
  enableDesktop: () => Promise<void>;
}

const MAX_WINDOWS = 3;
const noop = () => {};
const ChatDockContext = createContext<ChatDockValue>({
  convs: [],
  loaded: false,
  windows: [],
  panelOpen: false,
  loadConvs: async () => {},
  upsertConv: noop,
  setPanelOpen: noop,
  openWindow: noop,
  toggleMinimize: noop,
  closeWindow: noop,
  alerts: DEFAULT_ALERTS,
  setAlerts: noop,
  desktop: "unsupported",
  enableDesktop: async () => {},
});

const storageKey = (userId: string) => `chat-dock:${userId}`;

function sortConvs(list: Conversation[]): Conversation[] {
  return [...list].sort((a, b) => (b.updated_at || "").localeCompare(a.updated_at || ""));
}

export function ChatDockProvider({ children }: { children: ReactNode }) {
  const { user } = useCurrentUser();
  const userId = user.id;
  const [convs, setConvs] = useState<Conversation[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [windows, setWindows] = useState<DockWindow[]>([]);
  const [panelOpen, setPanelOpen] = useState(false);
  const [hydrated, setHydrated] = useState(false);
  const [alerts, setAlertsState] = useState<ChatAlertSettings>(DEFAULT_ALERTS);
  const [desktop, setDesktop] = useState<DesktopState>("unsupported");
  const alertsRef = useRef(alerts);
  useEffect(() => { alertsRef.current = alerts; }, [alerts]);
  useEffect(() => { setAlertsState(loadAlertSettings(userId)); setDesktop(desktopState()); }, [userId]);
  const setAlerts = useCallback((s: ChatAlertSettings) => { setAlertsState(s); saveAlertSettings(userId, s); }, [userId]);
  const enableDesktop = useCallback(async () => { setDesktop(await requestDesktopPermission()); }, []);
  const { onMessage, onDeleted, onConversationUpdated, onReconnect, isViewing } = useChatRealtime();
  const pathname = usePathname();
  const onChatPage = pathname === "/chat" || pathname.startsWith("/chat/");
  const convsRef = useRef<Conversation[]>([]);
  useEffect(() => {
    convsRef.current = convs;
  }, [convs]);
  const onChatPageRef = useRef(onChatPage);
  useEffect(() => {
    onChatPageRef.current = onChatPage;
  }, [onChatPage]);

  // Restore persisted state (after mount, so SSR markup matches).
  useEffect(() => {
    try {
      const raw = localStorage.getItem(storageKey(userId));
      if (raw) {
        const p = JSON.parse(raw) as { windows?: DockWindow[]; panelOpen?: boolean };
        if (Array.isArray(p.windows)) {
          setWindows(
            p.windows
              .filter((w) => w && typeof w.conversationId === "string")
              .slice(-MAX_WINDOWS)
              .map((w) => ({ conversationId: w.conversationId, minimized: !!w.minimized }))
          );
        }
        setPanelOpen(!!p.panelOpen);
      }
    } catch {
      /* ignore */
    }
    setHydrated(true);
  }, [userId]);

  useEffect(() => {
    if (!hydrated) return;
    try {
      localStorage.setItem(storageKey(userId), JSON.stringify({ windows, panelOpen }));
    } catch {
      /* ignore */
    }
  }, [windows, panelOpen, hydrated, userId]);

  // Messages already announced (by the realtime event or a list refresh), so nothing is announced twice.
  const noticed = useRef(new Set<string>());
  const noticeRef = useRef<(conversationId: string, from: string, body: string) => void>(() => {});

  const loadConvs = useCallback(async () => {
    try {
      const { data } = await api.get<Conversation[]>("/chat/conversations");
      // Without a WebSocket the list refresh is how a new message is discovered: announce it here.
      for (const c of data) {
        const before = convsRef.current.find((x) => x.id === c.id);
        const lm = c.last_message;
        if (before && c.unread_count > before.unread_count && lm && lm.sender_id !== userId && !lm.deleted && !noticed.current.has(lm.id) && !isViewing(c.id)) {
          noticed.current.add(lm.id);
          noticeRef.current(c.id, lm.sender_name, lm.body);
        }
      }
      setConvs(data);
    } catch {
      /* transient */
    } finally {
      setLoaded(true);
    }
  }, [userId, isViewing]);

  // Coalesced list refetch (the /chat page owns its own list and refetches itself).
  const refetchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const scheduleRefetch = useCallback(() => {
    if (onChatPageRef.current) return;
    if (refetchTimer.current) clearTimeout(refetchTimer.current);
    refetchTimer.current = setTimeout(() => void loadConvs(), 250);
  }, [loadConvs]);
  useEffect(() => () => {
    if (refetchTimer.current) clearTimeout(refetchTimer.current);
  }, []);

  const openById = useCallback((id: string) => {
    setWindows((cur) => {
      const existing = cur.find((w) => w.conversationId === id);
      if (existing) return cur.map((w) => (w.conversationId === id ? { ...w, minimized: false } : w));
      const next = [...cur, { conversationId: id, minimized: false }];
      return next.length > MAX_WINDOWS ? next.slice(next.length - MAX_WINDOWS) : next;
    });
  }, []);

  // A message from someone else that the person is not looking at: make sure they notice it.
  const notice = useCallback((conversationId: string, from: string, body: string) => {
    const a = alertsRef.current;
    if (a.sound) playPing();
    flashTitle(`💬 ${from} sent a message`);
    if (a.desktop) notifyDesktop(from, body, `chat-${conversationId}`, () => openById(conversationId));
    if (a.autoOpen && typeof window !== "undefined" && window.matchMedia("(min-width: 768px)").matches && !onChatPageRef.current) openById(conversationId);
  }, [openById]);

  useEffect(() => { noticeRef.current = notice; }, [notice]);

  // When the unread count goes up, fetch the list: that is how a message is noticed when no WebSocket is connected.
  const { total } = useChatUnread();
  const prevTotal = useRef<number | null>(null);
  useEffect(() => {
    if (prevTotal.current !== null && total > prevTotal.current) void loadConvs();
    prevTotal.current = total;
  }, [total, loadConvs]);

  // Realtime: update the list locally; refetch only for unknown conversations / conversation.updated / reconnect.
  useEffect(
    () =>
      onMessage(({ conversation_id, message }) => {
        const viewing = isViewing(conversation_id);
        const r = applyIncomingMessage(convsRef.current, conversation_id, message, userId, viewing);
        if (message.sender_id !== userId && !message.deleted && !viewing && !noticed.current.has(message.id)) {
          noticed.current.add(message.id);
          notice(conversation_id, message.sender_name, message.body);
        }
        if (!r.known) {
          scheduleRefetch();
          return;
        }
        if (r.list !== convsRef.current) {
          convsRef.current = r.list;
          setConvs(r.list);
        }
      }),
    [onMessage, isViewing, userId, scheduleRefetch, notice]
  );
  useEffect(
    () =>
      onDeleted(({ conversation_id, message_id }) => {
        const next = applyDeletedMessage(convsRef.current, conversation_id, message_id);
        if (next !== convsRef.current) {
          convsRef.current = next;
          setConvs(next);
        }
      }),
    [onDeleted]
  );
  useEffect(() => onConversationUpdated(() => scheduleRefetch()), [onConversationUpdated, scheduleRefetch]);
  useEffect(() => onReconnect(() => scheduleRefetch()), [onReconnect, scheduleRefetch]);

  const upsertConv = useCallback((c: Conversation) => {
    setConvs((cur) => sortConvs([c, ...cur.filter((x) => x.id !== c.id)]));
  }, []);

  const openWindow = useCallback((c: Conversation) => {
    setConvs((cur) => sortConvs([{ ...c, unread_count: 0 }, ...cur.filter((x) => x.id !== c.id)]));
    setWindows((cur) => {
      const existing = cur.find((w) => w.conversationId === c.id);
      if (existing) return cur.map((w) => (w.conversationId === c.id ? { ...w, minimized: false } : w));
      const next = [...cur, { conversationId: c.id, minimized: false }];
      return next.length > MAX_WINDOWS ? next.slice(next.length - MAX_WINDOWS) : next;
    });
  }, []);

  const toggleMinimize = useCallback((id: string) => {
    setWindows((cur) => cur.map((w) => (w.conversationId === id ? { ...w, minimized: !w.minimized } : w)));
  }, []);

  const closeWindow = useCallback((id: string) => {
    setWindows((cur) => cur.filter((w) => w.conversationId !== id));
  }, []);

  const value = useMemo(
    () => ({ convs, loaded, windows, panelOpen, loadConvs, upsertConv, setPanelOpen, openWindow, toggleMinimize, closeWindow, alerts, setAlerts, desktop, enableDesktop }),
    [convs, loaded, windows, panelOpen, loadConvs, upsertConv, openWindow, toggleMinimize, closeWindow, alerts, setAlerts, desktop, enableDesktop]
  );
  return <ChatDockContext.Provider value={value}>{children}</ChatDockContext.Provider>;
}

export function useChatDock(): ChatDockValue {
  return useContext(ChatDockContext);
}
