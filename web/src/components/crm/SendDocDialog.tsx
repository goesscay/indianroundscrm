"use client";

import { useEffect, useState, type FormEvent } from "react";
import { FileText, Loader2, Send } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";

interface Preview { to: string[]; subject: string; message: string; filename: string }
const split = (s: string) => s.split(/[,;\s]+/).map((x) => x.trim()).filter(Boolean);

/** E-mail an invoice or a receipt. `basePath` is "/invoices/12" or "/receipts/5". */
export default function SendDocDialog({ basePath, title, open, onOpenChange, onSent, reminder }: {
  reminder?: boolean; basePath: string; title: string; open: boolean; onOpenChange: (o: boolean) => void; onSent?: () => void;
}) {
  const [pre, setPre] = useState<Preview | null>(null);
  const [to, setTo] = useState("");
  const [cc, setCc] = useState("");
  const [subject, setSubject] = useState("");
  const [message, setMessage] = useState("");
  const [pdf, setPdf] = useState(true);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setPre(null); setError(""); setSending(false);
    api.get<Preview>(`${basePath}/email-preview${reminder ? "?reminder=1" : ""}`).then(({ data }) => {
      setPre(data); setTo(data.to.join(", ")); setCc(""); setSubject(data.subject); setMessage(data.message); setPdf(true);
    }).catch((e) => setError(formatApiError(e)));
  }, [open, basePath, reminder]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const toList = split(to);
    if (toList.length === 0) { setError("Enter at least one e-mail address."); return; }
    setSending(true); setError("");
    try {
      const { data } = await api.post<{ status: string; note?: string | null }>(`${basePath}/send`, { to: toList, cc: split(cc), subject: subject.trim(), message, attach_pdf: pdf, ...(reminder ? { reminder: true } : {}) });
      if (data.status === "simulated_sent") toast.warning(data.note || "Mail is in log mode — nothing was really sent.");
      else toast.success(reminder ? "Reminder e-mailed" : `${title} e-mailed`);
      onSent?.(); onOpenChange(false);
    } catch (err) { setError(formatApiError(err)); }
    finally { setSending(false); }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => !sending && onOpenChange(o)}>
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto" data-testid="send-doc-dialog">
        <DialogHeader>
          <DialogTitle className="font-display text-2xl font-black">{reminder ? "Payment reminder" : `E-mail ${title.toLowerCase()}`}</DialogTitle>
          <DialogDescription>{reminder ? "A polite overdue notice with the invoice attached. Edit the wording if you like." : "Sent from the enquiry mailbox with the PDF attached."}</DialogDescription>
        </DialogHeader>
        {!pre && !error && <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground"><Loader2 size={16} className="animate-spin" /> Preparing…</div>}
        {pre && (
          <form onSubmit={submit} className="space-y-4">
            <div><Label htmlFor="sd-to">To</Label><Input id="sd-to" className="mt-1.5 h-10" value={to} onChange={(e) => setTo(e.target.value)} placeholder="customer@example.com" data-testid="send-to" />
              {!pre.to.length && <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">This customer has no e-mail saved. Type one here.</p>}</div>
            <div><Label htmlFor="sd-cc">Cc</Label><Input id="sd-cc" className="mt-1.5 h-10" value={cc} onChange={(e) => setCc(e.target.value)} placeholder="Optional, comma separated" /></div>
            <div><Label htmlFor="sd-sub">Subject</Label><Input id="sd-sub" className="mt-1.5 h-10" value={subject} onChange={(e) => setSubject(e.target.value)} /></div>
            <div><Label htmlFor="sd-msg">Message</Label><Textarea id="sd-msg" rows={8} className="mt-1.5" value={message} onChange={(e) => setMessage(e.target.value)} /></div>
            <label className="flex cursor-pointer items-center gap-2 text-sm"><Checkbox checked={pdf} onCheckedChange={(v) => setPdf(v === true)} /> <FileText size={14} /> Attach as PDF ({pre.filename})</label>
            {error && <div className="text-sm text-destructive" role="alert">{error}</div>}
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={sending}>Cancel</Button>
              <Button type="submit" disabled={sending} data-testid="send-submit">{sending ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />} Send</Button>
            </DialogFooter>
          </form>
        )}
        {!pre && error && <div className="text-sm text-destructive" role="alert">{error}</div>}
      </DialogContent>
    </Dialog>
  );
}
