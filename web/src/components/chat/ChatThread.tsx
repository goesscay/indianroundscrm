"use client";

import { useCallback, useEffect, useLayoutEffect, useRef, useState, type KeyboardEvent } from "react";
import { ArrowDown, ArrowLeft, Check, CheckCheck, RotateCw, Send, Settings2, Smile, SmilePlus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { useChatRealtime } from "@/context/ChatRealtimeContext";
import type { ChatMessage, ChatMessagesResponse, ChatReaction, Conversation } from "@/types";
import LiveDot from "./LiveDot";
import RichText from "./RichText";
import { ComposerPicker, ReactionPicker } from "./EmojiPicker";
import { stickerById, type Sticker } from "./emoji";
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
const newTmpId = () => `tmp-${Date.now()}-${tmpCounter++}`;

/** The "@word" being typed right before the caret, if any. */
function mentionAt(text: string, caret: number): { start: number; query: string } | null {
  const m = /(^|\s)@([^\s@]{0,30})$/u.exec(text.slice(0, caret));
  return m ? { start: caret - m[2].length - 1, query: m[2] } : null;
}

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
  const [reads, setReads] = useState<Record<string, string | null>>({});            // userId -> last message they have read
  const [mention, setMention] = useState<{ start: number; query: string } | null>(null);
  const [mentionIdx, setMentionIdx] = useState(0);
  const picked = useRef(new Map<string, string>());                                   // "@Name" tags added to the text being written: id -> name
  const inputRef = useRef<HTMLTextAreaElement>(null);

  const scrollRef = useRef<HTMLDivElement>(null);
  const nearBottom = useRef(true);
  const wantScroll = useRef(false);
  const lastMarked = useRef<string>("");
  const messagesRef = useRef<ChatMessage[]>([]);
  const polling = useRef(false);
  const { connected, onMessage, onDeleted, onReacted, onRead, onReconnect, emitMessage, emitDeleted, registerViewing } = useChatRealtime();
  const people = conversation.members;
  const nameOf = (id: string) => people.find((p) => p.id === id)?.name ?? "";
  const meName = nameOf(meId);

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
        if (data.reads) setReads(data.reads);
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
      if (data.reads) setReads(data.reads);
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
  useEffect(
    () =>
      onReacted(({ conversation_id, message_id, reactions }) => {
        if (conversation_id !== convId) return;
        update((cur) => cur.map((x) => (x.id === message_id ? { ...x, reactions } : x)));
      }),
    [onReacted, convId, update]
  );
  useEffect(
    () =>
      onRead(({ conversation_id, user_id, message_id }) => {
        if (conversation_id !== convId) return;
        setReads((cur) => (cur[user_id] && !idGreater(message_id, cur[user_id] as string) ? cur : { ...cur, [user_id]: message_id }));
      }),
    [onRead, convId]
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

  const sendBody = async (body: string, tmpId: string, kind: "text" | "sticker" = "text", mentions: string[] = []) => {
    try {
      const { data } = await api.post<ChatMessage>(`/chat/conversations/${convId}/messages`, { body, kind, ...(mentions.length ? { mentions } : {}) });
      update((cur) => mergeMessages(cur.filter((m) => m.id !== tmpId), [data]));
      lastMarked.current = data.id;
      // Update lists/badge locally (no refetch); the websocket echo is deduped by id.
      emitMessage({ conversation_id: convId, message: data });
    } catch (e) {
      update((cur) => cur.map((m) => (m.id === tmpId ? { ...m, pending: false, failed: true } : m)));
      toast.error(formatApiError(e));
    }
  };

  const queue = (body: string, kind: "text" | "sticker", mentions: string[]) => {
    const tmpId = newTmpId();
    const optimistic: ChatMessage = {
      id: tmpId,
      conversation_id: convId,
      sender_id: meId,
      sender_name: "You",
      body,
      created_at: new Date().toISOString(),
      deleted: false,
      kind,
      mentions,
      reactions: [],
      pending: true,
    };
    wantScroll.current = true;
    nearBottom.current = true;
    setShowJump(false);
    update((cur) => [...cur, optimistic]);
    void sendBody(body, tmpId, kind, mentions);
  };

  const send = () => {
    const body = text.trim();
    if (!body) return;
    // only the tags still in the text count (the person may have deleted one after picking it)
    const mentions = Array.from(picked.current).filter(([, name]) => body.includes(`@${name}`)).map(([id]) => id);
    picked.current = new Map();
    setMention(null);
    setText("");
    queue(body, "text", mentions);
  };

  const sendSticker = (st: Sticker) => queue(st.id, "sticker", []);

  const retry = (m: ChatMessage) => {
    update((cur) => cur.map((x) => (x.id === m.id ? { ...x, pending: true, failed: false } : x)));
    void sendBody(m.body, m.id, m.kind ?? "text", m.mentions ?? []);
  };

  const react = async (m: ChatMessage, emoji: string) => {
    try {
      const { data } = await api.post<{ message_id: string; reactions: ChatReaction[] }>(`/chat/messages/${m.id}/reactions`, { emoji });
      update((cur) => cur.map((x) => (x.id === m.id ? { ...x, reactions: data.reactions } : x)));
    } catch (e) {
      toast.error(formatApiError(e));
    }
  };

  /** Put an emoji into the text at the caret. */
  const addEmoji = (em: string) => {
    const el = inputRef.current;
    const a = el?.selectionStart ?? text.length;
    const b = el?.selectionEnd ?? text.length;
    setText((cur) => cur.slice(0, a) + em + cur.slice(b));
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(a + em.length, a + em.length); });
  };

  // @mentions: the people in this conversation, filtered by what has been typed after the @
  const candidates = mention
    ? people.filter((p) => p.id !== meId && p.name.toLowerCase().includes(mention.query.toLowerCase())).slice(0, 6)
    : [];
  const pickMention = (id: string) => {
    if (!mention) return;
    const name = nameOf(id);
    const el = inputRef.current;
    const caret = el?.selectionStart ?? text.length;
    const next = `${text.slice(0, mention.start)}@${name} ${text.slice(caret)}`;
    picked.current.set(id, name);
    setText(next);
    setMention(null);
    const pos = mention.start + name.length + 2;
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(pos, pos); });
  };
  const onType = (value: string, caret: number) => {
    setText(value);
    const m = mentionAt(value, caret);
    setMention(m);
    setMentionIdx(0);
  };

  /** Ticks on your own messages: one grey tick = sent, two = seen by some, two blue = seen by everyone. */
  const seen = (m: ChatMessage) => {
    const others = people.filter((p) => p.id !== meId);
    const readers = others.filter((p) => reads[p.id] && !idGreater(m.id, reads[p.id] as string));
    return { all: others.length > 0 && readers.length === others.length, some: readers.length > 0, names: readers.map((p) => p.name) };
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
    if (mention && candidates.length > 0) {
      if (e.key === "ArrowDown") { e.preventDefault(); setMentionIdx((i) => (i + 1) % candidates.length); return; }
      if (e.key === "ArrowUp") { e.preventDefault(); setMentionIdx((i) => (i - 1 + candidates.length) % candidates.length); return; }
      if ((e.key === "Enter" || e.key === "Tab") && !e.nativeEvent.isComposing) { e.preventDefault(); pickMention(candidates[Math.min(mentionIdx, candidates.length - 1)].id); return; }
    }
    if (mention && e.key === "Escape") { setMention(null); return; }
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
            const canReact = !m.deleted && !m.pending && !m.failed;
            const sticker = m.kind === "sticker" && !m.deleted ? stickerById(m.body) : null;
            const isSticker = m.kind === "sticker" && !m.deleted;
            const mentionNames = (m.mentions ?? []).map(nameOf).filter(Boolean);
            const sn = mine && !m.pending && !m.failed && !m.deleted ? seen(m) : null;
            const tools = (
              <>
                {canReact && (
                  <ReactionPicker onPick={(em) => void react(m, em)}>
                    <button aria-label="React to message" data-testid={`chat-react-${m.id}`}
                            className="self-center p-1 rounded text-neutral-400 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-white hover:bg-neutral-100 dark:hover:bg-white/10 opacity-0 group-hover:opacity-100 focus:opacity-100 data-[state=open]:opacity-100">
                      <SmilePlus size={14} />
                    </button>
                  </ReactionPicker>
                )}
                {canDelete && (
                  <button onClick={() => remove(m)} aria-label="Delete message" data-testid={`chat-delete-${m.id}`}
                          className="self-center p-1 rounded text-neutral-400 dark:text-neutral-400 hover:text-red-700 dark:hover:text-red-400 hover:bg-neutral-100 dark:hover:bg-white/10 opacity-0 group-hover:opacity-100 focus:opacity-100">
                    <Trash2 size={13} />
                  </button>
                )}
              </>
            );
            const stamp = (
              <div className={cn("text-[10px] mt-0.5 flex items-center justify-end gap-1", mine && !isSticker ? "text-neutral-400 dark:text-neutral-600" : "text-neutral-400 dark:text-neutral-400")}>
                {m.pending ? "Sending..." : m.failed ? "Not sent" : timeLabel(m.created_at)}
                {sn && (
                  <span title={sn.names.length ? `Seen by ${sn.names.join(", ")}` : "Sent"} data-testid={`chat-ticks-${m.id}`} data-seen={sn.all ? "all" : sn.some ? "some" : "none"}
                        className={cn("inline-flex", sn.all ? "text-sky-400 dark:text-sky-600" : "")}>
                    {sn.some ? <CheckCheck size={13} /> : <Check size={13} />}
                  </span>
                )}
              </div>
            );
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
                  {mine && <div className="flex mr-1">{tools}</div>}
                  <div className={cn("flex flex-col min-w-0 max-w-[85%]", embedded ? "" : "sm:max-w-[70%]", mine ? "items-end" : "items-start")}>
                    {isSticker ? (
                      <div className={cn("rounded-xl px-4 py-2 text-center", sticker ? sticker.tone : "bg-secondary text-foreground", m.pending && "opacity-60", m.failed && "ring-1 ring-red-500")} data-testid={`chat-sticker-${m.body}`}>
                        {showName && <div className="text-[11px] font-semibold opacity-70 mb-0.5">{m.sender_name}</div>}
                        <div className="text-5xl leading-tight">{sticker?.emoji ?? "🙂"}</div>
                        <div className="text-xs font-bold">{sticker?.label ?? "Sticker"}</div>
                        {stamp}
                      </div>
                    ) : (
                      <div className={cn(
                        "px-3 py-1.5 rounded text-sm leading-snug break-words",
                        mine ? "bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900" : "bg-card border border-border text-neutral-900 dark:text-neutral-100",
                        m.pending && "opacity-60", m.failed && "ring-1 ring-red-500")}>
                        {showName && <div className="text-[11px] font-semibold text-neutral-500 dark:text-neutral-400 mb-0.5">{m.sender_name}</div>}
                        {m.deleted ? (
                          <span className={cn("italic text-[13px]", mine ? "text-neutral-300 dark:text-neutral-600" : "text-neutral-400 dark:text-neutral-400")}>Message deleted</span>
                        ) : (
                          <div className="whitespace-pre-wrap">
                            <RichText text={m.body} mine={mine} mentionNames={mentionNames} meName={meName} />
                          </div>
                        )}
                        {stamp}
                      </div>
                    )}
                    {!m.deleted && (m.reactions?.length ?? 0) > 0 && (
                      <div className="flex flex-wrap gap-1 mt-0.5" data-testid={`chat-reactions-${m.id}`}>
                        {(m.reactions ?? []).map((r) => {
                          const iReacted = r.user_ids.includes(meId);
                          return (
                            <button key={r.emoji} type="button" onClick={() => void react(m, r.emoji)}
                                    title={r.user_ids.map(nameOf).filter(Boolean).join(", ")} data-testid={`chat-reaction-${m.id}-${r.emoji}`}
                                    className={cn("inline-flex items-center gap-1 rounded-full border px-1.5 py-px text-xs", iReacted ? "border-sky-400 bg-sky-50 text-sky-900 dark:bg-sky-950/50 dark:text-sky-200" : "border-border bg-card hover:bg-secondary")}>
                              <span>{r.emoji}</span><span className="tabular-nums text-[11px]">{r.user_ids.length}</span>
                            </button>
                          );
                        })}
                      </div>
                    )}
                  </div>
                  {!mine && <div className="flex ml-1">{tools}</div>}
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

      <footer className="relative shrink-0 border-t border-border p-2.5 flex items-end gap-2 bg-card">
        {mention && candidates.length > 0 && (
          <ul className="absolute bottom-full left-2.5 right-2.5 mb-1 max-h-48 overflow-y-auto rounded-md border border-border bg-popover p-1 shadow-md z-20" role="listbox" aria-label="Tag someone" data-testid="mention-list">
            {candidates.map((p, i) => (
              <li key={p.id} role="option" aria-selected={i === mentionIdx}>
                <button type="button" onMouseDown={(e) => { e.preventDefault(); pickMention(p.id); }} data-testid={`mention-${p.id}`}
                        className={cn("flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm", i === mentionIdx ? "bg-secondary" : "hover:bg-secondary/60")}>
                  <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-neutral-900 text-[10px] font-bold text-white dark:bg-neutral-100 dark:text-neutral-900">{initials(p.name)}</span>
                  <span className="truncate font-medium">{p.name}</span>
                  <span className="ml-auto text-[11px] capitalize text-muted-foreground">{String(p.role).replace(/_/g, " ")}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
        <ComposerPicker onEmoji={addEmoji} onSticker={sendSticker}>
          <Button type="button" variant="outline" aria-label="Emoji and stickers" data-testid="chat-emoji-btn" className="h-9 px-2.5"><Smile /></Button>
        </ComposerPicker>
        <textarea
          ref={inputRef}
          value={text}
          onChange={(e) => onType(e.target.value, e.target.selectionStart ?? e.target.value.length)}
          onKeyDown={onKey}
          onClick={(e) => setMention(mentionAt(text, e.currentTarget.selectionStart ?? text.length))}
          rows={1}
          maxLength={4000}
          placeholder={embedded ? "Write a message, @ to tag" : "Write a message (@ to tag someone, Enter to send, Shift+Enter for a new line)"}
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
