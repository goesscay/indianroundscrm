"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useAuth } from "@/context/AuthContext";
import { destroyEcho, getEcho, realtimeEnabled } from "@/lib/echo";
import type { ChatConversationReadEvent, ChatConversationUpdatedEvent, ChatMessageDeletedEvent, ChatMessageReactedEvent, ChatMessageSentEvent, SupplierReplyEvent } from "@/types";

type Unsub = () => void;

interface ChatRealtimeValue {
  /** Realtime configured (NEXT_PUBLIC_REVERB_KEY set) for this user. */
  enabled: boolean;
  /** WebSocket currently connected and subscribed. When false, consumers poll at the normal rate. */
  connected: boolean;
  onMessage: (h: (e: ChatMessageSentEvent) => void) => Unsub;
  onDeleted: (h: (e: ChatMessageDeletedEvent) => void) => Unsub;
  onConversationUpdated: (h: (e: ChatConversationUpdatedEvent) => void) => Unsub;
  onReacted: (h: (e: ChatMessageReactedEvent) => void) => Unsub;
  /** Someone read a conversation up to a message (drives the read ticks). */
  onRead: (h: (e: ChatConversationReadEvent) => void) => Unsub;
  /** Fires on every (re)connect: consumers do a catch-up refetch. */
  onReconnect: (h: () => void) => Unsub;
  /** Locally feed an event (e.g. the POST response) to all handlers; handlers dedupe by id. */
  emitMessage: (e: ChatMessageSentEvent) => void;
  emitDeleted: (e: ChatMessageDeletedEvent) => void;
  /** A thread registers while mounted; "viewing" also requires the tab to be visible. */
  registerViewing: (conversationId: string) => Unsub;
  isViewing: (conversationId: string) => boolean;
  /** Per-user notifications channel (supplier replies). Subscribed for every signed-in user. */
  notifConnected: boolean;
  onSupplierReply: (h: (e: SupplierReplyEvent) => void) => Unsub;
  /** Fires on every (re)subscribe of the notifications channel: consumers do a catch-up refetch. */
  onNotifReconnect: (h: () => void) => Unsub;
}

const noopUnsub: Unsub = () => {};
const ChatRealtimeContext = createContext<ChatRealtimeValue>({
  enabled: false,
  connected: false,
  onMessage: () => noopUnsub,
  onDeleted: () => noopUnsub,
  onConversationUpdated: () => noopUnsub,
  onReacted: () => noopUnsub,
  onRead: () => noopUnsub,
  onReconnect: () => noopUnsub,
  emitMessage: () => {},
  emitDeleted: () => {},
  registerViewing: () => noopUnsub,
  isViewing: () => false,
  notifConnected: false,
  onSupplierReply: () => noopUnsub,
  onNotifReconnect: () => noopUnsub,
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
  const chatAllowed = !!userId && can("chat.use");
  const enabled = chatAllowed && realtimeEnabled();
  // The notifications channel is for every signed-in user (chat permission not required).
  const wsOn = !!userId && realtimeEnabled();
  const [connected, setConnected] = useState(false);
  const [notifConnected, setNotifConnected] = useState(false);

  const [msgRef, onMessage] = useRegistry<(e: ChatMessageSentEvent) => void>();
  const [delRef, onDeleted] = useRegistry<(e: ChatMessageDeletedEvent) => void>();
  const [updRef, onConversationUpdated] = useRegistry<(e: ChatConversationUpdatedEvent) => void>();
  const [reactRef, onReacted] = useRegistry<(e: ChatMessageReactedEvent) => void>();
  const [readRef, onRead] = useRegistry<(e: ChatConversationReadEvent) => void>();
  const [recRef, onReconnect] = useRegistry<() => void>();
  const [supRef, onSupplierReply] = useRegistry<(e: SupplierReplyEvent) => void>();
  const [nrecRef, onNotifReconnect] = useRegistry<() => void>();
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
    if (!wsOn || !userId) return;
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
      if (enabled) {
        const channel = echo.private(`chat.user.${userId}`);
        channel
          .listen(".message.sent", (e: ChatMessageSentEvent) => emitMessage(e))
          .listen(".message.deleted", (e: ChatMessageDeletedEvent) => emitDeleted(e))
          .listen(".conversation.updated", (e: ChatConversationUpdatedEvent) => updRef.current.forEach((h) => h(e)))
          .listen(".message.reacted", (e: ChatMessageReactedEvent) => reactRef.current.forEach((h) => h(e)))
          .listen(".conversation.read", (e: ChatConversationReadEvent) => readRef.current.forEach((h) => h(e)));
        channel.subscribed(() => {
          if (cancelled) return;
          backoff = 5_000;
          setConnected(true);
          recRef.current.forEach((h) => h());
        });
        channel.error(() => {
          if (!cancelled) setConnected(false);
        });
      }

      const notif = echo.private(`notifications.user.${userId}`);
      notif.listen(".supplier.reply", (e: SupplierReplyEvent) => supRef.current.forEach((h) => h(e)));
      notif.subscribed(() => {
        if (cancelled) return;
        backoff = 5_000;
        setNotifConnected(true);
        nrecRef.current.forEach((h) => h());
      });
      notif.error(() => {
        if (!cancelled) setNotifConnected(false);
      });

      const pusher = echo.connector.pusher;
      const onState = (s: { previous: string; current: string }) => {
        if (cancelled) return;
        if (s.current === "connected") {
          // pusher-js re-subscribes channels itself; "subscribed" fires again after a reconnect.
          return;
        }
        setConnected(false);
        setNotifConnected(false);
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
      setNotifConnected(false);
    };
  }, [wsOn, enabled, userId, emitMessage, emitDeleted, updRef, reactRef, readRef, recRef, supRef, nrecRef]);

  const value = useMemo<ChatRealtimeValue>(
    () => ({
      enabled,
      connected: enabled && connected,
      onMessage,
      onDeleted,
      onConversationUpdated,
      onReacted,
      onRead,
      onReconnect,
      emitMessage,
      emitDeleted,
      registerViewing,
      isViewing,
      notifConnected: wsOn && notifConnected,
      onSupplierReply,
      onNotifReconnect,
    }),
    [enabled, connected, onMessage, onDeleted, onConversationUpdated, onReacted, onRead, onReconnect, emitMessage, emitDeleted, registerViewing, isViewing, wsOn, notifConnected, onSupplierReply, onNotifReconnect]
  );
  return <ChatRealtimeContext.Provider value={value}>{children}</ChatRealtimeContext.Provider>;
}

export function useChatRealtime(): ChatRealtimeValue {
  return useContext(ChatRealtimeContext);
}
