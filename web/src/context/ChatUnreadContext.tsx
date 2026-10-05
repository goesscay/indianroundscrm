"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { api } from "@/lib/api";
import { useAuth } from "@/context/AuthContext";
import { useChatRealtime } from "@/context/ChatRealtimeContext";

interface ChatUnreadValue {
  total: number;
  refresh: () => Promise<void>;
}

const ChatUnreadContext = createContext<ChatUnreadValue>({ total: 0, refresh: async () => {} });

const POLL_MS = 15000;
const POLL_CONNECTED_MS = 60000;

/**
 * Unread badge. Incremented instantly from realtime events; GET /chat/unread-count
 * reconciles it (every 15s when offline, 60s as a safety net while connected,
 * paused while the tab is hidden, plus on focus and every (re)connect).
 */
export function ChatUnreadProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const meId = user?.id ?? null;
  const { connected, onMessage, onReconnect, isViewing } = useChatRealtime();
  const [total, setTotal] = useState(0);
  const inFlight = useRef(false);
  const dirty = useRef(false);
  const seen = useRef<string[]>([]);

  const refresh = useCallback(async () => {
    if (inFlight.current) {
      dirty.current = true; // re-run once the current request settles
      return;
    }
    inFlight.current = true;
    try {
      do {
        dirty.current = false;
        try {
          const { data } = await api.get<{ total: number }>("/chat/unread-count");
          setTotal(Number(data.total) || 0);
        } catch {
          /* ignore transient errors */
        }
      } while (dirty.current);
    } finally {
      inFlight.current = false;
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  useEffect(() => {
    const tick = () => {
      if (document.visibilityState === "visible") void refresh();
    };
    const t = setInterval(tick, connected ? POLL_CONNECTED_MS : POLL_MS);
    document.addEventListener("visibilitychange", tick);
    window.addEventListener("focus", tick);
    return () => {
      clearInterval(t);
      document.removeEventListener("visibilitychange", tick);
      window.removeEventListener("focus", tick);
    };
  }, [refresh, connected]);

  // Instant increment for messages from others in threads that aren't being viewed.
  useEffect(
    () =>
      onMessage(({ conversation_id, message }) => {
        if (!meId || message.sender_id === meId || message.deleted) return;
        if (seen.current.includes(message.id)) return;
        seen.current = [...seen.current.slice(-49), message.id];
        if (isViewing(conversation_id)) return;
        setTotal((n) => n + 1);
      }),
    [onMessage, isViewing, meId]
  );

  useEffect(() => onReconnect(() => void refresh()), [onReconnect, refresh]);

  const value = useMemo(() => ({ total, refresh }), [total, refresh]);
  return <ChatUnreadContext.Provider value={value}>{children}</ChatUnreadContext.Provider>;
}

export function useChatUnread(): ChatUnreadValue {
  return useContext(ChatUnreadContext);
}
