"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { LOST_REASONS } from "@/lib/leadStages";
import { cn } from "@/lib/utils";

/** Marking a lead Lost asks why, so the reasons can be looked at later. */
export default function LostReasonDialog({ open, onOpenChange, leadLabel, onConfirm }: {
  open: boolean; onOpenChange: (o: boolean) => void; leadLabel: string; onConfirm: (reason: string) => Promise<void> | void;
}) {
  const [reason, setReason] = useState("");
  const [other, setOther] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (open) { setReason(""); setOther(""); setBusy(false); } }, [open]);

  const final = reason === "Other" ? (other.trim() ? `Other: ${other.trim()}` : "") : reason;
  const submit = async () => {
    if (!final) return;
    setBusy(true);
    try { await onConfirm(final.slice(0, 120)); onOpenChange(false); } finally { setBusy(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !busy && onOpenChange(o)}>
      <DialogContent className="max-w-sm" data-testid="lost-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-xl font-black">Mark as Lost</DialogTitle>
          <DialogDescription>{leadLabel}. Why was this lead lost?</DialogDescription>
        </DialogHeader>
        <div className="space-y-1.5">
          {LOST_REASONS.map((r) => (
            <label key={r} className={cn("flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm", reason === r ? "border-foreground" : "border-border")}>
              <input type="radio" name="lost-reason" className="accent-black dark:accent-white" checked={reason === r} onChange={() => setReason(r)} data-testid={`lost-reason-${r.toLowerCase().replace(/[^a-z]+/g, "-")}`} />
              {r}
            </label>
          ))}
          {reason === "Other" && (
            <div><Label className="label-caps">Tell us more</Label><Input className="mt-1.5" value={other} onChange={(e) => setOther(e.target.value)} maxLength={100} data-testid="lost-other" autoFocus /></div>
          )}
        </div>
        <DialogFooter className="gap-2 sm:gap-2">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>Cancel</Button>
          <Button variant="destructive" onClick={submit} disabled={busy || !final} data-testid="lost-confirm">{busy ? "Saving…" : "Mark as Lost"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
