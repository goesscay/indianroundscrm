"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useAuth } from "@/context/AuthContext";
import { destroyEcho, getEcho, realtimeEnabled } from "@/lib/echo";
import type { ChatConversationUpdatedEvent, ChatMessageDeletedEvent, ChatMessageSentEvent } from "@/types";

type Unsub = () => void;

interface ChatRealtimeValue {
  /** Realtime configured (NEXT_PUBLIC_REVERB_KEY set) for this user. */
  enabled: boolean;
  /** WebSocket currently connected and subscribed. When false, consumers poll at the normal rate. */
  connected: boolean;
  onMessage: (h: (e: ChatMessageSentEvent) => void) => Unsub;
  onDeleted: (h: (e: ChatMessageDeletedEvent) => void) => Unsub;
  onConversationUpdated: (h: (e: ChatConversationUpdatedEvent) => void) => Unsub;
  /** Fires on every (re)connect: consumers do a catch-up refetch. */
  onReconnect: (h: () => void) => Unsub;
  /** Locally feed an event (e.g. the POST response) to all handlers; handlers dedupe by id. */
  emitMessage: (e: ChatMessageSentEvent) => void;
  emitDeleted: (e: ChatMessageDeletedEvent) => void;
  /** A thread registers while mounted; "viewing" also requires the tab to be visible. */
  registerViewing: (conversationId: string) => Unsub;
  isViewing: (conversationId: string) => boolean;
}

const noopUnsub: Unsub = () => {};
const ChatRealtimeContext = createContext<ChatRealtimeValue>({
  enabled: false,
  connected: false,
  onMessage: () => noopUnsub,
  onDeleted: () => noopUnsub,
  onConversationUpdated: () => noopUnsub,
  onReconnect: () => noopUnsub,
  emitMessage: () => {},
  emitDeleted: () => {},
  registerViewing: () => noopUnsub,
  isViewing: () => false,
});

function useRegistry<T>() {
  const ref = useRef(new Set<T>());
  const add = useCallback((h: T): Unsub => {
    ref.current.add(h);
    return () => {
      ref.current.delete(h);
    };
  }, []);
  return [ref, add] as const;
}

const BACKOFF_MAX = 60_000;

export function ChatRealtimeProvider({ children }: { children: ReactNode }) {
  const { user, can } = useAuth();
  const userId = user?.id ?? null;
  const allowed = !!userId && can("chat.use");
  const enabled = allowed && realtimeEnabled();
  const [connected, setConnected] = useState(false);

  const [msgRef, onMessage] = useRegistry<(e: ChatMessageSentEvent) => void>();
  const [delRef, onDeleted] = useRegistry<(e: ChatMessageDeletedEvent) => void>();
  const [updRef, onConversationUpdated] = useRegistry<(e: ChatConversationUpdatedEvent) => void>();
  const [recRef, onReconnect] = useRegistry<() => void>();
  const viewing = useRef(new Map<string, number>());

  const emitMessage = useCallback((e: ChatMessageSentEvent) => {
    msgRef.current.forEach((h) => h(e));
  }, [msgRef]);
  const emitDeleted = useCallback((e: ChatMessageDeletedEvent) => {
    delRef.current.forEach((h) => h(e));
  }, [delRef]);

  const registerViewing = useCallback((id: string): Unsub => {
    viewing.current.set(id, (viewing.current.get(id) ?? 0) + 1);
    return () => {
      const n = (viewing.current.get(id) ?? 1) - 1;
      if (n <= 0) viewing.current.delete(id);
      else viewing.current.set(id, n);
    };
  }, []);
  const isViewing = useCallback(
    (id: string) => (viewing.current.get(id) ?? 0) > 0 && typeof document !== "undefined" && document.visibilityState === "visible",
    []
  );

  useEffect(() => {
    if (!enabled || !userId) return;
    let cancelled = false;
    let retryTimer: ReturnType<typeof setTimeout> | undefined;
    let backoff = 5_000;
    let unbind: (() => void) | undefined;

    (async () => {
      let echo;
      try {
        echo = await getEcho();
      } catch {
        return; // libs failed to load: stay on polling
      }
      if (cancelled || !echo) return;
      const channel = echo.private(`chat.user.${userId}`);
      channel
        .listen(".message.sent", (e: ChatMessageSentEvent) => emitMessage(e))
        .listen(".message.deleted", (e: ChatMessageDeletedEvent) => emitDeleted(e))
        .listen(".conversation.updated", (e: ChatConversationUpdatedEvent) => updRef.current.forEach((h) => h(e)));

      const markUp = () => {
        if (cancelled) return;
        backoff = 5_000;
        setConnected(true);
        recRef.current.forEach((h) => h());
      };
      channel.subscribed(markUp);
      channel.error(() => {
        if (!cancelled) setConnected(false);
      });

      const pusher = echo.connector.pusher;
      const onState = (s: { previous: string; current: string }) => {
        if (cancelled) return;
        if (s.current === "connected") {
          // pusher-js re-subscribes channels itself; "subscribed" fires again after a reconnect.
          return;
        }
        setConnected(false);
        if (s.current === "unavailable" || s.current === "failed") {
          // Back off instead of hammering a missing server (keeps the console quiet).
          pusher.disconnect();
          retryTimer = setTimeout(() => {
            if (!cancelled) pusher.connect();
          }, backoff);
          backoff = Math.min(backoff * 2, BACKOFF_MAX);
        }
      };
      pusher.connection.bind("state_change", onState);
      unbind = () => pusher.connection.unbind("state_change", onState);
    })();

    return () => {
      cancelled = true;
      if (retryTimer) clearTimeout(retryTimer);
      unbind?.();
      destroyEcho();
      setConnected(false);
    };
  }, [enabled, userId, emitMessage, emitDeleted, updRef, recRef]);

  const value = useMemo<ChatRealtimeValue>(
    () => ({
      enabled,
      connected: enabled && connected,
      onMessage,
      onDeleted,
      onConversationUpdated,
      onReconnect,
      emitMessage,
      emitDeleted,
      registerViewing,
      isViewing,
    }),
    [enabled, connected, onMessage, onDeleted, onConversationUpdated, onReconnect, emitMessage, emitDeleted, registerViewing, isViewing]
  );
  return <ChatRealtimeContext.Provider value={value}>{children}</ChatRealtimeContext.Provider>;
}

export function useChatRealtime(): ChatRealtimeValue {
  return useContext(ChatRealtimeContext);
}
