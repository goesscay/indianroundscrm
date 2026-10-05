"use client";

import { useState } from "react";
import { Check, Monitor, Moon, Sun, type LucideIcon } from "lucide-react";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useTheme, type ThemePreference } from "@/context/ThemeContext";
import { cn } from "@/lib/utils";

const OPTIONS: { value: ThemePreference; label: string; icon: LucideIcon; testId: string }[] = [
  { value: "light", label: "Light", icon: Sun, testId: "theme-light" },
  { value: "dark", label: "Dark", icon: Moon, testId: "theme-dark" },
  { value: "system", label: "Match my device", icon: Monitor, testId: "theme-system" },
];

export default function ThemeMenu({ className }: { className?: string }) {
  const { preference, resolved, setPreference } = useTheme();
  const [open, setOpen] = useState(false);
  const Icon = resolved === "dark" ? Moon : Sun;
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          className={cn("p-1.5 rounded text-muted-foreground hover:text-foreground hover:bg-accent", className)}
          data-testid="theme-btn" title="Theme" aria-label="Change theme"
        >
          <Icon size={16} />
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={8} className="w-48 p-1.5" data-testid="theme-menu">
        <div role="radiogroup" aria-label="Theme">
          {OPTIONS.map((o) => (
            <button
              key={o.value} role="radio" aria-checked={preference === o.value} data-testid={o.testId}
              onClick={() => { setPreference(o.value); setOpen(false); }}
              className="w-full flex items-center gap-2.5 px-2.5 py-2 rounded text-[13px] font-medium text-foreground hover:bg-accent"
            >
              <o.icon size={15} className="text-muted-foreground" />
              <span className="flex-1 text-left">{o.label}</span>
              {preference === o.value && <Check size={14} />}
            </button>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  );
}
