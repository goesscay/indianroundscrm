"use client";

import { useEffect, useState } from "react";
import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { CATEGORIES, type TripTemplate, type TripTemplateItem } from "@/components/crm/tripShared";
import { cn } from "@/lib/utils";

/** Admin: the standard checklist for each trip type. New trips copy the template; trips already confirmed keep their own list. */
export default function TripTemplates() {
  const [list, setList] = useState<TripTemplate[]>([]);
  const [sel, setSel] = useState<string>("");
  const [items, setItems] = useState<TripTemplateItem[]>([]);
  const [name, setName] = useState("");
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get<TripTemplate[]>("/trip-templates").then(({ data }) => {
      setList(data);
      if (data[0]) { setSel(data[0].id); setItems(data[0].items); setName(data[0].name); }
    }).catch((e) => toast.error(formatApiError(e)));
  }, []);

  const pick = (id: string) => {
    if (dirty && !window.confirm("Discard your unsaved changes?")) return;
    const t = list.find((x) => x.id === id);
    if (!t) return;
    setSel(id); setItems(t.items); setName(t.name); setDirty(false);
  };
  const edit = (i: number, patch: Partial<TripTemplateItem>) => { setItems((xs) => xs.map((x, j) => (j === i ? { ...x, ...patch } : x))); setDirty(true); };
  const move = (i: number, d: number) => {
    setItems((xs) => { const a = [...xs]; const j = i + d; if (j < 0 || j >= a.length) return xs; [a[i], a[j]] = [a[j], a[i]]; return a; });
    setDirty(true);
  };
  const save = async () => {
    if (items.some((x) => !x.title.trim())) { toast.error("Every item needs a title"); return; }
    setSaving(true);
    try {
      const { data } = await api.put<TripTemplate>(`/trip-templates/${sel}`, { name, items: items.map((x) => ({ category: x.category, title: x.title, due_days_before: x.due_days_before, critical: x.critical })) });
      setList((l) => l.map((x) => (x.id === data.id ? data : x))); setItems(data.items); setDirty(false);
      toast.success("Template saved. New trips will use it.");
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setSaving(false); }
  };

  return (
    <div className="space-y-4" data-testid="trip-templates-page">
      <div>
        <div className="label-caps">Administration</div>
        <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">Trip checklists</h1>
        <p className="mt-1 text-xs text-muted-foreground">The standard steps for each type of trip. Due days count back from the travel date; a negative number means after the trip. Trips already confirmed keep their own list.</p>
      </div>
      <div className="flex flex-wrap gap-2">
        {list.map((t) => (
          <button key={t.id} type="button" onClick={() => pick(t.id)} data-testid={`tpl-${t.trip_type}`}
                  className={cn("rounded border px-3 py-1.5 text-sm", sel === t.id ? "border-foreground bg-foreground text-background" : "border-border hover:bg-secondary")}>{t.name}</button>
        ))}
      </div>
      {sel && (
        <Card className="rounded-lg p-4">
          <Input value={name} onChange={(e) => { setName(e.target.value); setDirty(true); }} className="mb-3 max-w-sm font-semibold" aria-label="Template name" />
          <div className="hidden grid-cols-[1fr_11rem_6rem_5rem_6.5rem] gap-2 pb-1 text-xs sm:grid"><span className="label-caps">Step</span><span className="label-caps">Category</span><span className="label-caps">Days before</span><span className="label-caps">Critical</span><span /></div>
          <div className="space-y-2">
            {items.map((it, i) => (
              <div key={i} className="grid gap-2 sm:grid-cols-[1fr_11rem_6rem_5rem_6.5rem] sm:items-center" data-testid={`tpl-item-${i}`}>
                <Input value={it.title} onChange={(e) => edit(i, { title: e.target.value })} aria-label={`Step ${i + 1}`} />
                <Select value={it.category} onValueChange={(v) => edit(i, { category: v })}>
                  <SelectTrigger aria-label="Category"><SelectValue /></SelectTrigger>
                  <SelectContent>{CATEGORIES.map((c) => <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>)}</SelectContent>
                </Select>
                <Input type="number" value={it.due_days_before ?? ""} onChange={(e) => edit(i, { due_days_before: e.target.value === "" ? null : Number(e.target.value) })} aria-label="Days before travel" />
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={it.critical} onChange={(e) => edit(i, { critical: e.target.checked })} className="accent-black dark:accent-white" /> Critical</label>
                <div className="flex items-center gap-1">
                  <Button type="button" variant="ghost" size="icon" onClick={() => move(i, -1)} aria-label="Move up" disabled={i === 0}><ArrowUp size={14} /></Button>
                  <Button type="button" variant="ghost" size="icon" onClick={() => move(i, 1)} aria-label="Move down" disabled={i === items.length - 1}><ArrowDown size={14} /></Button>
                  <Button type="button" variant="ghost" size="icon" onClick={() => { setItems((xs) => xs.filter((_, j) => j !== i)); setDirty(true); }} aria-label="Remove step"><Trash2 size={14} className="text-destructive" /></Button>
                </div>
              </div>
            ))}
          </div>
          <div className="mt-4 flex items-center justify-between">
            <Button type="button" variant="outline" size="sm" onClick={() => { setItems((xs) => [...xs, { category: "other", title: "", due_days_before: null, critical: false }]); setDirty(true); }} data-testid="tpl-add"><Plus size={14} /> Add step</Button>
            <Button onClick={save} disabled={!dirty || saving} data-testid="tpl-save">{saving ? "Saving…" : "Save template"}</Button>
          </div>
        </Card>
      )}
    </div>
  );
}
