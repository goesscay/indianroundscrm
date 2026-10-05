"use client";

import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { ArrowUp, Clock, ChevronLeft, Plus, Sparkles, X } from "lucide-react";
import { useAuth } from "@/context/AuthContext";
import { cn } from "@/lib/utils";

interface Msg { id: number; role: "user" | "assistant"; text: string }
interface Chat { id: number; title: string; messages: Msg[] }

const SUGGESTIONS = [
  "What should I follow up on today?",
  "How are leads doing this month?",
  "Which supplier quotes are overdue?",
  "Add a note to a lead",
];
const PLACEHOLDER_REPLY =
  "The AI assistant isn't connected yet — this is a preview of the layout. Your message wasn't sent anywhere.";
const STORE_KEY = "ir-ai-chats";

function loadChats(): Chat[] {
  try {
    const raw = sessionStorage.getItem(STORE_KEY);
    const v = raw ? (JSON.parse(raw) as Chat[]) : [];
    return Array.isArray(v) ? v : [];
  } catch {
    return [];
  }
}
function saveChats(c: Chat[]) {
  try { sessionStorage.setItem(STORE_KEY, JSON.stringify(c.slice(0, 20))); } catch { /* ignore */ }
}

function Avatar() {
  return (
    <span className="mt-0.5 w-6 h-6 shrink-0 rounded-full border border-border bg-secondary text-foreground flex items-center justify-center">
      <Sparkles size={12} />
    </span>
  );
}

function TypingIndicator() {
  return (
    <div className="flex gap-2.5" data-testid="ai-typing" aria-label="Assistant is typing">
      <Avatar />
      <div className="rounded-lg border border-border bg-card px-3 py-3 flex gap-1">
        {[0, 1, 2].map((i) => (
          <span key={i} className="w-1.5 h-1.5 rounded-full bg-muted-foreground animate-bounce" style={{ animationDelay: `${i * 150}ms` }} />
        ))}
      </div>
    </div>
  );
}

function MessageBubble({ m }: { m: Msg }) {
  if (m.role === "user") {
    return (
      <div className="flex justify-end">
        <div className="max-w-[85%] rounded-lg bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900 px-3.5 py-2 text-sm whitespace-pre-wrap break-words">{m.text}</div>
      </div>
    );
  }
  return (
    <div className="flex gap-2.5">
      <Avatar />
      <div className="max-w-[85%] rounded-lg border border-border bg-card px-3.5 py-2 text-sm leading-relaxed">{m.text}</div>
    </div>
  );
}

export default function AiPanel({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { user } = useAuth();
  const firstName = (user?.name || user?.email || "there").split(/[\s@]+/)[0];

  const [mounted, setMounted] = useState(false); // keeps DOM during exit animation
  const [visible, setVisible] = useState(false);
  const [view, setView] = useState<"chat" | "history">("chat");
  const [messages, setMessages] = useState<Msg[]>([]);
  const [chatId, setChatId] = useState(() => Math.floor(performance.timeOrigin));
  const [chats, setChats] = useState<Chat[]>([]);
  const [typing, setTyping] = useState(false);
  const [draft, setDraft] = useState("");
  const panelRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const returnFocus = useRef<HTMLElement | null>(null);
  const timers = useRef<ReturnType<typeof setTimeout>[]>([]);
  const nextId = useRef(1);
  const idSeq = useRef(Math.floor(performance.timeOrigin) + 1);
  const chatIdRef = useRef(chatId);
  useEffect(() => { chatIdRef.current = chatId; }, [chatId]);

  useEffect(() => { setChats(loadChats()); }, []);

  // Mount/unmount with animation.
  useEffect(() => {
    if (open) {
      returnFocus.current = document.activeElement as HTMLElement | null;
      setMounted(true);
      const r = requestAnimationFrame(() => requestAnimationFrame(() => setVisible(true)));
      return () => cancelAnimationFrame(r);
    }
    setVisible(false);
    const t = setTimeout(() => setMounted(false), 220);
    returnFocus.current?.focus?.();
    return () => clearTimeout(t);
  }, [open]);

  useEffect(() => {
    if (open && visible && view === "chat") inputRef.current?.focus();
  }, [open, visible, view]);

  useEffect(() => () => timers.current.forEach(clearTimeout), []);

  // Esc closes; Tab stays inside the panel.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") { e.stopPropagation(); onClose(); return; }
      if (e.key !== "Tab" || !panelRef.current) return;
      const f = panelRef.current.querySelectorAll<HTMLElement>("button:not(:disabled), textarea, [href]");
      if (f.length === 0) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      else if (!panelRef.current.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  // Auto-scroll.
  useLayoutEffect(() => {
    const el = scrollRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages, typing, view, mounted]);

  // Auto-grow textarea.
  useLayoutEffect(() => {
    const el = inputRef.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.min(el.scrollHeight, 140)}px`;
  }, [draft, view, mounted]);

  const archive = useCallback((msgs: Msg[], id: number) => {
    if (msgs.length === 0) return;
    setChats((prev) => {
      const title = msgs.find((m) => m.role === "user")?.text.slice(0, 60) || "Chat";
      const next = [{ id, title, messages: msgs }, ...prev.filter((c) => c.id !== id)];
      saveChats(next);
      return next;
    });
  }, []);

  const send = (text: string) => {
    const t = text.trim();
    if (!t) return;
    const id = chatId;
    const userMsg: Msg = { id: nextId.current++, role: "user", text: t };
    const afterUser = [...messages, userMsg];
    setMessages(afterUser);
    archive(afterUser, id);
    setDraft("");
    setTyping(true);
    timers.current.push(setTimeout(() => {
      if (chatIdRef.current !== id) return;
      const reply: Msg = { id: nextId.current++, role: "assistant", text: PLACEHOLDER_REPLY };
      const all = [...afterUser, reply];
      setMessages(all);
      archive(all, id);
      setTyping(false);
    }, 900));
  };

  const resetTimers = () => {
    timers.current.forEach(clearTimeout);
    timers.current = [];
    setTyping(false);
  };

  const newChat = () => {
    resetTimers();
    setMessages([]);
    setChatId(idSeq.current++);
    setDraft("");
    setView("chat");
  };

  const openChat = (c: Chat) => {
    resetTimers();
    setMessages(c.messages);
    setChatId(c.id);
    setView("chat");
  };

  if (!mounted) return null;

  const iconBtn = "p-2 rounded text-muted-foreground hover:text-foreground hover:bg-accent";

  return (
    <div className="fixed inset-0 z-[60]">
      <div
        className={cn("absolute inset-0 bg-black/40 backdrop-blur-[2px] transition-opacity duration-200", visible ? "opacity-100" : "opacity-0")}
        onClick={onClose} aria-hidden="true"
      />
      <div
        ref={panelRef} role="dialog" aria-modal="true" aria-labelledby="ai-panel-title" data-testid="ai-panel"
        className={cn(
          "absolute right-0 top-0 h-full w-full sm:w-[420px] flex flex-col bg-background text-foreground border-l border-border shadow-2xl transition-transform duration-200 ease-out",
          visible ? "translate-x-0" : "translate-x-full"
        )}
      >
        <div className="flex items-start gap-3 px-4 py-3 border-b border-border">
          {view === "history" ? (
            <button onClick={() => setView("chat")} className={cn(iconBtn, "-ml-1")} aria-label="Back to chat" data-testid="ai-back">
              <ChevronLeft size={18} />
            </button>
          ) : (
            <span className="mt-0.5 w-8 h-8 shrink-0 rounded-md bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900 flex items-center justify-center">
              <Sparkles size={16} />
            </span>
          )}
          <div className="min-w-0 flex-1">
            <h2 id="ai-panel-title" className="font-display text-[15px] leading-tight">{view === "history" ? "Chat history" : "Rounds AI"}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{view === "history" ? "This browser session" : "Ask a question, or ask for a change."}</p>
          </div>
          <div className="flex items-center">
            <button onClick={newChat} className={iconBtn} title="New chat" aria-label="New chat" data-testid="ai-new-chat"><Plus size={16} /></button>
            <button onClick={() => setView(view === "history" ? "chat" : "history")} className={iconBtn} title="History" aria-label="History" data-testid="ai-history-btn"><Clock size={16} /></button>
            <button onClick={onClose} className={iconBtn} title="Close" aria-label="Close" data-testid="ai-close"><X size={16} /></button>
          </div>
        </div>

        {view === "history" ? (
          <div ref={scrollRef} className="flex-1 overflow-y-auto p-3" data-testid="ai-history">
            {chats.length === 0 ? (
              <div className="text-sm text-muted-foreground text-center py-16">No previous chats yet</div>
            ) : (
              <ul className="space-y-1.5">
                {chats.map((c) => (
                  <li key={c.id}>
                    <button onClick={() => openChat(c)} className="w-full text-left rounded-md border border-border px-3 py-2.5 hover:bg-accent">
                      <div className="text-sm font-medium truncate">{c.title}</div>
                      <div className="text-xs text-muted-foreground">{c.messages.length} messages</div>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        ) : (
          <>
            <div ref={scrollRef} className="flex-1 overflow-y-auto px-4 py-4 space-y-4" data-testid="ai-thread">
              <div className="flex gap-2.5">
                <Avatar />
                <div className="text-sm leading-relaxed">
                  Hi {firstName}. I can look things up across your workspace and, soon, add or update things for you. I&apos;ll always show you a change to confirm before anything is saved.
                </div>
              </div>
              {messages.length === 0 && (
                <div className="flex flex-col items-stretch gap-2 pl-8">
                  {SUGGESTIONS.map((s, i) => (
                    <button key={s} onClick={() => send(s)} data-testid={`ai-suggestion-${i}`}
                            className="text-left text-[13px] rounded-md border border-border px-3 py-2 hover:bg-accent transition-colors">
                      {s}
                    </button>
                  ))}
                </div>
              )}
              {messages.map((m) => <MessageBubble key={m.id} m={m} />)}
              {typing && <TypingIndicator />}
            </div>

            <div className="border-t border-border p-3">
              <div className="flex items-end gap-2 rounded-lg border border-input bg-card px-2.5 py-2 focus-within:ring-1 focus-within:ring-ring">
                <textarea
                  ref={inputRef} rows={1} value={draft} data-testid="ai-input"
                  onChange={(e) => setDraft(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); send(draft); }
                  }}
                  placeholder="Ask about your business" aria-label="Message Rounds AI"
                  className="flex-1 resize-none bg-transparent text-sm outline-none max-h-[140px] py-1 placeholder:text-muted-foreground"
                />
                <button onClick={() => send(draft)} disabled={!draft.trim()} data-testid="ai-send" aria-label="Send"
                        className="w-8 h-8 shrink-0 rounded-md bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900 flex items-center justify-center disabled:opacity-30 disabled:cursor-not-allowed">
                  <ArrowUp size={16} />
                </button>
              </div>
              <p className="text-[11px] text-muted-foreground mt-2 text-center">AI answers can be wrong. Check anything important.</p>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
