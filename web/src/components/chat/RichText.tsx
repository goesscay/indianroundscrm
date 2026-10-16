import Link from "next/link";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

// Lead ids (IR1234, IRSA00012...) or http(s) URLs.
const TOKEN = /(\bIR[A-Z]{0,2}\d{4,8}\b|https?:\/\/[^\s<>"']+)/g;

function renderLine(line: string, keyPrefix: string, mine: boolean): ReactNode[] {
  const out: ReactNode[] = [];
  const linkCls = cn2(mine);
  line.split(TOKEN).forEach((part, i) => {
    if (!part) return;
    const key = `${keyPrefix}-${i}`;
    if (/^https?:\/\//.test(part)) {
      // Peel trailing punctuation off the URL.
      const m = part.match(/^(.*?)([.,;:!?)\]]*)$/);
      const url = m ? m[1] : part;
      const tail = m ? m[2] : "";
      out.push(
        <a key={key} href={url} target="_blank" rel="noopener noreferrer" className={linkCls}>
          {url}
        </a>
      );
      if (tail) out.push(tail);
    } else if (/^IR[A-Z]{0,2}\d{4,8}$/.test(part)) {
      out.push(
        <Link key={key} href={`/leads/${part}`} className={linkCls} data-testid="chat-lead-link">
          {part}
        </Link>
      );
    } else {
      out.push(part);
    }
  });
  return out;
}

function cn2(mine: boolean) {
  return mine ? "underline underline-offset-2 text-white dark:text-neutral-900 font-medium break-all" : "underline underline-offset-2 text-neutral-900 dark:text-neutral-100 font-medium break-all";
}

const escapeRe = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/** Splits text around "@Name" for the people tagged on the message; the tag for `meName` is lit up. */
function withMentions(line: string, keyPrefix: string, mine: boolean, mentionNames: string[], meName: string | undefined): ReactNode[] {
  if (mentionNames.length === 0) return renderLine(line, keyPrefix, mine);
  const names = [...mentionNames].sort((a, b) => b.length - a.length);
  const re = new RegExp(`(@(?:${names.map(escapeRe).join("|")}))(?![\\p{L}\\p{N}])`, "gu");
  const out: ReactNode[] = [];
  line.split(re).forEach((part, i) => {
    if (!part) return;
    if (i % 2 === 1) {
      const isMe = meName && part.slice(1) === meName;
      out.push(
        <span key={`${keyPrefix}-m${i}`} data-testid="chat-mention"
              className={cn("rounded px-1 font-semibold", isMe ? "bg-amber-300 text-amber-950" : mine ? "bg-white/20 text-white dark:bg-black/15 dark:text-neutral-900" : "bg-sky-100 text-sky-900 dark:bg-sky-950/60 dark:text-sky-200")}>
          {part}
        </span>
      );
    } else {
      out.push(...renderLine(part, `${keyPrefix}-${i}`, mine));
    }
  });
  return out;
}

/** Safe text renderer: preserves line breaks, linkifies lead ids and URLs, lights up @mentions. No raw HTML. */
export default function RichText({ text, mine = false, mentionNames = [], meName }: { text: string; mine?: boolean; mentionNames?: string[]; meName?: string }) {
  const lines = text.split("\n");
  return (
    <>
      {lines.map((l, i) => (
        <span key={i}>
          {withMentions(l, `l${i}`, mine, mentionNames, meName)}
          {i < lines.length - 1 && <br />}
        </span>
      ))}
    </>
  );
}
