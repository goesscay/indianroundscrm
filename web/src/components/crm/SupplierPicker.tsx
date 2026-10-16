"use client";

import { useMemo, useState } from "react";
import { Search, Users } from "lucide-react";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import type { Supplier, SupplierGroup } from "@/types";

export type MailRole = "to" | "cc" | "bcc";
/** What was ticked: "g:<groupId>" or "s:<supplierId>" -> how it is sent. */
export type Picks = Record<string, MailRole>;

const ORDER: MailRole[] = ["to", "cc", "bcc"];
const LABEL: Record<MailRole, string> = { to: "To", cc: "CC", bcc: "BCC" };

/**
 * Every supplier that ends up in each line. A group applies its role to all its members; a supplier picked on its own overrides that;
 * if two groups disagree, To beats CC beats BCC.
 */
export function resolvePicks(picks: Picks, groups: SupplierGroup[]): Record<MailRole, string[]> {
  const rank = (r: MailRole) => ORDER.indexOf(r);
  const by = new Map<string, MailRole>();
  groups.forEach((g) => {
    const r = picks[`g:${g.id}`];
    if (!r) return;
    g.supplier_ids.forEach((id) => { const cur = by.get(id); if (!cur || rank(r) < rank(cur)) by.set(id, r); });
  });
  Object.entries(picks).forEach(([k, r]) => { if (k.startsWith("s:")) by.set(k.slice(2), r); });
  const out: Record<MailRole, string[]> = { to: [], cc: [], bcc: [] };
  by.forEach((r, id) => out[r].push(id));
  return out;
}

function Segments({ value, onPick, testId }: { value: MailRole | undefined; onPick: (r: MailRole | undefined) => void; testId: string }) {
  return (
    <div className="inline-flex shrink-0 overflow-hidden rounded-md border border-border text-[11px] font-semibold" role="group" aria-label="Send as">
      {ORDER.map((r) => (
        <button key={r} type="button" aria-pressed={value === r} onClick={() => onPick(value === r ? undefined : r)} data-testid={`${testId}-${r}`}
                className={cn("px-2 py-1 transition-colors", value === r ? "bg-foreground text-background" : "bg-card text-muted-foreground hover:bg-secondary hover:text-foreground", r !== "to" && "border-l border-border")}>
          {LABEL[r]}
        </button>
      ))}
    </div>
  );
}

/** One compact list for the whole mail: groups first, then single suppliers, each with To / CC / BCC buttons. */
export default function SupplierPicker({ suppliers, groups, picks, onChange, destination }: {
  suppliers: Supplier[]; groups: SupplierGroup[]; picks: Picks; onChange: (next: Picks) => void; destination?: string;
}) {
  const [q, setQ] = useState("");
  const dest = (destination ?? "").trim().toLowerCase();
  const query = q.trim().toLowerCase();
  const byId = useMemo(() => new Map(suppliers.map((s) => [s.id, s])), [suppliers]);

  const set = (key: string, role: MailRole | undefined) => {
    const next = { ...picks };
    if (role) next[key] = role; else delete next[key];
    onChange(next);
  };

  const shownGroups = useMemo(() => groups
    .filter((g) => !query || g.name.toLowerCase().includes(query) || (g.destination ?? "").toLowerCase().includes(query))
    .sort((a, b) => Number((b.destination ?? "").toLowerCase() === dest && !!dest) - Number((a.destination ?? "").toLowerCase() === dest && !!dest) || a.name.localeCompare(b.name)),
  [groups, query, dest]);
  const shownSuppliers = useMemo(() => suppliers
    .filter((s) => !query || s.name.toLowerCase().includes(query) || (s.email ?? "").toLowerCase().includes(query) || (s.destination ?? "").toLowerCase().includes(query))
    .sort((a, b) => Number((b.destination ?? "").toLowerCase() === dest && !!dest) - Number((a.destination ?? "").toLowerCase() === dest && !!dest) || a.name.localeCompare(b.name)),
  [suppliers, query, dest]);

  const resolved = useMemo(() => resolvePicks(picks, groups), [picks, groups]);
  const names = (ids: string[]) => ids.map((id) => byId.get(id)?.name).filter(Boolean).join(", ");

  return (
    <div data-testid="supplier-picker">
      <div className="relative">
        <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search groups or suppliers" className="h-8 pl-8 text-[13px]" data-testid="supplier-search" />
      </div>
      <div className="mt-1.5 max-h-52 overflow-y-auto rounded-md border border-border" data-testid="supplier-list">
        {suppliers.length === 0 && groups.length === 0 && <div className="p-3 text-sm text-muted-foreground">No suppliers. Add some in Suppliers page.</div>}
        {shownGroups.length > 0 && <div className="sticky top-0 z-10 bg-secondary px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Groups</div>}
        {shownGroups.map((g) => {
          const role = picks[`g:${g.id}`];
          const match = !!dest && (g.destination ?? "").toLowerCase() === dest;
          return (
            <div key={g.id} className={cn("flex items-center gap-2 border-b border-border/60 px-2.5 py-1.5 last:border-b-0", role && "bg-sky-50 dark:bg-sky-950/30")} data-testid={`group-row-${g.id}`}>
              <Users size={14} className="shrink-0 text-muted-foreground" />
              <div className="min-w-0 flex-1" title={names(g.supplier_ids)}>
                <div className="truncate text-[13px] font-medium leading-tight">{g.name}{match && <span className="ml-1.5 rounded bg-emerald-100 px-1 py-px text-[9px] font-bold uppercase text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">Matches lead</span>}</div>
                <div className="truncate text-[11px] text-muted-foreground">{g.supplier_ids.length} supplier{g.supplier_ids.length === 1 ? "" : "s"}{g.destination ? ` · ${g.destination}` : ""}</div>
              </div>
              <Segments value={role} onPick={(r) => set(`g:${g.id}`, r)} testId={`group-${g.id}`} />
            </div>
          );
        })}
        {shownSuppliers.length > 0 && <div className="sticky top-0 z-10 bg-secondary px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Suppliers</div>}
        {shownSuppliers.map((s) => {
          const role = picks[`s:${s.id}`];
          const via = !role ? resolved.to.includes(s.id) ? "to" : resolved.cc.includes(s.id) ? "cc" : resolved.bcc.includes(s.id) ? "bcc" : undefined : undefined;
          return (
            <div key={s.id} className={cn("flex items-center gap-2 border-b border-border/60 px-2.5 py-1.5 last:border-b-0", (role || via) && "bg-sky-50 dark:bg-sky-950/30")} data-testid={`supplier-${s.id}`}>
              <div className="min-w-0 flex-1">
                <div className="truncate text-[13px] font-medium leading-tight">{s.name}{s.portal_enabled && <span className="ml-1.5 rounded bg-sky-100 px-1 py-px text-[9px] font-bold uppercase text-sky-800 dark:bg-sky-950/60 dark:text-sky-300" title="Gets a short link e-mail and quotes in the portal">Portal</span>}
                  {via && <span className="ml-1.5 text-[10px] font-semibold text-muted-foreground">via group · {LABEL[via]}</span>}</div>
                <div className="truncate text-[11px] text-muted-foreground">{s.email}{s.destination ? ` · ${s.destination}` : ""}</div>
              </div>
              <Segments value={role} onPick={(r) => set(`s:${s.id}`, r)} testId={`supplier-pick-${s.id}`} />
            </div>
          );
        })}
        {(query && shownGroups.length === 0 && shownSuppliers.length === 0) && <div className="p-3 text-sm text-muted-foreground">Nothing matches “{q}”.</div>}
      </div>
      <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-muted-foreground" data-testid="picker-summary">
        {ORDER.map((r) => (
          <span key={r} title={names(resolved[r])}><b className="text-foreground">{LABEL[r]}</b> {resolved[r].length} supplier{resolved[r].length === 1 ? "" : "s"}</span>
        ))}
      </div>
    </div>
  );
}
