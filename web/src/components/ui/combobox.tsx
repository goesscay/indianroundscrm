"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { Check, ChevronDown, X } from "lucide-react";
import { cn } from "@/lib/utils";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";

export interface ComboOption { value: string; label?: string; hint?: string }

interface Props {
  options: (string | ComboOption)[];
  value?: string | null;
  onChange: (value: string) => void;
  placeholder?: string;
  searchPlaceholder?: string;
  /** Offer a first "clear" row (e.g. "All sources") that sets the value to `clearValue`. */
  clearLabel?: string;
  clearValue?: string;
  disabled?: boolean;
  className?: string;
  testId?: string;
  /** Allow typing a value that is not in the list. */
  allowCustom?: boolean;
}

const norm = (o: string | ComboOption): ComboOption => (typeof o === "string" ? { value: o } : o);

/**
 * Searchable single-select. Replaces long dropdowns: the list is capped in height, scrolls inside the popover
 * and is filtered as you type, so it never runs off the screen. Keyboard: type, arrows, Enter, Escape.
 */
export function Combobox({ options, value, onChange, placeholder = "Select…", searchPlaceholder = "Type to search…", clearLabel, clearValue = "all", disabled, className, testId, allowCustom }: Props) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState("");
  const [active, setActive] = useState(0);
  const listRef = useRef<HTMLUListElement>(null);

  const all = useMemo(() => options.map(norm), [options]);
  const items = useMemo(() => {
    const s = q.trim().toLowerCase();
    const f = s ? all.filter((o) => `${o.label ?? o.value} ${o.hint ?? ""}`.toLowerCase().includes(s)) : all;
    const rows: ComboOption[] = [];
    if (clearLabel && !s) rows.push({ value: clearValue, label: clearLabel });
    rows.push(...f);
    if (allowCustom && s && !all.some((o) => o.value.toLowerCase() === s)) rows.push({ value: q.trim(), label: `Use “${q.trim()}”` });
    return rows;
  }, [all, q, clearLabel, clearValue, allowCustom]);

  const current = all.find((o) => o.value === value);
  const shown = value && value !== clearValue ? (current?.label ?? current?.value ?? value) : null;

  useEffect(() => { if (open) { setQ(""); setActive(Math.max(0, items.findIndex((o) => o.value === value))); } }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { setActive(0); }, [q]);
  useEffect(() => { listRef.current?.querySelector<HTMLElement>(`[data-idx="${active}"]`)?.scrollIntoView({ block: "nearest" }); }, [active]);

  const pick = (o: ComboOption) => { onChange(o.value); setOpen(false); };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button type="button" disabled={disabled} data-testid={testId} aria-haspopup="listbox" aria-expanded={open}
                className={cn("flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 text-left text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50", className)}>
          <span className={cn("truncate", !shown && "text-muted-foreground")}>{shown ?? placeholder}</span>
          <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" collisionPadding={12} className="w-[var(--radix-popover-trigger-width)] min-w-[220px] max-w-[min(92vw,380px)] p-0">
        <div className="flex items-center gap-1 border-b border-border px-2">
          <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder={searchPlaceholder} aria-label="Search" data-testid={testId ? `${testId}-search` : undefined}
                 className="h-9 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                 onKeyDown={(e) => {
                   if (e.key === "ArrowDown") { e.preventDefault(); setActive((a) => Math.min(items.length - 1, a + 1)); }
                   else if (e.key === "ArrowUp") { e.preventDefault(); setActive((a) => Math.max(0, a - 1)); }
                   else if (e.key === "Enter") { e.preventDefault(); if (items[active]) pick(items[active]); }
                   else if (e.key === "Escape") setOpen(false);
                 }} />
          {q && <button type="button" aria-label="Clear search" onClick={() => setQ("")} className="rounded p-1 text-muted-foreground hover:text-foreground"><X size={13} /></button>}
        </div>
        <ul ref={listRef} role="listbox" className="max-h-60 overflow-y-auto overscroll-contain p-1">
          {items.length === 0 && <li className="px-3 py-4 text-center text-sm text-muted-foreground">No match</li>}
          {items.map((o, i) => (
            <li key={`${o.value}-${i}`} role="option" aria-selected={o.value === value} data-idx={i}>
              <button type="button" onMouseEnter={() => setActive(i)} onClick={() => pick(o)} data-testid={testId ? `${testId}-opt-${o.value}` : undefined}
                      className={cn("flex w-full items-center justify-between gap-2 rounded px-2.5 py-1.5 text-left text-sm", i === active ? "bg-accent text-accent-foreground" : "hover:bg-accent/60")}>
                <span className="min-w-0"><span className="block truncate">{o.label ?? o.value}</span>{o.hint && <span className="block truncate text-xs text-muted-foreground">{o.hint}</span>}</span>
                {o.value === value && <Check size={14} className="shrink-0" />}
              </button>
            </li>
          ))}
        </ul>
      </PopoverContent>
    </Popover>
  );
}
