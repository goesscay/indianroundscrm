import type { ChatMessage, Conversation } from "@/types";

export function initials(name: string): string {
  return (name || "?").split(/[\s@]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase() || "?";
}

export function dayKey(iso: string | Date): string {
  const d = typeof iso === "string" ? new Date(iso) : iso;
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

export function dayLabel(iso: string): string {
  const d = new Date(iso);
  const now = new Date();
  const y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
  if (dayKey(d) === dayKey(now)) return "Today";
  if (dayKey(d) === dayKey(y)) return "Yesterday";
  return d.toLocaleDateString(undefined, {
    weekday: "short",
    day: "numeric",
    month: "short",
    year: d.getFullYear() === now.getFullYear() ? undefined : "numeric",
  });
}

export function timeLabel(iso: string): string {
  return new Date(iso).toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
}

/** Compact relative time for the conversation list. */
export function relativeTime(iso: string | undefined | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  const diff = Date.now() - d.getTime();
  if (diff < 60_000) return "now";
  if (diff < 3_600_000) return `${Math.floor(diff / 60_000)}m`;
  const now = new Date();
  if (dayKey(d) === dayKey(now)) return timeLabel(iso);
  const y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
  if (dayKey(d) === dayKey(y)) return "Yesterday";
  return d.toLocaleDateString(undefined, { day: "numeric", month: "short" });
}

/** Compare numeric-ish string ids. */
export function idGreater(a: string, b: string): boolean {
  const na = Number(a);
  const nb = Number(b);
  if (!Number.isNaN(na) && !Number.isNaN(nb)) return na > nb;
  return a > b;
}

function sortByRecent(list: Conversation[]): Conversation[] {
  return [...list].sort((a, b) => (b.updated_at || "").localeCompare(a.updated_at || ""));
}

/**
 * Apply an incoming message to a conversation list locally (no refetch).
 * `known` is false when the conversation isn't in the list (caller should refetch).
 */
export function applyIncomingMessage(
  list: Conversation[],
  convId: string,
  m: ChatMessage,
  meId: string,
  viewing: boolean
): { list: Conversation[]; known: boolean } {
  const cur = list.find((c) => c.id === convId);
  if (!cur) return { list, known: false };
  if (cur.last_message?.id === m.id) return { list, known: true }; // already applied
  const fromOther = m.sender_id !== meId;
  const next: Conversation = {
    ...cur,
    last_message: { id: m.id, body: m.body, sender_id: m.sender_id, sender_name: m.sender_name, created_at: m.created_at, deleted: m.deleted },
    updated_at: m.created_at,
    unread_count: fromOther && !viewing ? cur.unread_count + 1 : cur.unread_count,
  };
  return { list: sortByRecent([next, ...list.filter((c) => c.id !== convId)]), known: true };
}

export function applyDeletedMessage(list: Conversation[], convId: string, messageId: string): Conversation[] {
  const cur = list.find((c) => c.id === convId);
  if (!cur?.last_message || cur.last_message.id !== messageId || cur.last_message.deleted) return list;
  return list.map((c) => (c.id === convId && c.last_message ? { ...c, last_message: { ...c.last_message, deleted: true } } : c));
}
