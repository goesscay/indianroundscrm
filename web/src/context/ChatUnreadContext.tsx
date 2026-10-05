"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { api } from "@/lib/api";

interface ChatUnreadValue {
  total: number;
  refresh: () => Promise<void>;
}

const ChatUnreadContext = createContext<ChatUnreadValue>({ total: 0, refresh: async () => {} });

/** Polls GET /chat/unread-count every 15s (paused while the tab is hidden). */
export function ChatUnreadProvider({ children }: { children: ReactNode }) {
  const [total, setTotal] = useState(0);
  const inFlight = useRef(false);

  const refresh = useCallback(async () => {
    if (inFlight.current) return;
    inFlight.current = true;
    try {
      const { data } = await api.get<{ total: number }>("/chat/unread-count");
      setTotal(Number(data.total) || 0);
    } catch {
      /* ignore transient errors */
    } finally {
      inFlight.current = false;
    }
  }, []);

  useEffect(() => {
    void refresh();
    const tick = () => {
      if (document.visibilityState === "visible") void refresh();
    };
    const t = setInterval(tick, 15000);
    document.addEventListener("visibilitychange", tick);
    window.addEventListener("focus", tick);
    return () => {
      clearInterval(t);
      document.removeEventListener("visibilitychange", tick);
      window.removeEventListener("focus", tick);
    };
  }, [refresh]);

  const value = useMemo(() => ({ total, refresh }), [total, refresh]);
  return <ChatUnreadContext.Provider value={value}>{children}</ChatUnreadContext.Provider>;
}

export function useChatUnread(): ChatUnreadValue {
  return useContext(ChatUnreadContext);
}
