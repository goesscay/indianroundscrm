"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Loader2, Trash2, Upload } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import type { MediaItem } from "@/types";

const KINDS = [{ v: "destination", l: "Destination" }, { v: "cover", l: "Cover picture" }, { v: "day", l: "Day of the trip" }, { v: "other", l: "Other" }];

export default function MediaLibrary() {
  const [items, setItems] = useState<MediaItem[] | null>(null);
  const [dests, setDests] = useState<string[]>([]);
  const [filter, setFilter] = useState("all");
  const [dest, setDest] = useState("");
  const [kind, setKind] = useState("destination");
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  const load = useCallback(() => {
    api.get<MediaItem[]>("/media", { params: filter === "all" ? {} : { destination: filter } }).then((r) => setItems(r.data)).catch((e) => toast.error(formatApiError(e)));
    api.get<string[]>("/media/destinations").then((r) => setDests(r.data)).catch(() => setDests([]));
  }, [filter]);
  useEffect(() => { load(); }, [load]);

  const upload = async (files: FileList | null) => {
    if (!files?.length) return;
    if (!dest.trim()) { toast.error("Say which destination these pictures are for"); return; }
    setBusy(true);
    try {
      const fd = new FormData(); Array.from(files).forEach((f) => fd.append("files[]", f)); fd.append("destination", dest.trim()); fd.append("kind", kind);
      await api.post("/media", fd); toast.success("Pictures added"); load();
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); if (input.current) input.current.value = ""; }
  };
  const caption = async (m: MediaItem, text: string) => { if (text === (m.caption ?? "")) return; try { await api.patch(`/media/${m.id}`, { caption: text || null }); } catch (e) { toast.error(formatApiError(e)); } };
  const remove = async (m: MediaItem) => { if (!window.confirm("Delete this picture?")) return; try { await api.delete(`/media/${m.id}`); load(); } catch (e) { toast.error(formatApiError(e)); } };

  return (
    <div className="space-y-4" data-testid="media-page">
      <div>
        <div className="label-caps">Operations</div>
        <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Photo library</h1>
        <p className="mt-1 text-xs text-muted-foreground">Destination pictures for quotation covers and day pages. Hotel pictures are added on each hotel&apos;s page. Use pictures you own or have the right to use.</p>
      </div>
      <Card className="rounded-lg p-4">
        <div className="flex flex-wrap items-end gap-3">
          <div><Label className="label-caps">Destination</Label><Input list="media-dests" value={dest} onChange={(e) => setDest(e.target.value)} placeholder="e.g. Manali" className="mt-1.5 w-[200px]" data-testid="media-destination" /><datalist id="media-dests">{dests.map((d) => <option key={d} value={d} />)}</datalist></div>
          <div><Label className="label-caps">What it is</Label>
            <Select value={kind} onValueChange={setKind}><SelectTrigger className="mt-1.5 w-[190px]"><SelectValue /></SelectTrigger><SelectContent>{KINDS.map((k) => <SelectItem key={k.v} value={k.v}>{k.l}</SelectItem>)}</SelectContent></Select></div>
          <input ref={input} type="file" multiple accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => void upload(e.target.files)} data-testid="media-input" />
          <Button onClick={() => input.current?.click()} disabled={busy}>{busy ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />} Add pictures</Button>
        </div>
      </Card>
      <div className="flex items-center gap-2">
        <Select value={filter} onValueChange={setFilter}><SelectTrigger className="h-9 w-[200px]" data-testid="media-filter"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="all">All destinations</SelectItem>{dests.map((d) => <SelectItem key={d} value={d}>{d}</SelectItem>)}</SelectContent></Select>
        <span className="text-xs text-muted-foreground">{items?.length ?? 0} picture{items?.length === 1 ? "" : "s"}</span>
      </div>
      {items && items.length === 0 && <Card className="rounded-lg p-8 text-center text-sm text-muted-foreground">No pictures yet.</Card>}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {items?.map((m) => (
          <div key={m.id} className="overflow-hidden rounded-md border border-border" data-testid={`media-${m.id}`}>
            <div className="aspect-[4/3] bg-secondary">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={m.thumb_url} alt={m.caption ?? m.destination ?? ""} className="h-full w-full object-cover" loading="lazy" />
            </div>
            <div className="space-y-1 p-2">
              <div className="flex items-center justify-between text-[11px]"><span className="font-semibold">{m.destination}</span><span className="rounded bg-secondary px-1.5 py-px uppercase text-muted-foreground">{KINDS.find((k) => k.v === m.kind)?.l ?? m.kind}</span></div>
              <Input defaultValue={m.caption ?? ""} placeholder="Caption (optional)" className="h-7 text-xs" onBlur={(e) => void caption(m, e.target.value.trim())} aria-label="Caption" />
              <Button type="button" size="sm" variant="ghost" className="h-6 w-full justify-start px-1 text-xs text-destructive" onClick={() => void remove(m)}><Trash2 size={12} /> Delete</Button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
