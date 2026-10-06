"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useMemo, useState } from "react";
import { Search, X } from "lucide-react";
import { usePagedList } from "@/lib/usePagedList";
import { useCurrentUser } from "@/context/AuthContext";
import { cn } from "@/lib/utils";
import { Pagination } from "@/components/ui/pagination";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import JournalDialog from "@/components/crm/JournalDialog";
import { INR2, SOURCE_LABEL, fmtDate } from "@/components/crm/shared";
import type { JournalSummary } from "@/types";

function JournalList() {
  const router = useRouter();
  const sp = useSearchParams();
  const { can } = useCurrentUser();
  const [q, setQ] = useState("");
  const [applied, setApplied] = useState("");
  const [source, setSource] = useState("all");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [open, setOpen] = useState(sp.get("new") === "1" && can("finance.journal"));
  const [tick, setTick] = useState(0);

  const params = useMemo(() => {
    const p: Record<string, string> = {};
    if (applied.trim()) p.q = applied.trim();
    if (source !== "all") p.source = source;
    if (from) p.from = from;
    if (to) p.to = to;
    return p;
  }, [applied, source, from, to]);
  const { rows, loading, loaded, error, paginationProps } = usePagedList<JournalSummary>("/finance/journal", params, tick);
  useEffect(() => { const t = setTimeout(() => setApplied(q), 300); return () => clearTimeout(t); }, [q]);
  const active = (q.trim() ? 1 : 0) + (source !== "all" ? 1 : 0) + (from ? 1 : 0) + (to ? 1 : 0);

  return (
    <div className="space-y-6" data-testid="journal-page">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="font-display text-2xl font-black tracking-tight">Journal</h2>
          <p className="mt-1 text-sm text-muted-foreground">Every posting to the books, newest first. Invoices and receipts post themselves; entries are never edited, only reversed.</p>
        </div>
        {can("finance.journal") && <Button onClick={() => setOpen(true)} data-testid="new-journal-btn">+ New entry</Button>}
      </div>

      <div className="flex flex-wrap items-center gap-3" data-testid="journal-filters">
        <div className="relative min-w-[220px] max-w-[320px] flex-1">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search entry number or narration…" className="h-10 pl-9" data-testid="journal-search" />
        </div>
        <Select value={source} onValueChange={setSource}>
          <SelectTrigger className="h-10 w-[170px]" data-testid="filter-journal-source"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">All sources</SelectItem>{Object.entries(SOURCE_LABEL).map(([k, v]) => <SelectItem key={k} value={k}>{v}</SelectItem>)}</SelectContent>
        </Select>
        <Input type="date" aria-label="From date" className="h-10 w-[150px]" value={from} onChange={(e) => setFrom(e.target.value)} />
        <Input type="date" aria-label="To date" className="h-10 w-[150px]" value={to} onChange={(e) => setTo(e.target.value)} />
        {active > 0 && <Button variant="ghost" size="sm" onClick={() => { setQ(""); setApplied(""); setSource("all"); setFrom(""); setTo(""); }}><X size={14} /> Clear filters</Button>}
      </div>

      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader><TableRow><TableHead>Entry</TableHead><TableHead>Date</TableHead><TableHead>Narration</TableHead><TableHead>Source</TableHead><TableHead className="text-right">Amount</TableHead></TableRow></TableHeader>
            <TableBody className={loading && loaded ? "opacity-60 transition-opacity" : "transition-opacity"}>
              {loading && !loaded && <TableRow><TableCell colSpan={5} className="py-8 text-center text-muted-foreground">Loading…</TableCell></TableRow>}
              {loaded && rows.length === 0 && <TableRow><TableCell colSpan={5} className="py-12 text-center text-muted-foreground">{active ? "No entries match these filters." : "Nothing posted yet."}</TableCell></TableRow>}
              {rows.map((e) => (
                <TableRow key={e.id} className="cursor-pointer hover:bg-secondary/50" onClick={() => router.push(`/accounts/journal/${e.id}`)} data-testid={`journal-entry-${e.id}`}>
                  <TableCell className={cn("font-mono text-sm", e.reversed && "line-through text-muted-foreground")}>{e.entry_no}</TableCell>
                  <TableCell className="text-sm">{fmtDate(e.entry_date)}</TableCell>
                  <TableCell className="max-w-[420px] truncate text-sm">{e.narration}{e.reversed && <span className="ml-2 text-xs text-muted-foreground">reversed</span>}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{SOURCE_LABEL[e.source_type] ?? e.source_type}</TableCell>
                  <TableCell className="text-right font-semibold">{INR2(e.total)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Pagination {...paginationProps} />
      </Card>
      {error && <div className="text-sm text-destructive">{error}</div>}
      <JournalDialog open={open} onOpenChange={setOpen} onSaved={(e) => { setTick((t) => t + 1); router.push(`/accounts/journal/${e.id}`); }} />
    </div>
  );
}

export default function JournalPage() {
  return <Suspense fallback={null}><JournalList /></Suspense>;
}
