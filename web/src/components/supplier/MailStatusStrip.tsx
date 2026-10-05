"use client";

import { useCallback, useEffect, useState } from "react";
import { AlertTriangle, RefreshCw } from "lucide-react";
import { toast } from "sonner";
import { api, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { absTime, relTime } from "@/components/supplier/shared";
import type { MailPollResult, MailStatus } from "@/types";

/** Admin-only compact mailbox status + "Check now". */
export default function MailStatusStrip({ onPolled }: { onPolled?: () => void }) {
  const [status, setStatus] = useState<MailStatus | null>(null);
  const [unavailable, setUnavailable] = useState(false);
  const [polling, setPolling] = useState(false);

  const load = useCallback(async () => {
    try {
      const { data } = await api.get<MailStatus>("/mail/status");
      setStatus(data);
      setUnavailable(false);
    } catch {
      setUnavailable(true);
    }
  }, []);

  useEffect(() => {
    void load();
    const t = setInterval(() => { if (document.visibilityState === "visible") void load(); }, 60_000);
    return () => clearInterval(t);
  }, [load]);

  const checkNow = async () => {
    setPolling(true);
    try {
      const { data } = await api.post<MailPollResult>("/mail/poll");
      const msg = `Checked mailbox: ${data.fetched} fetched, ${data.new} new, ${data.matched} matched, ${data.unmatched} unmatched`;
      if (data.errors > 0) toast.warning(`${msg} (${data.errors} error${data.errors === 1 ? "" : "s"})`);
      else toast.success(msg);
      onPolled?.();
    } catch (e) {
      toast.error(formatApiError(e));
    } finally {
      setPolling(false);
      void load();
    }
  };

  if (unavailable && !status) {
    return (
      <div className="rounded-md border border-border bg-card px-4 py-2.5 text-xs text-muted-foreground" data-testid="mail-status-strip">
        Mailbox status is unavailable right now.
      </div>
    );
  }
  if (!status) return null;

  const live = status.transport === "graph" && status.configured;
  return (
    <div className="space-y-2" data-testid="mail-status-strip">
      <div className="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-md border border-border bg-card px-4 py-2.5 text-xs">
        <span className="font-semibold uppercase tracking-wide text-[10px] text-muted-foreground">Mailbox</span>
        <span className="inline-flex items-center gap-1.5">
          <span aria-hidden className={`h-2 w-2 rounded-full ${live ? "bg-emerald-500" : "bg-amber-500"}`} />
          <span data-testid="mail-transport">{live ? "Microsoft Graph" : status.transport === "graph" ? "Graph (not configured)" : "Log only (no real mail)"}</span>
        </span>
        <span className="min-w-0 truncate text-muted-foreground" title={status.mailbox || undefined}>
          {status.mailbox || "No mailbox set"}
        </span>
        <span className="text-muted-foreground" title={absTime(status.last_poll_at)}>
          Last checked: <span className="text-foreground">{status.last_poll_at ? relTime(status.last_poll_at) : "never"}</span>
        </span>
        {typeof status.unprocessed_count === "number" && status.unprocessed_count > 0 && (
          <span className="text-muted-foreground">{status.unprocessed_count} unprocessed</span>
        )}
        <Button size="sm" variant="outline" className="ml-auto" onClick={checkNow} disabled={polling} data-testid="mail-check-now">
          <RefreshCw size={13} className={polling ? "animate-spin" : undefined} aria-hidden /> {polling ? "Checking…" : "Check now"}
        </Button>
      </div>
      {status.last_error && (
        <div role="alert" className="flex items-start gap-2 rounded-md border border-red-300 bg-red-50 px-4 py-2.5 text-xs text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" data-testid="mail-last-error">
          <AlertTriangle size={14} className="mt-px shrink-0" aria-hidden />
          <span className="break-words">Last mailbox error: {status.last_error}</span>
        </div>
      )}
    </div>
  );
}
