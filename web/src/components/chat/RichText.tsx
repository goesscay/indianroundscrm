import Link from "next/link";
import type { ReactNode } from "react";

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
  return mine ? "underline underline-offset-2 text-white font-medium break-all" : "underline underline-offset-2 text-neutral-900 font-medium break-all";
}

/** Safe text renderer: preserves line breaks, linkifies lead ids and URLs. No raw HTML. */
export default function RichText({ text, mine = false }: { text: string; mine?: boolean }) {
  const lines = text.split("\n");
  return (
    <>
      {lines.map((l, i) => (
        <span key={i}>
          {renderLine(l, `l${i}`, mine)}
          {i < lines.length - 1 && <br />}
        </span>
      ))}
    </>
  );
}
