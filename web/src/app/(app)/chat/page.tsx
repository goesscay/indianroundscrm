"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { MessageSquarePlus, Search, Users } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useCurrentUser } from "@/context/AuthContext";
import { useChatUnread } from "@/context/ChatUnreadContext";
import { cn } from "@/lib/utils";
import type { Conversation } from "@/types";
import ChatThread from "@/components/chat/ChatThread";
import GroupDialog from "@/components/chat/GroupDialog";
import NewChatDialog from "@/components/chat/NewChatDialog";
import { initials, relativeTime } from "@/components/chat/util";

const LIST_POLL_MS = 8000;

function sortConvs(list: Conversation[]): Conversation[] {
  return [...list].sort((a, b) => (b.updated_at || "").localeCompare(a.updated_at || ""));
}

export default function ChatPage() {
  const { user, can } = useCurrentUser();
  const isAdmin = can("chat.create_groups");
  const { refresh: refreshUnread } = useChatUnread();
  const [convs, setConvs] = useState<Conversation[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [newChatOpen, setNewChatOpen] = useState(false);
  const [newGroupOpen, setNewGroupOpen] = useState(false);
  const [manageOpen, setManageOpen] = useState(false);
  const activeRef = useRef<string | null>(null);
  useEffect(() => {
    activeRef.current = activeId;
  }, [activeId]);

  const loadConvs = useCallback(async () => {
    try {
      const { data } = await api.get<Conversation[]>("/chat/conversations");
      // The open thread is being read; don't flash an unread badge for it.
      setConvs(data.map((c) => (c.id === activeRef.current ? { ...c, unread_count: 0 } : c)));
    } catch (e) {
      if (!loaded) toast.error(formatApiError(e));
    } finally {
      setLoaded(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    void loadConvs();
    const tick = () => {
      if (document.visibilityState === "visible") void loadConvs();
    };
    const t = setInterval(tick, LIST_POLL_MS);
    document.addEventListener("visibilitychange", tick);
    window.addEventListener("focus", tick);
    return () => {
      clearInterval(t);
      document.removeEventListener("visibilitychange", tick);
      window.removeEventListener("focus", tick);
    };
  }, [loadConvs]);

  const onActivity = useCallback(() => {
    void loadConvs();
    void refreshUnread();
  }, [loadConvs, refreshUnread]);

  const upsert = useCallback((c: Conversation) => {
    setConvs((cur) => sortConvs([c, ...cur.filter((x) => x.id !== c.id)]));
  }, []);

  const openConv = (c: Conversation) => {
    upsert({ ...c, unread_count: 0 });
    setActiveId(c.id);
  };

  const active = convs.find((c) => c.id === activeId) ?? null;

  const filtered = useMemo(() => {
    const s = search.trim().toLowerCase();
    return s ? convs.filter((c) => c.name.toLowerCase().includes(s)) : convs;
  }, [convs, search]);

  // Deep link: /chat?c=<id> opens that conversation (once the list has loaded).
  const deepLinked = useRef(false);
  useEffect(() => {
    if (!loaded || deepLinked.current) return;
    deepLinked.current = true;
    const id = new URLSearchParams(window.location.search).get("c");
    if (id && convs.some((c) => c.id === id)) setActiveId(id);
  }, [loaded, convs]);

  // If the open conversation vanished (group deleted), close it.
  useEffect(() => {
    if (loaded && activeId && !convs.some((c) => c.id === activeId)) setActiveId(null);
  }, [convs, loaded, activeId]);

  return (
    <div
      className="flex border border-border bg-white overflow-hidden h-[calc(100dvh-5.5rem)] md:h-[calc(100dvh-3rem)]"
      data-testid="chat-page"
    >
      {/* Conversation list */}
      <aside
        className={cn("w-full md:w-80 md:shrink-0 md:border-r border-border flex-col min-h-0", active ? "hidden md:flex" : "flex")}
        data-testid="chat-list-pane"
      >
        <div className="p-3 border-b border-border space-y-2.5">
          <div className="flex items-center justify-between gap-2">
            <h1 className="font-display text-lg font-bold text-neutral-900">Chat</h1>
            <div className="flex gap-1.5">
              {isAdmin && (
                <Button variant="outline" size="sm" onClick={() => setNewGroupOpen(true)} data-testid="new-group-btn">
                  <Users /> New group
                </Button>
              )}
              <Button size="sm" onClick={() => setNewChatOpen(true)} data-testid="new-chat-btn">
                <MessageSquarePlus /> New chat
              </Button>
            </div>
          </div>
          <div className="relative">
            <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-neutral-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search conversations" aria-label="Search conversations" className="pl-8" data-testid="chat-search" />
          </div>
        </div>
        <div className="flex-1 overflow-y-auto divide-y divide-border" data-testid="chat-list" role="list" aria-label="Conversations">
          {!loaded && <div className="p-4 text-sm text-neutral-400">Loading...</div>}
          {loaded && filtered.length === 0 && (
            <div className="p-6 text-center text-sm text-neutral-400" data-testid="chat-list-empty">
              {convs.length === 0 ? "No conversations yet. Start a new chat." : "No matches"}
            </div>
          )}
          {filtered.map((c) => {
            const lm = c.last_message;
            const preview = lm ? (lm.deleted ? "Message deleted" : `${c.type === "group" || lm.sender_id === user.id ? (lm.sender_id === user.id ? "You" : lm.sender_name) + ": " : ""}${lm.body}`) : "No messages yet";
            const unread = c.unread_count > 0 && c.id !== activeId;
            return (
              <button
                key={c.id}
                role="listitem"
                onClick={() => setActiveId(c.id)}
                data-testid={`chat-conv-${c.id}`}
                className={cn("w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-neutral-50", c.id === activeId && "bg-neutral-100")}
              >
                <span className="w-10 h-10 shrink-0 rounded bg-neutral-900 text-white text-xs font-bold flex items-center justify-center">
                  {c.type === "group" ? <Users size={16} /> : initials(c.name)}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-baseline justify-between gap-2">
                    <span className={cn("text-sm truncate", unread ? "font-bold text-neutral-950" : "font-semibold text-neutral-900")}>{c.name}</span>
                    <span className="text-[10px] text-neutral-400 shrink-0">{relativeTime(lm?.created_at ?? c.updated_at)}</span>
                  </span>
                  <span className="flex items-center justify-between gap-2 mt-0.5">
                    <span className={cn("text-xs truncate", unread ? "text-neutral-800" : "text-neutral-500")}>{preview}</span>
                    {unread && (
                      <span data-testid="chat-unread" className="shrink-0 min-w-[18px] h-[18px] px-1 rounded-sm bg-neutral-900 text-white text-[10px] font-bold flex items-center justify-center">
                        {c.unread_count > 99 ? "99+" : c.unread_count}
                      </span>
                    )}
                  </span>
                </span>
              </button>
            );
          })}
        </div>
      </aside>

      {/* Thread */}
      <div className={cn("flex-1 min-w-0 min-h-0", active ? "flex flex-col" : "hidden md:flex md:flex-col")}>
        {active ? (
          <ChatThread
            key={active.id}
            conversation={active}
            meId={user.id}
            isAdmin={isAdmin}
            onBack={() => setActiveId(null)}
            onManage={() => setManageOpen(true)}
            onActivity={onActivity}
          />
        ) : (
          <div className="flex-1 flex flex-col items-center justify-center text-center text-neutral-400 gap-2 p-6" data-testid="chat-placeholder">
            <MessageSquarePlus size={28} strokeWidth={1.5} />
            <div className="text-sm">Select a conversation or start a new chat.</div>
          </div>
        )}
      </div>

      <NewChatDialog open={newChatOpen} onOpenChange={setNewChatOpen} onOpened={openConv} />
      {isAdmin && (
        <>
          <GroupDialog open={newGroupOpen} onOpenChange={setNewGroupOpen} meId={user.id} onSaved={openConv} />
          <GroupDialog
            open={manageOpen}
            onOpenChange={setManageOpen}
            group={active?.type === "group" ? active : null}
            meId={user.id}
            onSaved={upsert}
            onDeleted={(id) => {
              setConvs((cur) => cur.filter((c) => c.id !== id));
              setActiveId(null);
              void refreshUnread();
            }}
          />
        </>
      )}
    </div>
  );
}
