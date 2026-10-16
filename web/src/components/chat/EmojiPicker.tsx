"use client";

import { useState, type ReactNode } from "react";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { cn } from "@/lib/utils";
import { EMOJI_GROUPS, QUICK_REACTIONS, STICKERS, type Sticker } from "./emoji";

/** The smiley button next to the message box: emoji to drop into the text, and stickers to send on their own. */
export function ComposerPicker({ onEmoji, onSticker, children }: { onEmoji: (e: string) => void; onSticker: (s: Sticker) => void; children: ReactNode }) {
  const [open, setOpen] = useState(false);
  const [tab, setTab] = useState<"emoji" | "stickers">("emoji");
  const [group, setGroup] = useState(EMOJI_GROUPS[0].key);
  const g = EMOJI_GROUPS.find((x) => x.key === group) ?? EMOJI_GROUPS[0];
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>{children}</PopoverTrigger>
      <PopoverContent side="top" align="start" className="w-[300px] p-0" data-testid="chat-picker">
        <div className="flex border-b border-border text-xs font-medium" role="tablist">
          {(["emoji", "stickers"] as const).map((t) => (
            <button key={t} type="button" role="tab" aria-selected={tab === t} onClick={() => setTab(t)} data-testid={`picker-tab-${t}`}
                    className={cn("flex-1 px-3 py-2 capitalize", tab === t ? "border-b-2 border-foreground text-foreground" : "text-muted-foreground hover:text-foreground")}>{t}</button>
          ))}
        </div>
        {tab === "emoji" ? (
          <div>
            <div className="flex gap-0.5 border-b border-border px-1.5 py-1">
              {EMOJI_GROUPS.map((x) => (
                <button key={x.key} type="button" title={x.label} aria-label={x.label} onClick={() => setGroup(x.key)}
                        className={cn("rounded px-2 py-1 text-base", x.key === g.key ? "bg-secondary" : "hover:bg-secondary/60")}>{x.icon}</button>
              ))}
            </div>
            <div className="grid h-[200px] grid-cols-8 content-start gap-0.5 overflow-y-auto p-1.5" data-testid="emoji-grid">
              {g.emojis.map((em) => (
                <button key={em} type="button" onClick={() => onEmoji(em)} className="rounded p-1 text-xl leading-none hover:bg-secondary" data-testid={`emoji-${em}`}>{em}</button>
              ))}
            </div>
          </div>
        ) : (
          <div className="grid h-[244px] grid-cols-3 content-start gap-1.5 overflow-y-auto p-2" data-testid="sticker-grid">
            {STICKERS.map((s) => (
              <button key={s.id} type="button" onClick={() => { onSticker(s); setOpen(false); }} title={s.label} data-testid={`sticker-${s.id}`}
                      className={cn("flex flex-col items-center gap-0.5 rounded-lg px-1 py-2 hover:ring-2 hover:ring-foreground/40", s.tone)}>
                <span className="text-3xl leading-none">{s.emoji}</span>
                <span className="text-[10px] font-semibold leading-tight">{s.label}</span>
              </button>
            ))}
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
}

/** The little reaction bar that opens beside a message. */
export function ReactionPicker({ onPick, children }: { onPick: (emoji: string) => void; children: ReactNode }) {
  const [open, setOpen] = useState(false);
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>{children}</PopoverTrigger>
      <PopoverContent side="top" className="w-auto p-1" data-testid="reaction-picker">
        <div className="flex gap-0.5">
          {QUICK_REACTIONS.map((em) => (
            <button key={em} type="button" onClick={() => { onPick(em); setOpen(false); }} className="rounded p-1.5 text-xl leading-none hover:bg-secondary" data-testid={`react-${em}`}>{em}</button>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  );
}
