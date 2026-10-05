"use client";

import { useChatRealtime } from "@/context/ChatRealtimeContext";
import { cn } from "@/lib/utils";

/** Tiny live/reconnecting indicator. Renders nothing when realtime isn't configured. */
export default function LiveDot({ className }: { className?: string }) {
  const { enabled, connected } = useChatRealtime();
  if (!enabled) return null;
  const title = connected ? "Live: messages arrive instantly" : "Reconnecting: updates may be delayed";
  return (
    <span
      title={title}
      role="status"
      aria-label={title}
      data-testid="chat-live-dot"
      data-live={connected ? "true" : "false"}
      className={cn("inline-block w-1.5 h-1.5 rounded-full shrink-0", connected ? "bg-emerald-500" : "bg-amber-500", className)}
    />
  );
}
