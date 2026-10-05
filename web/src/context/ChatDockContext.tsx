"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import type { Conversation } from "@/types";

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

  const loadConvs = useCallback(async () => {
    try {
      const { data } = await api.get<Conversation[]>("/chat/conversations");
      setConvs(data);
    } catch {
      /* transient */
    } finally {
      setLoaded(true);
    }
  }, []);

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
    () => ({ convs, loaded, windows, panelOpen, loadConvs, upsertConv, setPanelOpen, openWindow, toggleMinimize, closeWindow }),
    [convs, loaded, windows, panelOpen, loadConvs, upsertConv, openWindow, toggleMinimize, closeWindow]
  );
  return <ChatDockContext.Provider value={value}>{children}</ChatDockContext.Provider>;
}

export function useChatDock(): ChatDockValue {
  return useContext(ChatDockContext);
}
