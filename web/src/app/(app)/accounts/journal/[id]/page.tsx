"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Undo2 } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { useCurrentUser } from "@/context/AuthContext";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { INR2, SOURCE_LABEL, fmtDate } from "@/components/crm/shared";
import type { JournalDetail } from "@/types";

export default function JournalEntryPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useCurrentUser();
  const [e, setE] = useState<JournalDetail | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    try { const { data } = await api.get<JournalDetail>(`/finance/journal/${id}`); setE(data); setError(""); }
    catch (err) { setError(formatApiError(err)); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  const reverse = async () => {
    const reason = window.prompt("Why is this entry being reversed? (optional)");
    if (reason === null) return;
    setBusy(true);
    try { const { data } = await api.post<JournalDetail>(`/finance/journal/${id}/reverse`, { reason }); toast.success(`Reversed by ${data.entry_no}`); router.push(`/accounts/journal/${data.id}`); }
    catch (err) { toast.error(formatApiError(err)); setBusy(false); }
  };

  if (error) return <div className="space-y-3"><Button variant="ghost" size="sm" onClick={() => router.push("/accounts/journal")}><ArrowLeft size={16} /> Journal</Button><div className="text-sm text-destructive">{error}</div></div>;
  if (!e) return <div className="py-12 text-center text-sm text-muted-foreground">Loading…</div>;
  const dr = e.lines.reduce((a, l) => a + l.debit, 0);
  const cr = e.lines.reduce((a, l) => a + l.credit, 0);

  return (
    <div className="space-y-6" data-testid="journal-detail">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => router.push("/accounts/journal")} className="-ml-3 mb-2"><ArrowLeft size={16} /> Journal</Button>
          <div className="label-caps">{SOURCE_LABEL[e.source_type] ?? e.source_type} · {fmtDate(e.entry_date)}</div>
          <h1 className="mt-1 font-mono text-3xl font-black tracking-tight sm:text-4xl" data-testid="entry-no">{e.entry_no}</h1>
          <p className="mt-2 text-sm">{e.narration}</p>
          {e.reversed && <p className="mt-1 text-sm text-muted-foreground" data-testid="entry-reversed">This entry has been reversed.</p>}
          {e.is_reversal && e.reverses_id && <p className="mt-1 text-sm text-muted-foreground">Reversal of <Link href={`/accounts/journal/${e.reverses_id}`} className="underline underline-offset-2">the original entry</Link>.</p>}
        </div>
        {can("finance.journal") && e.editable_by_hand && <Button variant="outline" onClick={reverse} disabled={busy} data-testid="entry-reverse"><Undo2 size={16} /> Reverse entry</Button>}
      </div>

      <Card className="overflow-hidden rounded-lg">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[520px] text-sm">
            <thead><tr className="border-b border-border bg-secondary/50 text-left text-xs uppercase tracking-wide text-muted-foreground"><th className="px-4 py-2.5">Account</th><th className="px-2 py-2.5">Note</th><th className="px-2 py-2.5 text-right">Debit</th><th className="px-4 py-2.5 text-right">Credit</th></tr></thead>
            <tbody>
              {e.lines.map((l, i) => (
                <tr key={i} className="border-b border-border last:border-0" data-testid={`entry-line-${i}`}>
                  <td className="px-4 py-2.5"><Link href={`/accounts/ledger?account=${l.account_id}`} className="hover:underline"><span className="font-mono text-xs text-muted-foreground">{l.account_code}</span> {l.account_name}</Link></td>
                  <td className="px-2 py-2.5 text-muted-foreground">{l.memo ?? ""}</td>
                  <td className="px-2 py-2.5 text-right">{l.debit ? INR2(l.debit) : ""}</td>
                  <td className="px-4 py-2.5 text-right">{l.credit ? INR2(l.credit) : ""}</td>
                </tr>
              ))}
            </tbody>
            <tfoot><tr className="font-bold"><td className="px-4 py-3" colSpan={2}>Total</td><td className="px-2 py-3 text-right">{INR2(dr)}</td><td className="px-4 py-3 text-right">{INR2(cr)}</td></tr></tfoot>
          </table>
        </div>
      </Card>
    </div>
  );
}
