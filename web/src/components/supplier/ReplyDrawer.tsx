"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { Search } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import RichText from "@/components/chat/RichText";
import { AttachmentList, StatusBadge, absTime, relTime, senderLabel } from "@/components/supplier/shared";
import { cn } from "@/lib/utils";
import type { Lead, LeadSupplier, SupplierReply } from "@/types";

interface LeadHit { lead_id: string; customer_name: string; destination?: string; lead_status?: string }

function LinkForm({ reply, onDone }: { reply: SupplierReply; onDone: (r: SupplierReply) => void }) {
  const [q, setQ] = useState("");
  const [hits, setHits] = useState<LeadHit[]>([]);
  const [searching, setSearching] = useState(false);
  const [picked, setPicked] = useState<LeadHit | null>(null);
  const [suppliers, setSuppliers] = useState<LeadSupplier[]>([]);
  const [supplierId, setSupplierId] = useState("auto");
  const [busy, setBusy] = useState(false);
  const reqId = useRef(0);

  useEffect(() => {
    const text = q.trim();
    if (!text) { setHits([]); setSearching(false); return; }
    const id = ++reqId.current;
    setSearching(true);
    const t = setTimeout(() => {
      api.get<{ items?: Lead[] } | Lead[]>("/leads", { params: { page: 1, per_page: 10, q: text } })
        .then((r) => {
          if (id !== reqId.current) return;
          const items = Array.isArray(r.data) ? r.data : r.data.items ?? [];
          setHits(items.slice(0, 10).map((l) => ({ lead_id: l.lead_id, customer_name: l.customer_name, destination: l.destination, lead_status: l.lead_status })));
        })
        .catch(() => { if (id === reqId.current) setHits([]); })
        .finally(() => { if (id === reqId.current) setSearching(false); });
    }, 300);
    return () => clearTimeout(t);
  }, [q]);

  useEffect(() => {
    setSupplierId("auto");
    setSuppliers([]);
    if (!picked) return;
    let cancelled = false;
    api.get<LeadSupplier[]>(`/lead-suppliers/${picked.lead_id}`)
      .then((r) => { if (!cancelled) setSuppliers((Array.isArray(r.data) ? r.data : []).filter((s) => !!s.supplier_id)); })
      .catch(() => { /* optional */ });
    return () => { cancelled = true; };
  }, [picked]);

  const confirm = async () => {
    if (!picked) return;
    setBusy(true);
    try {
      const body: { lead_id: string; supplier_id?: string } = { lead_id: picked.lead_id };
      if (supplierId !== "auto") body.supplier_id = supplierId;
      const { data } = await api.post<SupplierReply>(`/supplier-replies/${reply.id}/link`, body);
      toast.success(`Linked to ${picked.lead_id}`);
      onDone(data);
    } catch (e) {
      toast.error(formatApiError(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-3 rounded-md border border-border p-3" data-testid="inbox-link-form">
      <div>
        <Label htmlFor="inbox-lead-search" className="label-caps">Link to lead</Label>
        <div className="relative mt-1.5">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input
            id="inbox-lead-search" value={q} onChange={(e) => { setQ(e.target.value); setPicked(null); }}
            placeholder="Search by lead id, customer, phone…" className="pl-9" autoComplete="off" data-testid="inbox-lead-search"
          />
        </div>
      </div>
      {!picked && q.trim() && (
        <ul className="max-h-48 divide-y divide-border overflow-y-auto rounded-md border border-border" aria-label="Matching leads" data-testid="inbox-lead-results">
          {searching && hits.length === 0 && <li className="px-3 py-2 text-xs text-muted-foreground">Searching…</li>}
          {!searching && hits.length === 0 && <li className="px-3 py-2 text-xs text-muted-foreground">No leads found.</li>}
          {hits.map((h) => (
            <li key={h.lead_id}>
              <button
                type="button" onClick={() => setPicked(h)}
                className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-secondary focus-visible:outline-none focus-visible:bg-secondary"
                data-testid={`inbox-lead-hit-${h.lead_id}`}
              >
                <span className="min-w-0">
                  <span className="font-mono font-semibold">{h.lead_id}</span>{" "}
                  <span className="truncate">{h.customer_name}</span>
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">{h.destination}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {picked && (
        <div className="space-y-3">
          <div className="rounded-md bg-secondary/60 px-3 py-2 text-sm">
            Link this reply to <span className="font-mono font-semibold">{picked.lead_id}</span> · {picked.customer_name}
            {picked.destination ? ` · ${picked.destination}` : ""}
          </div>
          {suppliers.length > 0 && (
            <div>
              <Label className="label-caps">Supplier (optional)</Label>
              <Select value={supplierId} onValueChange={setSupplierId}>
                <SelectTrigger className="mt-1.5" aria-label="Supplier" data-testid="inbox-supplier-select"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="auto">Detect from sender</SelectItem>
                  {suppliers.map((s) => <SelectItem key={s.id} value={String(s.supplier_id)}>{s.supplier_name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            <Button onClick={confirm} disabled={busy} data-testid="inbox-link-lead">{busy ? "Linking…" : `Link to ${picked.lead_id}`}</Button>
            <Button variant="ghost" onClick={() => setPicked(null)} disabled={busy}>Change</Button>
          </div>
        </div>
      )}
    </div>
  );
}

/** Right-hand detail drawer for one reply. */
export default function ReplyDrawer({
  reply, onClose, onChanged,
}: {
  reply: SupplierReply | null;
  onClose: () => void;
  onChanged: (r: SupplierReply) => void;
}) {
  const [ignoring, setIgnoring] = useState(false);

  const ignore = async () => {
    if (!reply) return;
    setIgnoring(true);
    try {
      const { data } = await api.post<SupplierReply>(`/supplier-replies/${reply.id}/ignore`);
      toast.success("Reply ignored");
      onChanged(data);
    } catch (e) {
      toast.error(formatApiError(e));
    } finally {
      setIgnoring(false);
    }
  };

  return (
    <Dialog open={!!reply} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent
        className={cn(
          "left-auto right-0 top-0 h-full max-w-xl translate-x-0 translate-y-0 overflow-y-auto rounded-none border-y-0 border-r-0 content-start",
          "data-[state=open]:slide-in-from-right data-[state=closed]:slide-out-to-right data-[state=open]:zoom-in-100 data-[state=closed]:zoom-out-100 data-[state=open]:slide-in-from-top-0 data-[state=closed]:slide-out-to-top-0"
        )}
        data-testid="inbox-drawer"
      >
        {reply && (
          <>
            <DialogHeader className="pr-6">
              <div className="flex flex-wrap items-center gap-1.5">
                <StatusBadge status={reply.status} />
                {reply.matched_by && <span className="text-[10px] uppercase tracking-wide text-muted-foreground">via {reply.matched_by}</span>}
              </div>
              <DialogTitle className="break-words leading-snug">{reply.subject || "(no subject)"}</DialogTitle>
              <DialogDescription asChild>
                <div>
                  <div className="font-medium text-foreground">{senderLabel(reply)}</div>
                  <div className="break-all">{reply.from_email}</div>
                  <time dateTime={reply.received_at} className="text-xs" title={relTime(reply.received_at)}>{absTime(reply.received_at)}</time>
                </div>
              </DialogDescription>
            </DialogHeader>

            {reply.lead_id && (
              <div className="rounded-md bg-secondary/60 px-3 py-2 text-sm">
                Lead:{" "}
                <Link href={`/leads/${reply.lead_id}#supplier-replies`} className="font-mono font-semibold underline underline-offset-2" data-testid="inbox-open-lead">
                  {reply.lead_id}
                </Link>
                {reply.customer_name ? ` · ${reply.customer_name}` : ""}
              </div>
            )}

            <div className="whitespace-pre-wrap break-words rounded-md border border-border p-3 text-sm" data-testid="inbox-body">
              {reply.body_text ? <RichText text={reply.body_text} /> : <span className="text-muted-foreground">(no text)</span>}
            </div>

            <AttachmentList items={reply.attachments} />

            {reply.status === "unmatched" && (
              <div className="space-y-3">
                <LinkForm reply={reply} onDone={onChanged} />
                <Button variant="outline" onClick={ignore} disabled={ignoring} data-testid="inbox-ignore">
                  {ignoring ? "Ignoring…" : "Ignore this reply"}
                </Button>
              </div>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
