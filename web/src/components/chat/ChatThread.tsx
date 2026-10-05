"use client";

import { useCallback, useEffect, useLayoutEffect, useRef, useState, type KeyboardEvent } from "react";
import { ArrowDown, ArrowLeft, RotateCw, Send, Settings2, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { useChatRealtime } from "@/context/ChatRealtimeContext";
import type { ChatMessage, ChatMessagesResponse, Conversation } from "@/types";
import LiveDot from "./LiveDot";
import RichText from "./RichText";
import { dayKey, dayLabel, idGreater, initials, timeLabel } from "./util";

interface Props {
  conversation: Conversation;
  meId: string;
  isAdmin: boolean;
  onBack: () => void;
  onManage: () => void;
  /** Called after sending or marking read so the list / unread badge can refresh. */
  onActivity: () => void;
  /** Compact docked-window mode: no header (the window supplies its own). */
  embedded?: boolean;
}

const POLL_MS = 3000;
const POLL_CONNECTED_MS = 30000;
const NEAR_PX = 120;
let tmpCounter = 0;

function mergeMessages(cur: ChatMessage[], incoming: ChatMessage[]): ChatMessage[] {
  const byId = new Map<string, ChatMessage>();
  cur.forEach((m) => byId.set(m.id, m));
  incoming.forEach((m) => byId.set(m.id, { ...byId.get(m.id), ...m, pending: false, failed: false }));
  const server = Array.from(byId.values()).filter((m) => !m.id.startsWith("tmp-"));
  const tmps = Array.from(byId.values()).filter((m) => m.id.startsWith("tmp-"));
  server.sort((a, b) => (idGreater(a.id, b.id) ? 1 : idGreater(b.id, a.id) ? -1 : 0));
  return [...server, ...tmps];
}

export default function ChatThread({ conversation, meId, isAdmin, onBack, onManage, onActivity, embedded = false }: Props) {
  const convId = conversation.id;
  const isGroup = conversation.type === "group";
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(true);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [text, setText] = useState("");
  const [showJump, setShowJump] = useState(false);

  const scrollRef = useRef<HTMLDivElement>(null);
  const nearBottom = useRef(true);
  const wantScroll = useRef(false);
  const lastMarked = useRef<string>("");
  const messagesRef = useRef<ChatMessage[]>([]);
  const polling = useRef(false);
  const { connected, onMessage, onDeleted, onReconnect, emitMessage, emitDeleted, registerViewing } = useChatRealtime();

  /** Update messages state and the synchronous ref together (events can arrive faster than renders). */
  const update = useCallback((fn: (cur: ChatMessage[]) => ChatMessage[]) => {
    const next = fn(messagesRef.current);
    messagesRef.current = next;
    setMessages(next);
  }, []);

  // While mounted, this thread counts as "being viewed" (unread badge won't bump for it).
  useEffect(() => registerViewing(convId), [registerViewing, convId]);

  const lastServerId = useCallback((): string | null => {
    const list = messagesRef.current;
    for (let i = list.length - 1; i >= 0; i--) if (!list[i].id.startsWith("tmp-")) return list[i].id;
    return null;
  }, []);

  const markRead = useCallback(
    async (messageId?: string | null, silent = false) => {
      const id = messageId ?? lastServerId();
      if (!id || lastMarked.current === id) return;
      lastMarked.current = id;
      try {
        await api.post(`/chat/conversations/${convId}/read`, { message_id: id });
        if (!silent) onActivity();
      } catch {
        lastMarked.current = "";
      }
    },
    [convId, lastServerId, onActivity]
  );

  const scrollToBottom = useCallback((smooth = false) => {
    const el = scrollRef.current;
    if (!el) return;
    el.scrollTo({ top: el.scrollHeight, behavior: smooth ? "smooth" : "auto" });
  }, []);

  // Apply scroll after DOM updates when we decided to stick to the bottom.
  useLayoutEffect(() => {
    if (wantScroll.current) {
      wantScroll.current = false;
      scrollToBottom();
    }
  }, [messages, scrollToBottom]);

  // Initial load.
  useEffect(() => {
    let alive = true;
    update(() => []);
    setLoading(true);
    setShowJump(false);
    nearBottom.current = true;
    lastMarked.current = "";
    (async () => {
      try {
        const { data } = await api.get<ChatMessagesResponse>(`/chat/conversations/${convId}/messages`, { params: { limit: 50 } });
        if (!alive) return;
        wantScroll.current = true;
        update(() => mergeMessages([], data.messages));
        setHasMore(data.has_more);
        const last = data.messages[data.messages.length - 1];
        if (last) {
          lastMarked.current = last.id;
          await api.post(`/chat/conversations/${convId}/read`, { message_id: last.id }).catch(() => {});
          onActivity();
        }
      } catch (e) {
        if (alive) toast.error(formatApiError(e));
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [convId]);

  // Polling for newer messages.
  const poll = useCallback(async () => {
    if (polling.current || document.visibilityState !== "visible") return;
    const after = lastServerId();
    if (!after) return;
    polling.current = true;
    try {
      const { data } = await api.get<ChatMessagesResponse>(`/chat/conversations/${convId}/messages`, { params: { after_id: after, limit: 50 } });
      if (data.messages.length === 0) return;
      const fresh = data.messages.filter((m) => !messagesRef.current.some((x) => x.id === m.id && !x.pending));
      if (fresh.length === 0) return;
      if (nearBottom.current) wantScroll.current = true;
      else if (fresh.some((m) => m.sender_id !== meId)) setShowJump(true);
      update((cur) => mergeMessages(cur, fresh));
      if (fresh.some((m) => m.sender_id !== meId) && nearBottom.current) void markRead(fresh[fresh.length - 1].id);
      else if (nearBottom.current) lastMarked.current = fresh[fresh.length - 1].id;
    } catch {
      /* transient */
    } finally {
      polling.current = false;
    }
  }, [convId, lastServerId, markRead, meId, update]);

  useEffect(() => {
    // Safety-net rate while the websocket is live; normal polling otherwise.
    const t = setInterval(() => void poll(), connected ? POLL_CONNECTED_MS : POLL_MS);
    const onVis = () => {
      if (document.visibilityState !== "visible") return;
      void poll();
      // Messages that arrived while the tab was hidden were not marked read yet.
      if (nearBottom.current) void markRead();
    };
    document.addEventListener("visibilitychange", onVis);
    window.addEventListener("focus", onVis);
    return () => {
      clearInterval(t);
      document.removeEventListener("visibilitychange", onVis);
      window.removeEventListener("focus", onVis);
    };
  }, [poll, connected, markRead]);

  // Realtime: append instantly, reconcile with optimistic sends, handle deletions, catch up on (re)connect.
  useEffect(
    () =>
      onMessage(({ conversation_id, message: m }) => {
        if (conversation_id !== convId) return;
        if (messagesRef.current.some((x) => x.id === m.id)) return; // already have it (POST response / poll)
        const fromOther = m.sender_id !== meId;
        const stick = nearBottom.current;
        update((cur) => {
          let base = cur;
          if (!fromOther) {
            // Our own message echoed back: replace the oldest matching optimistic bubble.
            const i = cur.findIndex((x) => x.id.startsWith("tmp-") && x.pending && x.body === m.body);
            if (i >= 0) base = cur.filter((_, k) => k !== i);
          }
          return mergeMessages(base, [m]);
        });
        if (stick) wantScroll.current = true;
        else if (fromOther) setShowJump(true);
        if (!fromOther) {
          lastMarked.current = m.id;
        } else if (stick && document.visibilityState === "visible") {
          void markRead(m.id, true);
        } else if (!stick) {
          onActivity(); // unread on the server but not marked: reconcile list + badge
        }
      }),
    [onMessage, convId, meId, update, markRead, onActivity]
  );
  useEffect(
    () =>
      onDeleted(({ conversation_id, message_id }) => {
        if (conversation_id !== convId) return;
        update((cur) => (cur.some((x) => x.id === message_id && !x.deleted) ? cur.map((x) => (x.id === message_id ? { ...x, deleted: true } : x)) : cur));
      }),
    [onDeleted, convId, update]
  );
  useEffect(() => onReconnect(() => void poll()), [onReconnect, poll]);

  const onScroll = () => {
    const el = scrollRef.current;
    if (!el) return;
    const near = el.scrollHeight - el.scrollTop - el.clientHeight < NEAR_PX;
    nearBottom.current = near;
    if (near && showJump) {
      setShowJump(false);
      void markRead();
    }
  };

  const loadOlder = async () => {
    const first = messages.find((m) => !m.id.startsWith("tmp-"));
    if (!first || loadingMore) return;
    setLoadingMore(true);
    const el = scrollRef.current;
    const prevHeight = el?.scrollHeight ?? 0;
    try {
      const { data } = await api.get<ChatMessagesResponse>(`/chat/conversations/${convId}/messages`, { params: { before_id: first.id, limit: 50 } });
      update((cur) => mergeMessages(cur, data.messages));
      setHasMore(data.has_more);
      requestAnimationFrame(() => {
        if (el) el.scrollTop = el.scrollHeight - prevHeight;
      });
    } catch (e) {
      toast.error(formatApiError(e));
    } finally {
      setLoadingMore(false);
    }
  };

  const sendBody = async (body: string, tmpId: string) => {
    try {
      const { data } = await api.post<ChatMessage>(`/chat/conversations/${convId}/messages`, { body });
      update((cur) => mergeMessages(cur.filter((m) => m.id !== tmpId), [data]));
      lastMarked.current = data.id;
      // Update lists/badge locally (no refetch); the websocket echo is deduped by id.
      emitMessage({ conversation_id: convId, message: data });
    } catch (e) {
      update((cur) => cur.map((m) => (m.id === tmpId ? { ...m, pending: false, failed: true } : m)));
      toast.error(formatApiError(e));
    }
  };

  const send = () => {
    const body = text.trim();
    if (!body) return;
    const tmpId = `tmp-${Date.now()}-${tmpCounter++}`;
    const optimistic: ChatMessage = {
      id: tmpId,
      conversation_id: convId,
      sender_id: meId,
      sender_name: "You",
      body,
      created_at: new Date().toISOString(),
      deleted: false,
      pending: true,
    };
    wantScroll.current = true;
    nearBottom.current = true;
    setShowJump(false);
    update((cur) => [...cur, optimistic]);
    setText("");
    void sendBody(body, tmpId);
  };

  const retry = (m: ChatMessage) => {
    update((cur) => cur.map((x) => (x.id === m.id ? { ...x, pending: true, failed: false } : x)));
    void sendBody(m.body, m.id);
  };

  const discard = (m: ChatMessage) => update((cur) => cur.filter((x) => x.id !== m.id));

  const remove = async (m: ChatMessage) => {
    if (!window.confirm("Delete this message?")) return;
    try {
      await api.delete(`/chat/messages/${m.id}`);
      update((cur) => cur.map((x) => (x.id === m.id ? { ...x, deleted: true } : x)));
      emitDeleted({ conversation_id: convId, message_id: m.id });
    } catch (e) {
      toast.error(formatApiError(e));
    }
  };

  const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault();
      send();
    }
  };

  const jump = () => {
    scrollToBottom(true);
    setShowJump(false);
    void markRead();
  };

  return (
    <section className="flex flex-col h-full min-h-0 bg-card" data-testid="chat-thread" aria-label={`Conversation with ${conversation.name}`}>
      {!embedded && (
      <header className="flex items-center gap-2 px-3 h-14 shrink-0 border-b border-border">
        <button onClick={onBack} aria-label="Back to conversations" data-testid="chat-back" className="md:hidden p-1.5 -ml-1 rounded hover:bg-neutral-100 dark:hover:bg-white/10">
          <ArrowLeft size={18} />
        </button>
        <div className="w-9 h-9 shrink-0 rounded bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900 text-xs font-bold flex items-center justify-center">{initials(conversation.name)}</div>
        <div className="min-w-0 flex-1">
          <div className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 truncate" data-testid="chat-thread-name">{conversation.name}</div>
          {isGroup && <div className="text-[11px] text-neutral-500 dark:text-neutral-400">{conversation.members.length} members</div>}
        </div>
        <LiveDot />
        {isGroup && isAdmin && (
          <Button variant="outline" size="sm" onClick={onManage} data-testid="manage-group-btn" aria-label="Manage group">
            <Settings2 /> <span className="hidden sm:inline">Manage</span>
          </Button>
        )}
      </header>
      )}

      <div className="relative flex-1 min-h-0">
        <div ref={scrollRef} onScroll={onScroll} className="absolute inset-0 overflow-y-auto px-3 sm:px-4 py-3 bg-neutral-50/60 dark:bg-black/20" data-testid="chat-messages">
          {hasMore && (
            <div className="text-center mb-3">
              <Button variant="outline" size="sm" onClick={loadOlder} disabled={loadingMore} data-testid="chat-load-older">
                {loadingMore ? "Loading..." : "Load older messages"}
              </Button>
            </div>
          )}
          {loading && <div className="text-center text-sm text-neutral-400 dark:text-neutral-400 py-8">Loading messages...</div>}
          {!loading && messages.length === 0 && (
            <div className="text-center text-sm text-neutral-400 dark:text-neutral-400 py-12" data-testid="chat-empty">No messages yet. Say hello.</div>
          )}
          {messages.map((m, idx) => {
            const mine = m.sender_id === meId;
            const dk = dayKey(m.created_at);
            const prev = idx > 0 ? messages[idx - 1] : null;
            const showDay = !prev || dayKey(prev.created_at) !== dk;
            const showName = isGroup && !mine && (showDay || prev?.sender_id !== m.sender_id);
            const canDelete = !m.deleted && !m.pending && !m.failed && (mine || (isGroup && isAdmin));
            return (
              <div key={m.id}>
                {showDay && (
                  <div className="flex items-center gap-3 my-3" data-testid="chat-day-sep">
                    <div className="flex-1 border-t border-border" />
                    <span className="text-[10px] font-semibold uppercase tracking-widest text-neutral-400 dark:text-neutral-400">{dayLabel(m.created_at)}</span>
                    <div className="flex-1 border-t border-border" />
                  </div>
                )}
                <div className={cn("group flex mb-1.5", mine ? "justify-end" : "justify-start")} data-testid={`chat-msg-${m.id}`}>
                  {canDelete && mine && (
                    <button onClick={() => remove(m)} aria-label="Delete message" data-testid={`chat-delete-${m.id}`}
                            className="self-center mr-1 p-1 rounded text-neutral-400 dark:text-neutral-400 hover:text-red-700 dark:hover:text-red-400 hover:bg-neutral-100 dark:hover:bg-white/10 opacity-0 group-hover:opacity-100 focus:opacity-100">
                      <Trash2 size={13} />
                    </button>
                  )}
                  <div className={cn("max-w-[85%]", embedded ? "" : "sm:max-w-[70%]",
                    " px-3 py-1.5 rounded text-sm leading-snug break-words",
                    mine ? "bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900" : "bg-card border border-border text-neutral-900 dark:text-neutral-100",
                    m.pending && "opacity-60", m.failed && "ring-1 ring-red-500")}>
                    {showName && <div className="text-[11px] font-semibold text-neutral-500 dark:text-neutral-400 mb-0.5">{m.sender_name}</div>}
                    {m.deleted ? (
                      <span className={cn("italic text-[13px]", mine ? "text-neutral-300 dark:text-neutral-600" : "text-neutral-400 dark:text-neutral-400")}>Message deleted</span>
                    ) : (
                      <div className="whitespace-pre-wrap">
                        <RichText text={m.body} mine={mine} />
                      </div>
                    )}
                    <div className={cn("text-[10px] mt-0.5 text-right", mine ? "text-neutral-400 dark:text-neutral-600" : "text-neutral-400 dark:text-neutral-400")}>
                      {m.pending ? "Sending..." : m.failed ? "Not sent" : timeLabel(m.created_at)}
                    </div>
                  </div>
                  {canDelete && !mine && (
                    <button onClick={() => remove(m)} aria-label="Delete message" data-testid={`chat-delete-${m.id}`}
                            className="self-center ml-1 p-1 rounded text-neutral-400 dark:text-neutral-400 hover:text-red-700 dark:hover:text-red-400 hover:bg-neutral-100 dark:hover:bg-white/10 opacity-0 group-hover:opacity-100 focus:opacity-100">
                      <Trash2 size={13} />
                    </button>
                  )}
                </div>
                {m.failed && (
                  <div className="flex justify-end gap-3 text-[11px] text-red-700 dark:text-red-300 mb-1.5" data-testid="chat-failed">
                    <span>Failed to send.</span>
                    <button onClick={() => retry(m)} className="inline-flex items-center gap-1 underline" data-testid="chat-retry"><RotateCw size={11} /> Retry</button>
                    <button onClick={() => discard(m)} className="underline">Discard</button>
                  </div>
                )}
              </div>
            );
          })}
        </div>
        {showJump && (
          <button onClick={jump} data-testid="chat-new-messages"
                  className="absolute bottom-3 left-1/2 -translate-x-1/2 inline-flex items-center gap-1.5 bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900 text-xs font-medium px-3 py-1.5 rounded">
            <ArrowDown size={13} /> New messages
          </button>
        )}
      </div>

      <footer className="shrink-0 border-t border-border p-2.5 flex items-end gap-2 bg-card">
        <textarea
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={onKey}
          rows={1}
          maxLength={4000}
          placeholder={embedded ? "Write a message" : "Write a message (Enter to send, Shift+Enter for a new line)"}
          aria-label="Message"
          data-testid="chat-input"
          className="flex-1 resize-none max-h-32 min-h-9 rounded border border-input bg-card dark:bg-background px-3 py-2 text-base md:text-sm focus-visible:outline-none focus-visible:border-black dark:focus-visible:border-white/60"
          style={{ fieldSizing: "content" } as React.CSSProperties}
        />
        <Button onClick={send} disabled={!text.trim()} aria-label="Send message" data-testid="chat-send" className="h-9">
          <Send /> <span className={embedded ? "sr-only" : "hidden sm:inline"}>Send</span>
        </Button>
      </footer>
    </section>
  );
}
