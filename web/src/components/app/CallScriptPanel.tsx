"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { ChevronDown, ChevronUp, Copy, Loader2, Pencil, ScrollText, X } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import type { CallScript } from "@/types";

const COMPANY = "Indian Rounds";
const norm = (s?: string | null) => (s ?? "").trim().toLowerCase();
const label = (s: CallScript) => `${s.destination || "Any destination"} · ${s.scope === "mine" ? "Mine" : "Team"}`;

/** Which scripts to offer first: my own for this destination, then the team's, then the general ones, then the rest. */
function rank(s: CallScript, dest: string): number {
  const same = !!dest && norm(s.destination) === norm(dest);
  if (same) return s.scope === "mine" ? 0 : 1;
  if (!s.destination) return s.scope === "mine" ? 2 : 3;
  return 4;
}

/** Put the customer, destination and salesperson into the script. Unknown {words} are left as written. */
export function fillScript(body: string, v: { customer: string; destination: string; me: string }): string {
  const values: Record<string, string> = {
    customer: v.customer.trim() || "Sir/Madam", destination: v.destination.trim() || "your trip", me: v.me.trim() || "your travel consultant", company: COMPANY,
  };
  return body.replace(/\{(customer|destination|me|company)\}/g, (_m, k: string) => values[k]);
}

const STARTER = ["OPENING", "\"Hello {customer}, this is {me} from {company}.\"", ""].join("\n");

const HEADING = /^[A-Z][A-Z0-9 &'’()/-]{2,}$/;

function ScriptText({ text }: { text: string }) {
  return (
    <div className="space-y-1 text-[15px] leading-relaxed" data-testid="script-text">
      {text.split("\n").map((line, i) =>
        line.trim() === "" ? <div key={i} className="h-2" />
        : HEADING.test(line.trim()) ? <div key={i} className="pt-1 text-xs font-bold uppercase tracking-wider text-muted-foreground">{line.trim()}</div>
        : <div key={i} className="whitespace-pre-wrap break-words">{line}</div>
      )}
    </div>
  );
}

/**
 * A floating call script beside the lead form (not a modal, so the form stays usable during the call).
 * Shows the script for the chosen destination with the customer's name filled in; the salesperson can edit it and keep it as their own.
 */
export default function CallScriptPanel({ destination, customer, onClose }: { destination: string; customer: string; onClose: () => void }) {
  const { user, can } = useCurrentUser();
  const canTeam = can("leads.scripts");
  const [scripts, setScripts] = useState<CallScript[] | null>(null);
  const [pickedId, setPickedId] = useState<string | null>(null);
  const [mode, setMode] = useState<"view" | "edit">("view");
  const [draft, setDraft] = useState("");
  const [saveFor, setSaveFor] = useState("");
  const [busy, setBusy] = useState(false);
  const [minimised, setMinimised] = useState(false);

  const load = useCallback(async () => {
    try { const { data } = await api.get<CallScript[]>("/scripts"); setScripts(data); }
    catch (e) { toast.error(formatApiError(e)); setScripts([]); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const options = useMemo(
    () => [...(scripts ?? [])].sort((a, b) => rank(a, destination) - rank(b, destination) || (a.destination ?? "").localeCompare(b.destination ?? "")),
    [scripts, destination]
  );
  const active = options.find((o) => o.id === pickedId) ?? options[0] ?? null;
  const filled = active ? fillScript(active.body, { customer, destination, me: user.name }) : "";
  const teamTwin = active?.scope === "mine" ? (scripts ?? []).some((s) => s.scope === "team" && norm(s.destination) === norm(active.destination)) : false;

  const startEdit = () => {
    if (!active) return;
    setDraft(active.body);
    setSaveFor(destination.trim() || active.destination || "");
    setMode("edit");
  };

  /** Where a saved version can apply: the destination on the form, the script's own destination, or any destination. */
  const saveTargets = useMemo(() => {
    const out: { value: string; text: string }[] = [];
    const add = (v: string) => { if (!out.some((o) => norm(o.value) === norm(v))) out.push({ value: v, text: v || "Any destination" }); };
    if (destination.trim()) add(destination.trim());
    if (active?.destination) add(active.destination);
    add("");
    return out;
  }, [destination, active]);

  const save = async (scope: "mine" | "team") => {
    if (!active || !draft.trim()) return;
    setBusy(true);
    try {
      const { data } = await api.post<CallScript>("/scripts", {
        destination: saveFor || null, title: saveFor ? `${saveFor} call` : "General call", body: draft, scope,
      });
      await load();
      setPickedId(data.id); setMode("view");
      toast.success(scope === "team" ? "Team default updated" : "Saved as your script");
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const remove = async () => {
    if (!active) return;
    const msg = active.scope === "mine"
      ? (teamTwin ? "Go back to the team script and delete your version?" : "Delete your script?")
      : "Delete this team default for everyone?";
    if (!window.confirm(msg)) return;
    setBusy(true);
    try { await api.delete(`/scripts/${active.id}`); setPickedId(null); await load(); toast.success("Deleted"); }
    catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  const copy = async () => {
    try { await navigator.clipboard.writeText(filled); toast.success("Script copied"); } catch { toast.error("Copy failed"); }
  };

  return (
    <aside
      className="fixed inset-x-2 bottom-2 z-40 flex max-h-[72vh] flex-col rounded-lg border border-border bg-card shadow-2xl sm:inset-x-auto sm:bottom-auto sm:right-4 sm:top-20 sm:w-[410px]"
      role="complementary" aria-label="Call script" data-testid="script-panel"
    >
      <div className="flex items-center gap-2 border-b border-border px-3 py-2">
        <ScrollText size={16} aria-hidden />
        <div className="min-w-0 flex-1">
          <div className="text-sm font-semibold leading-tight">Call script</div>
          <div className="truncate text-[11px] text-muted-foreground">{destination.trim() ? `For ${destination.trim()}` : "Choose a destination on the form to get its script"}</div>
        </div>
        <button type="button" onClick={() => setMinimised((m) => !m)} aria-label={minimised ? "Expand script" : "Minimise script"} className="rounded p-1 text-muted-foreground hover:bg-secondary">{minimised ? <ChevronUp size={15} /> : <ChevronDown size={15} />}</button>
        <button type="button" onClick={onClose} aria-label="Close script" className="rounded p-1 text-muted-foreground hover:bg-secondary" data-testid="script-close"><X size={15} /></button>
      </div>

      {!minimised && (scripts === null ? (
        <div className="flex items-center justify-center gap-2 p-8 text-sm text-muted-foreground"><Loader2 size={14} className="animate-spin" /> Loading…</div>
      ) : (
        <>
          {active && (
            <div className="border-b border-border px-3 py-2">
              <select
                value={active.id} onChange={(e) => setPickedId(e.target.value)} disabled={mode === "edit"} aria-label="Choose a script"
                className="h-8 w-full rounded-md border border-input bg-background px-2 text-xs disabled:opacity-60" data-testid="script-select"
              >
                {options.map((o) => <option key={o.id} value={o.id}>{label(o)}</option>)}
              </select>
            </div>
          )}

          <div className="min-h-0 flex-1 overflow-y-auto px-3 py-3">
            {mode === "edit" ? (
              <div className="space-y-2">
                <Textarea value={draft} onChange={(e) => setDraft(e.target.value)} rows={14} className="font-mono text-[13px] leading-relaxed" aria-label="Script text" data-testid="script-draft" />
                <p className="text-[11px] text-muted-foreground">Placeholders filled in on the call: <code>{"{customer}"}</code> <code>{"{destination}"}</code> <code>{"{me}"}</code> <code>{"{company}"}</code>. Lines in CAPITALS become headings.</p>
                <label className="block text-xs">
                  <span className="mr-2 font-medium">Use it for</span>
                  <select value={saveFor} onChange={(e) => setSaveFor(e.target.value)} className="h-8 rounded-md border border-input bg-background px-2 text-xs" data-testid="script-save-for">
                    {saveTargets.map((t) => <option key={t.value} value={t.value}>{t.text}</option>)}
                  </select>
                </label>
              </div>
            ) : active ? <ScriptText text={filled} /> : (
              <div className="p-4 text-center text-sm text-muted-foreground">No scripts yet. Write one to start.</div>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-1.5 border-t border-border px-3 py-2">
            {mode === "edit" ? (
              <>
                <Button size="sm" onClick={() => save("mine")} disabled={busy || !draft.trim()} data-testid="script-save-mine">{busy ? "Saving…" : "Save as my script"}</Button>
                {canTeam && <Button size="sm" variant="outline" onClick={() => save("team")} disabled={busy || !draft.trim()} data-testid="script-save-team">Save as team default</Button>}
                <Button size="sm" variant="ghost" onClick={() => setMode("view")} disabled={busy}>Cancel</Button>
              </>
            ) : active ? (
              <>
                <Button size="sm" variant="outline" onClick={startEdit} data-testid="script-edit"><Pencil size={13} /> Edit</Button>
                <Button size="sm" variant="outline" onClick={copy} data-testid="script-copy"><Copy size={13} /> Copy</Button>
                {(active.scope === "mine" || canTeam) && (
                  <Button size="sm" variant="ghost" className="ml-auto text-xs" onClick={remove} disabled={busy} data-testid="script-delete">
                    {active.scope === "mine" ? (teamTwin ? "Use the team script" : "Delete mine") : "Delete team script"}
                  </Button>
                )}
              </>
            ) : (
              <Button size="sm" onClick={() => { setDraft(STARTER); setSaveFor(destination.trim()); setMode("edit"); }} data-testid="script-write">Write a script</Button>
            )}
          </div>
        </>
      ))}
    </aside>
  );
}
