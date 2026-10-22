"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import type { QuoteBlock, QuoteSettings } from "@/types";

type Kind = QuoteBlock["kind"];
const KINDS: { k: Kind; l: string; hint: string }[] = [
  { k: "inclusions", l: "Inclusions", hint: "What the package includes. One line per item, shown with ticks." },
  { k: "exclusions", l: "Exclusions", hint: "What is not included." },
  { k: "cancellation", l: "Cancellation policy", hint: "The charge by how many days before departure, and any extra lines." },
  { k: "notes", l: "Notes", hint: "Short practical notes for the guest." },
  { k: "terms", l: "Terms and conditions", hint: "The terms the guest agrees to." },
  { k: "closing", l: "Closing page", hint: "The words on the last page, one line each: headline, sub-line, tagline." },
];

function BlockDialog({ kind, block, open, onClose, onSaved, destinations }: { kind: Kind; block: QuoteBlock | null; open: boolean; onClose: () => void; onSaved: () => void; destinations: string[] }) {
  const [f, setF] = useState({ title: "", destination: "", items: "", is_default: true, active: true });
  const [tiers, setTiers] = useState<{ label: string; percent: string }[]>([]);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (!open) return;
    setF({ title: block?.title ?? "", destination: block?.destination ?? "", items: (block?.items ?? []).join("\n"), is_default: block?.is_default ?? true, active: block?.active ?? true });
    setTiers((block?.tiers ?? []).map((t) => ({ label: t.label, percent: String(t.percent) })));
  }, [open, block]);
  const save = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true);
    const body = {
      kind, title: f.title, destination: f.destination.trim() || null, items: f.items.split("\n").map((x) => x.trim()).filter(Boolean), is_default: f.is_default, active: f.active,
      ...(kind === "cancellation" ? { tiers: tiers.filter((t) => t.label.trim()).map((t) => ({ label: t.label, percent: Number(t.percent) || 0 })) } : {}),
    };
    try {
      if (block) await api.patch(`/quote-content/${block.id}`, body); else await api.post("/quote-content", body);
      toast.success("Saved"); onSaved(); onClose();
    } catch (err) { toast.error(formatApiError(err)); }
    finally { setBusy(false); }
  };
  const meta = KINDS.find((x) => x.k === kind)!;
  return (
    <Dialog open={open} onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[92vh] max-w-xl overflow-y-auto" data-testid="block-dialog">
        <DialogHeader><DialogTitle className="font-display text-2xl font-black">{block ? "Edit" : "New"} {meta.l.toLowerCase()}</DialogTitle><DialogDescription>{meta.hint}</DialogDescription></DialogHeader>
        <form onSubmit={save} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div><Label className="label-caps">Name</Label><Input className="mt-1.5" value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} required data-testid="block-title" placeholder="e.g. Manali land package" /></div>
            <div><Label className="label-caps">Only for (optional)</Label><Input className="mt-1.5" list="qc-destinations" value={f.destination} onChange={(e) => setF({ ...f, destination: e.target.value })} placeholder="All destinations" data-testid="block-destination" />
              <datalist id="qc-destinations">{destinations.map((d) => <option key={d} value={d} />)}</datalist></div>
          </div>
          {kind === "cancellation" && (
            <div>
              <Label className="label-caps">Charge by days before departure</Label>
              <div className="mt-1.5 space-y-1.5" data-testid="tiers">
                {tiers.map((t, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <Input value={t.label} onChange={(e) => setTiers(tiers.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} placeholder="e.g. 50+ days before departure" className="flex-1" />
                    <Input type="number" min={0} max={100} value={t.percent} onChange={(e) => setTiers(tiers.map((x, j) => (j === i ? { ...x, percent: e.target.value } : x)))} className="w-20" aria-label="Percent" />
                    <span className="text-xs text-muted-foreground">%</span>
                    <Button type="button" size="sm" variant="ghost" onClick={() => setTiers(tiers.filter((_, j) => j !== i))} aria-label="Remove"><Trash2 size={13} className="text-destructive" /></Button>
                  </div>
                ))}
                <Button type="button" size="sm" variant="outline" onClick={() => setTiers([...tiers, { label: "", percent: "100" }])} data-testid="tier-add"><Plus size={13} /> Add a line</Button>
              </div>
            </div>
          )}
          <div><Label className="label-caps">{kind === "cancellation" ? "Other lines" : "Lines"} (one per line)</Label><Textarea rows={10} className="mt-1.5 text-sm" value={f.items} onChange={(e) => setF({ ...f, items: e.target.value })} data-testid="block-items" /></div>
          <div className="flex flex-wrap gap-4 text-sm">
            <label className="flex cursor-pointer items-center gap-2"><input type="checkbox" checked={f.is_default} onChange={(e) => setF({ ...f, is_default: e.target.checked })} className="accent-black dark:accent-white" /> Added to new quotations automatically</label>
            <label className="flex cursor-pointer items-center gap-2"><input type="checkbox" checked={f.active} onChange={(e) => setF({ ...f, active: e.target.checked })} className="accent-black dark:accent-white" /> In use</label>
          </div>
          <DialogFooter className="gap-2 sm:gap-2"><Button type="button" variant="outline" onClick={onClose} disabled={busy}>Cancel</Button><Button type="submit" disabled={busy} data-testid="block-save">{busy ? "Saving…" : "Save"}</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function TypeSel({ v, on }: { v: string; on: (x: string) => void }) {
  return (
    <Select value={v} onValueChange={on}><SelectTrigger className="w-[210px]"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="percent">% of the vendor cost</SelectItem><SelectItem value="amount">₹ per person (whole stay)</SelectItem></SelectContent></Select>
  );
}

function Margins({ destinations }: { destinations: string[] }) {
  const [s, setS] = useState<QuoteSettings | null>(null);
  const [def, setDef] = useState({ type: "percent", value: "" });
  const [rows, setRows] = useState<{ dest: string; type: string; value: string }[]>([]);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => {
    api.get<QuoteSettings>("/quote-settings").then((r) => {
      setS(r.data); setDef({ type: r.data.margin_default.type, value: String(r.data.margin_default.value) });
      setRows(Object.entries(r.data.margin_destinations).map(([dest, m]) => ({ dest, type: m.type, value: String(m.value) })));
    }).catch((e) => toast.error(formatApiError(e)));
  }, []);
  useEffect(() => { load(); }, [load]);
  const save = async () => {
    setBusy(true);
    try {
      await api.put("/quote-settings", { margin_default: { type: def.type, value: Number(def.value) || 0 }, margin_destinations: Object.fromEntries(rows.filter((r) => r.dest.trim()).map((r) => [r.dest.trim(), { type: r.type, value: Number(r.value) || 0 }])) });
      toast.success("Margins saved"); load();
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };
  if (!s) return <div className="py-8 text-center text-sm text-muted-foreground">Loading…</div>;
  return (
    <Card className="space-y-4 rounded-lg p-5" data-testid="margins">
      <div>
        <h3 className="font-display text-lg font-bold">Margins</h3>
        <p className="text-xs text-muted-foreground">What we add to the vendor rate of a hotel. A hotel&apos;s own margin wins, then its destination&apos;s, then the standard one. Customers and sales never see this.</p>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <div><Label className="label-caps">Standard margin</Label><div className="mt-1.5"><TypeSel v={def.type} on={(v) => setDef({ ...def, type: v })} /></div></div>
        <div><Label className="label-caps">{def.type === "percent" ? "Percent" : "₹ per person"}</Label><Input type="number" min={0} className="mt-1.5 w-28" value={def.value} onChange={(e) => setDef({ ...def, value: e.target.value })} data-testid="margin-default" /></div>
      </div>
      <div>
        <Label className="label-caps">Different for a destination</Label>
        <div className="mt-1.5 space-y-1.5">
          {rows.map((r, i) => (
            <div key={i} className="flex flex-wrap items-center gap-2" data-testid={`margin-row-${i}`}>
              <Input list="qc-destinations-m" value={r.dest} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, dest: e.target.value } : x)))} placeholder="Destination" className="w-[180px]" />
              <TypeSel v={r.type} on={(v) => setRows(rows.map((x, j) => (j === i ? { ...x, type: v } : x)))} />
              <Input type="number" min={0} value={r.value} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} className="w-24" aria-label="Value" />
              <Button type="button" size="sm" variant="ghost" onClick={() => setRows(rows.filter((_, j) => j !== i))} aria-label="Remove"><Trash2 size={13} className="text-destructive" /></Button>
            </div>
          ))}
          <datalist id="qc-destinations-m">{destinations.map((d) => <option key={d} value={d} />)}</datalist>
          <Button type="button" size="sm" variant="outline" onClick={() => setRows([...rows, { dest: "", type: def.type, value: def.value }])} data-testid="margin-add"><Plus size={13} /> Add a destination</Button>
        </div>
      </div>
      <Button onClick={save} disabled={busy} data-testid="margin-save">{busy ? "Saving…" : "Save margins"}</Button>
    </Card>
  );
}

export default function QuoteContentPage() {
  const [tab, setTab] = useState<Kind | "margins">("inclusions");
  const [blocks, setBlocks] = useState<QuoteBlock[] | null>(null);
  const [destinations, setDestinations] = useState<string[]>([]);
  const [dlg, setDlg] = useState<{ open: boolean; block: QuoteBlock | null }>({ open: false, block: null });
  const load = useCallback(() => {
    api.get<QuoteBlock[]>("/quote-content").then((r) => setBlocks(r.data)).catch((e) => toast.error(formatApiError(e)));
    api.get<string[]>("/hotels/destinations").then((r) => setDestinations(r.data)).catch(() => setDestinations([]));
  }, []);
  useEffect(() => { load(); }, [load]);
  const remove = async (b: QuoteBlock) => {
    if (!window.confirm(`Delete "${b.title}"?`)) return;
    try { await api.delete(`/quote-content/${b.id}`); load(); } catch (e) { toast.error(formatApiError(e)); }
  };
  const list = (blocks ?? []).filter((b) => b.kind === tab);
  return (
    <div className="space-y-4" data-testid="quote-content-page">
      <div>
        <div className="label-caps">Operations</div>
        <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Quotation content</h1>
        <p className="mt-1 text-xs text-muted-foreground">The standard words of a quotation and the margins. The quotation builder pulls these in, and each quotation can still edit them.</p>
      </div>
      <div className="flex flex-wrap gap-1 border-b border-border" role="tablist">
        {[...KINDS.map((k) => ({ k: k.k as Kind | "margins", l: k.l })), { k: "margins" as const, l: "Margins" }].map((t) => (
          <button key={t.k} type="button" role="tab" aria-selected={tab === t.k} onClick={() => setTab(t.k)} data-testid={`qc-tab-${t.k}`}
                  className={cn("-mb-px border-b-2 px-3 py-2 text-[13px] font-medium", tab === t.k ? "border-primary text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}>{t.l}</button>
        ))}
      </div>
      {tab === "margins" ? <Margins destinations={destinations} /> : (
        <div className="space-y-3">
          <Button onClick={() => setDlg({ open: true, block: null })} data-testid="block-new"><Plus size={15} /> New</Button>
          {blocks === null && <div className="py-8 text-center text-sm text-muted-foreground">Loading…</div>}
          {blocks && list.length === 0 && <Card className="rounded-lg p-6 text-sm text-muted-foreground">Nothing here yet.</Card>}
          {list.map((b) => (
            <Card key={b.id} className={cn("rounded-lg p-4", !b.active && "opacity-60")} data-testid={`block-${b.id}`}>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold">{b.title}</span>
                  <span className="rounded bg-secondary px-1.5 py-0.5 text-[10px] font-semibold uppercase">{b.destination ?? "All destinations"}</span>
                  {b.is_default && <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">Automatic</span>}
                  {!b.active && <span className="text-xs text-muted-foreground">not in use</span>}
                </div>
                <div className="flex gap-1">
                  <Button size="sm" variant="ghost" onClick={() => setDlg({ open: true, block: b })} aria-label="Edit" data-testid={`block-edit-${b.id}`}><Pencil size={14} /></Button>
                  <Button size="sm" variant="ghost" onClick={() => void remove(b)} aria-label="Delete"><Trash2 size={14} className="text-destructive" /></Button>
                </div>
              </div>
              {b.tiers.length > 0 && <ul className="mt-2 space-y-0.5 text-sm">{b.tiers.map((t, i) => <li key={i}><b>{t.percent}%</b> cancellation charge · {t.label}</li>)}</ul>}
              {b.items.length > 0 && <ul className="mt-2 list-disc space-y-0.5 pl-5 text-sm text-muted-foreground">{b.items.slice(0, 6).map((x, i) => <li key={i}>{x}</li>)}{b.items.length > 6 && <li className="list-none text-xs">and {b.items.length - 6} more</li>}</ul>}
            </Card>
          ))}
        </div>
      )}
      {tab !== "margins" && <BlockDialog kind={tab} block={dlg.block} open={dlg.open} onClose={() => setDlg({ open: false, block: null })} onSaved={load} destinations={destinations} />}
    </div>
  );
}
