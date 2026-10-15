"use client";

import { useEffect, useMemo, useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import PlacesField from "@/components/app/PlacesField";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { AssignableUser } from "@/types";

/**
 * Give sales people (or take away) packages in one go: the packages ticked in the list, or every package of some destinations.
 * Operations and Admin always see every package, so they are not in the list of people.
 */
export default function PackageAssignDialog({ open, onOpenChange, selectedIds, destinations, onDone }: {
  open: boolean; onOpenChange: (o: boolean) => void; selectedIds: string[]; destinations: string[]; onDone: () => void;
}) {
  const [people, setPeople] = useState<AssignableUser[] | null>(null);
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [by, setBy] = useState<"selected" | "destinations">("selected");
  const [dests, setDests] = useState<string[]>([]);
  const [mode, setMode] = useState<"add" | "remove">("add");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!open) return;
    setPicked(new Set()); setDests([]); setMode("add"); setBy(selectedIds.length ? "selected" : "destinations");
    api.get<AssignableUser[]>("/packages/assignable-users").then((r) => setPeople(r.data)).catch((e) => { toast.error(formatApiError(e)); setPeople([]); });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const theirDestinations = useMemo(() => {
    const out: string[] = [];
    (people ?? []).filter((p) => picked.has(p.id)).forEach((p) => p.destinations.forEach((d) => { if (!out.some((o) => o.toLowerCase() === d.toLowerCase())) out.push(d); }));
    return out;
  }, [people, picked]);

  const toggle = (id: string) => setPicked((p) => { const n = new Set(p); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  const ready = picked.size > 0 && (by === "selected" ? selectedIds.length > 0 : dests.length > 0);

  const submit = async () => {
    setBusy(true);
    try {
      const { data } = await api.post<{ packages: number; users: number; changed: number }>("/packages/assign", {
        user_ids: Array.from(picked), mode, ...(by === "selected" ? { package_ids: selectedIds } : { destinations: dests }),
      });
      toast.success(mode === "add"
        ? `${data.changed} package${data.changed === 1 ? "" : "s"} newly shown to ${data.users} ${data.users === 1 ? "person" : "people"} (${data.packages} package${data.packages === 1 ? "" : "s"} in all)`
        : `Removed ${data.changed} assignment${data.changed === 1 ? "" : "s"}`);
      onDone(); onOpenChange(false);
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
      <DialogContent className="max-h-[92vh] max-w-xl overflow-y-auto" data-testid="assign-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">Who sees which packages</DialogTitle>
          <DialogDescription>Sales people see only the packages assigned to them. Operations and Admin always see every package.</DialogDescription>
        </DialogHeader>

        <div className="space-y-5">
          <div>
            <Label className="label-caps">1. People</Label>
            {people === null ? <div className="mt-2 flex items-center gap-2 text-sm text-muted-foreground"><Loader2 size={14} className="animate-spin" /> Loading…</div>
              : people.length === 0 ? <p className="mt-2 text-sm text-muted-foreground">There are no sales people to assign to.</p>
              : (
                <ul className="mt-2 max-h-48 divide-y divide-border overflow-y-auto rounded-md border border-border" data-testid="assign-people">
                  {people.map((p) => (
                    <li key={p.id}>
                      <label className="flex cursor-pointer items-start gap-2 px-3 py-2 text-sm hover:bg-secondary/50">
                        <Checkbox checked={picked.has(p.id)} onCheckedChange={() => toggle(p.id)} data-testid={`assign-person-${p.id}`} />
                        <span className="min-w-0">
                          <span className="font-medium">{p.name}{p.code ? <span className="ml-1.5 font-mono text-xs text-muted-foreground">{p.code}</span> : null}</span>
                          <span className="block truncate text-xs text-muted-foreground">{p.destinations.length ? p.destinations.join(", ") : "No destinations set"}</span>
                        </span>
                      </label>
                    </li>
                  ))}
                </ul>
              )}
          </div>

          <div>
            <Label className="label-caps">2. Packages</Label>
            <div className="mt-2 space-y-2 text-sm">
              <label className="flex items-center gap-2"><input type="radio" name="assign-by" checked={by === "selected"} onChange={() => setBy("selected")} disabled={selectedIds.length === 0} />
                <span className={selectedIds.length === 0 ? "text-muted-foreground" : ""}>The {selectedIds.length} package{selectedIds.length === 1 ? "" : "s"} ticked in the list</span></label>
              <label className="flex items-center gap-2"><input type="radio" name="assign-by" checked={by === "destinations"} onChange={() => setBy("destinations")} data-testid="assign-by-destinations" /> Every package of these destinations</label>
              {by === "destinations" && (
                <div className="space-y-2 pl-6">
                  <PlacesField value={dests} onChange={setDests} options={destinations} testId="assign-destinations" placeholder="Add a destination…" />
                  {theirDestinations.length > 0 && <Button type="button" size="sm" variant="outline" onClick={() => setDests(theirDestinations)} data-testid="assign-use-theirs">Use the chosen people&apos;s own destinations ({theirDestinations.join(", ")})</Button>}
                </div>
              )}
            </div>
          </div>

          <div>
            <Label className="label-caps">3. Do</Label>
            <div className="mt-2 flex gap-4 text-sm">
              <label className="flex items-center gap-2"><input type="radio" name="assign-mode" checked={mode === "add"} onChange={() => setMode("add")} /> Show these packages to them</label>
              <label className="flex items-center gap-2"><input type="radio" name="assign-mode" checked={mode === "remove"} onChange={() => setMode("remove")} /> Hide them from them</label>
            </div>
          </div>
        </div>

        <DialogFooter className="gap-2 sm:gap-2">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>Cancel</Button>
          <Button type="button" onClick={submit} disabled={busy || !ready} data-testid="assign-submit">{busy ? "Saving…" : mode === "add" ? "Assign" : "Remove"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
