"use client";

import { LoadMore, useLoadMore } from "@/components/ui/pagination";
import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Textarea } from "@/components/ui/textarea";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { StickyNote, Trash2 } from "lucide-react";
import { useCurrentUser } from "@/context/AuthContext";
import type { LeadNote } from "@/types";

/** `embedded` drops the card chrome and heading so it can sit inside a tab; `onCount` reports how many notes there are. */
export default function LeadNotes({ leadId, embedded, onCount }: { leadId: string; embedded?: boolean; onCount?: (n: number) => void }) {
  const { user, can } = useCurrentUser();
  const [notes, setNotes] = useState<LeadNote[]>([]);
  const lm = useLoadMore(notes, 10);
  const [txt, setTxt] = useState("");
  const [busy, setBusy] = useState(false);

  const load = async () => {
    try { const { data } = await api.get<LeadNote[]>(`/leads/${leadId}/notes`); setNotes(data); onCount?.(data.length); }
    catch (e) { toast.error(formatApiError(e)); }
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, [leadId]);

  const add = async () => {
    if (!txt.trim()) return;
    setBusy(true);
    try {
      await api.post(`/leads/${leadId}/notes`, { note: txt });
      setTxt(""); load();
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const remove = async (nid: string) => {
    if (!window.confirm("Delete this note?")) return;
    try { await api.delete(`/notes/${nid}`); load(); }
    catch (e) { toast.error(formatApiError(e)); }
  };

  return (
    <Wrap embedded={embedded}>
      {!embedded && (
        <div className="flex items-center gap-2 mb-3">
          <StickyNote size={16} className="text-[#0A0A0A] dark:text-foreground" />
          <h3 className="font-display text-base font-bold">Notes</h3>
          <span className="label-caps ml-auto">{notes.length} note{notes.length !== 1 ? "s" : ""}</span>
        </div>
      )}
      <div className="flex gap-2 mb-3">
        <Textarea rows={2} className="min-h-0 text-sm" value={txt} onChange={(e) => setTxt(e.target.value)}
          placeholder="Add a quick note — customer said..." data-testid="note-input" />
        <Button onClick={add} disabled={busy || !txt.trim()} data-testid="note-add">Add</Button>
      </div>
      <div className="space-y-2 max-h-72 overflow-y-auto">
        {notes.length === 0 && <div className="text-sm text-muted-foreground">No notes yet. Add one above.</div>}
        {lm.visible.map((n) => (
          <div key={n.id} className="p-2.5 rounded-md border border-border bg-secondary/30 group" data-testid={`note-${n.id}`}>
            <div className="flex justify-between items-start gap-2">
              <div className="text-sm whitespace-pre-wrap flex-1">{n.note}</div>
              {(can("leads.delete") || n.created_by_id === user.id) && (
                <button onClick={() => remove(n.id)} className="opacity-0 group-hover:opacity-100 text-muted-foreground hover:text-destructive transition-opacity">
                  <Trash2 size={13} />
                </button>
              )}
            </div>
            <div className="label-caps mt-1.5">
              {n.created_by} · {(n.created_at || "").slice(0, 16).replace("T", " ")}
            </div>
          </div>
        ))}
        <LoadMore lm={lm} />
      </div>
    </Wrap>
  );
}

function Wrap({ embedded, children }: { embedded?: boolean; children: React.ReactNode }) {
  return embedded
    ? <div data-testid="lead-notes">{children}</div>
    : <Card className="p-4 rounded-lg lg:col-span-3" data-testid="lead-notes">{children}</Card>;
}
