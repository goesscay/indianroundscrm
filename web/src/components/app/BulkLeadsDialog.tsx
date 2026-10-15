"use client";

import { useEffect, useState, type FormEvent } from "react";
import { CheckCircle2, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { api, apiErrorStatus, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Combobox } from "@/components/ui/combobox";
import { mergePlaces, tidyPlace } from "@/lib/places";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { Lead } from "@/types";

const ROWS = 10;

const DEFAULT_SOURCE = "WhatsApp";

interface Row { phone: string; destination: string; source: string; error?: string }
const blank = (destination = ""): Row => ({ phone: "", destination, source: DEFAULT_SOURCE });
/** A row counts once a number is entered, or a destination other than the pre-filled one: the defaults alone do not. */
const isBlank = (r: Row, defaultDestination = "") => !r.phone.trim() && (!r.destination || r.destination === defaultDestination);

const cleanPhone = (raw: string) => (raw || "").replace(/\D/g, "").replace(/^91(?=\d{10}$)/, "").replace(/^0(?=\d{10}$)/, "");
const phoneError = (raw: string) => {
  const d = cleanPhone(raw);
  if (d.length !== 10) return "Enter a valid 10-digit mobile number";
  if (!/^[6-9]/.test(d)) return "Mobile must start with 6, 7, 8 or 9";
  return "";
};

/** Add up to 10 leads at once from a mobile number and a destination. The source starts as WhatsApp; the lead is named by its number until someone edits it. Each row goes through the normal lead rules. */
export default function BulkLeadsDialog({ open, onOpenChange, onDone }: { open: boolean; onOpenChange: (o: boolean) => void; onDone: () => void }) {
  const { user, can } = useCurrentUser();
  const myDestination = (user.default_destination || "").trim();
  const [rows, setRows] = useState<Row[]>(() => Array.from({ length: ROWS }, () => blank(myDestination)));
  const [destinations, setDestinations] = useState<string[]>([]);
  const [sources, setSources] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [added, setAdded] = useState<string[]>([]);

  useEffect(() => {
    if (!open) return;
    setRows(Array.from({ length: ROWS }, () => blank(myDestination))); setAdded([]);
    api.get<{ master: Record<string, string[]>; used?: Record<string, string[]> }>("/lead-options")
      .then(({ data }) => { setDestinations(mergePlaces(data.master?.destination, data.used?.destination)); setSources(data.master?.lead_source ?? []); })
      .catch((e) => toast.error(`Could not load the lists: ${formatApiError(e)}`));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const set = (i: number, patch: Partial<Row>) => setRows((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch, error: undefined } : r)));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    // check every filled row first, so nothing is half-saved because of a typo
    const seen = new Set<string>();
    const checked = rows.map((r) => {
      if (isBlank(r, myDestination)) return r;
      let error = "";
      if (phoneError(r.phone)) error = phoneError(r.phone);
      else if (!r.destination) error = "Choose a destination";
      else if (seen.has(cleanPhone(r.phone))) error = "Same mobile number appears twice in this list";
      if (!error) seen.add(cleanPhone(r.phone));
      return { ...r, error: error || undefined };
    });
    setRows(checked);
    const todo = checked.map((r, i) => ({ r, i })).filter(({ r }) => !isBlank(r, myDestination));
    if (todo.length === 0) { toast.error("Fill in at least one row"); return; }
    if (todo.some(({ r }) => r.error)) { toast.error("Fix the highlighted rows"); return; }

    setBusy(true);
    const created: string[] = [];
    const next = [...checked];
    for (const { r, i } of todo) {
      try {
        const { data } = await api.post<Lead>("/leads", {
          customer_name: cleanPhone(r.phone), phone: cleanPhone(r.phone), destination: r.destination, ...(r.source ? { lead_source: r.source } : {}),
        });
        created.push(data.lead_id);
        next[i] = blank(myDestination);
      } catch (err) {
        const detail = (err as { response?: { data?: { detail?: { existing?: { lead_id: string; staff_name?: string } } } } }).response?.data?.detail;
        const msg = apiErrorStatus(err) === 409 && detail && typeof detail === "object" && detail.existing
          ? `Already a lead: ${detail.existing.lead_id}${detail.existing.staff_name ? ` (${detail.existing.staff_name})` : ""}`
          : formatApiError(err);
        next[i] = { ...r, error: msg };
      }
    }
    setBusy(false);
    setRows(next);
    setAdded((a) => [...a, ...created]);
    if (created.length) onDone();
    const failed = next.filter((r) => r.error).length;
    if (failed === 0) { toast.success(`${created.length} lead${created.length === 1 ? "" : "s"} added`); onOpenChange(false); }
    else toast.warning(`${created.length} added, ${failed} need attention`);
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
      <DialogContent className="max-h-[92vh] max-w-2xl overflow-y-auto" data-testid="bulk-leads-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">Add leads in bulk</DialogTitle>
          <DialogDescription>
            Up to {ROWS} leads at once. Mobile number and destination are required. The destination is yours ({myDestination || "none set"}) and the source is WhatsApp unless you change them, and each lead carries its mobile number as the name until you edit it.
            {can("leads.reassign") ? " Owners are set from the destination allocation." : " The leads are added in your name."}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-[1.5rem_11rem_1fr_1fr] items-end gap-2 text-xs sm:text-sm">
            <span />
            <span className="label-caps">Mobile number *</span>
            <span className="label-caps">Destination *</span><span className="label-caps">Lead source</span>
            {rows.map((r, i) => (
              <div key={i} className="contents">
                <span className="pb-2 text-right text-muted-foreground">{i + 1}</span>
                <Input value={r.phone} onChange={(e) => set(i, { phone: e.target.value })} placeholder="10-digit mobile" inputMode="tel" aria-label={`Mobile number ${i + 1}`} data-testid={`bulk-phone-${i}`}
                       className={r.error ? "border-destructive" : ""} />
                <Combobox options={destinations} value={r.destination} onChange={(v) => set(i, { destination: tidyPlace(v) })} allowCustom placeholder="Destination" searchPlaceholder="Search, or type a new place…" testId={`bulk-dest-${i}`} />
                <Combobox options={sources} value={r.source} onChange={(v) => set(i, { source: v })} placeholder="Source" searchPlaceholder="Search…" clearLabel="No source" clearValue="" testId={`bulk-src-${i}`} />
                {r.error && <span className="col-span-4 -mt-1 pl-8 text-xs text-destructive" role="alert" data-testid={`bulk-err-${i}`}>{r.error}</span>}
              </div>
            ))}
          </div>
          {added.length > 0 && (
            <div className="flex items-start gap-2 rounded-md border border-border bg-secondary/40 p-2 text-xs" data-testid="bulk-added">
              <CheckCircle2 size={14} className="mt-0.5 shrink-0 text-emerald-600" /> Added: {added.join(", ")}
            </div>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy} data-testid="bulk-cancel">{added.length ? "Close" : "Cancel"}</Button>
            <Button type="submit" disabled={busy} data-testid="bulk-submit">{busy ? <><Loader2 size={14} className="mr-1 animate-spin" /> Adding…</> : "Submit"}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
