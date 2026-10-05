"use client";

import { useMemo, useState } from "react";
import { Check, Search } from "lucide-react";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import type { ChatUser } from "@/types";
import { initials } from "./util";

interface Props {
  users: ChatUser[];
  selected?: string[];
  onToggle: (u: ChatUser) => void;
  multi?: boolean;
  loading?: boolean;
  testId?: string;
}

/** Searchable user list; single-click select (`multi` shows checkmarks). */
export default function UserPicker({ users, selected = [], onToggle, multi = false, loading = false, testId = "user-picker" }: Props) {
  const [q, setQ] = useState("");
  const list = useMemo(() => {
    const s = q.trim().toLowerCase();
    return s ? users.filter((u) => u.name.toLowerCase().includes(s)) : users;
  }, [users, q]);

  return (
    <div data-testid={testId}>
      <div className="relative mb-2">
        <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-neutral-400" />
        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search people" aria-label="Search people" className="pl-8" data-testid={`${testId}-search`} />
      </div>
      <div className="max-h-64 overflow-y-auto border border-border divide-y divide-border rounded">
        {loading && <div className="p-3 text-sm text-muted-foreground">Loading...</div>}
        {!loading && list.length === 0 && <div className="p-3 text-sm text-muted-foreground">No people found</div>}
        {list.map((u) => {
          const on = selected.includes(u.id);
          return (
            <button
              key={u.id}
              type="button"
              onClick={() => onToggle(u)}
              aria-pressed={multi ? on : undefined}
              data-testid={`${testId}-user-${u.id}`}
              className={cn("w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-neutral-50", on && "bg-neutral-50")}
            >
              <span className="w-8 h-8 shrink-0 rounded bg-neutral-900 text-white text-[11px] font-bold flex items-center justify-center">{initials(u.name)}</span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium text-neutral-900 truncate">{u.name}</span>
                <span className="block text-[10px] uppercase tracking-widest text-neutral-400">{u.role}</span>
              </span>
              {multi && on && <Check size={16} className="text-neutral-900" />}
            </button>
          );
        })}
      </div>
    </div>
  );
}
