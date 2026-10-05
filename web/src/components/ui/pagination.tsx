"use client";

import { useState } from "react";
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from "lucide-react";
import { cn } from "@/lib/utils";

export const PAGE_SIZE_OPTIONS = [10, 25, 50, 100] as const;
export const DEFAULT_PAGE_SIZE = 25;

/** Compact page window: 1 ... 4 5 6 ... 25 (null = ellipsis). */
export function pageWindow(page: number, pages: number): (number | null)[] {
  if (pages <= 7) return Array.from({ length: pages }, (_, i) => i + 1);
  const set = new Set<number>([1, pages, page - 1, page, page + 1]);
  if (page <= 3) { set.add(2); set.add(3); set.add(4); }
  if (page >= pages - 2) { set.add(pages - 1); set.add(pages - 2); set.add(pages - 3); }
  const nums = [...set].filter((n) => n >= 1 && n <= pages).sort((a, b) => a - b);
  const out: (number | null)[] = [];
  nums.forEach((n, i) => {
    if (i > 0 && n - nums[i - 1] > 1) out.push(null);
    out.push(n);
  });
  return out;
}

export interface PaginationProps {
  page: number;
  pageSize: number;
  total: number;
  onPageChange: (page: number) => void;
  onPageSizeChange?: (size: number) => void;
  pageSizeOptions?: readonly number[];
  disabled?: boolean;
  className?: string;
}

const btn =
  "inline-flex h-8 min-w-8 items-center justify-center rounded border border-border bg-card px-2 text-xs font-medium text-foreground transition-colors hover:bg-secondary focus-visible:outline-none focus-visible:border-black dark:focus-visible:border-white/60 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-card";

export function Pagination({
  page, pageSize, total, onPageChange, onPageSizeChange,
  pageSizeOptions = PAGE_SIZE_OPTIONS, disabled, className,
}: PaginationProps) {
  const pages = Math.max(1, Math.ceil(total / Math.max(1, pageSize)));
  const cur = Math.min(Math.max(1, page), pages);
  const from = total === 0 ? 0 : (cur - 1) * pageSize + 1;
  const to = Math.min(total, cur * pageSize);
  const go = (p: number) => { if (!disabled && p >= 1 && p <= pages && p !== cur) onPageChange(p); };
  const sizes = pageSizeOptions.includes(pageSize) ? pageSizeOptions : [...pageSizeOptions, pageSize].sort((a, b) => a - b);

  return (
    <nav
      aria-label="Pagination"
      data-testid="pagination"
      className={cn("flex flex-col gap-3 border-t border-border bg-card px-4 py-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between", className)}
    >
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span data-testid="pagination-summary" aria-live="polite">
          Showing <span className="font-medium text-foreground">{from}–{to}</span> of <span className="font-medium text-foreground">{total.toLocaleString("en-IN")}</span>
        </span>
        {onPageSizeChange && (
          <label className="flex items-center gap-2">
            <span>Rows per page</span>
            <select
              value={pageSize}
              disabled={disabled}
              onChange={(e) => onPageSizeChange(Number(e.target.value))}
              className="h-8 rounded border border-input bg-card px-2 text-xs text-foreground focus-visible:outline-none focus-visible:border-black dark:focus-visible:border-white/60 disabled:opacity-50"
              data-testid="page-size"
              aria-label="Rows per page"
            >
              {sizes.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </label>
        )}
      </div>
      <div className="flex flex-wrap items-center gap-1">
        <button type="button" className={btn} onClick={() => go(1)} disabled={disabled || cur <= 1} aria-label="First page" data-testid="page-first"><ChevronsLeft size={14} /></button>
        <button type="button" className={btn} onClick={() => go(cur - 1)} disabled={disabled || cur <= 1} aria-label="Previous page" data-testid="page-prev"><ChevronLeft size={14} /></button>
        {pageWindow(cur, pages).map((n, i) =>
          n === null ? (
            <span key={`e${i}`} className="px-1 select-none" aria-hidden>…</span>
          ) : (
            <button
              key={n} type="button" onClick={() => go(n)} disabled={disabled}
              aria-label={`Page ${n}`} aria-current={n === cur ? "page" : undefined}
              className={cn(btn, n === cur && "border-[#0A0A0A] bg-[#0A0A0A] text-white hover:bg-[#0A0A0A] disabled:hover:bg-[#0A0A0A] dark:border-white dark:bg-white dark:text-neutral-900 dark:hover:bg-white dark:disabled:hover:bg-white")}
              data-testid={`page-${n}`}
            >{n}</button>
          )
        )}
        <button type="button" className={btn} onClick={() => go(cur + 1)} disabled={disabled || cur >= pages} aria-label="Next page" data-testid="page-next"><ChevronRight size={14} /></button>
        <button type="button" className={btn} onClick={() => go(pages)} disabled={disabled || cur >= pages} aria-label="Last page" data-testid="page-last"><ChevronsRight size={14} /></button>
      </div>
    </nav>
  );
}

/**
 * Client-side slicing of an in-memory array. Resets to page 1 whenever `resetKey`
 * changes (pass the active filters), and clamps when the page falls out of range.
 */
export function usePagination<T>(items: readonly T[], opts: { pageSize?: number; resetKey?: string } = {}) {
  const [page, setPageRaw] = useState(1);
  const [pageSize, setPageSizeRaw] = useState(opts.pageSize ?? DEFAULT_PAGE_SIZE);
  const [lastKey, setLastKey] = useState(opts.resetKey ?? "");
  const key = opts.resetKey ?? "";
  let curPage = page;
  if (key !== lastKey) { setLastKey(key); setPageRaw(1); curPage = 1; }
  const total = items.length;
  const pages = Math.max(1, Math.ceil(total / pageSize));
  const safePage = Math.min(Math.max(1, curPage), pages);
  const pageItems = items.slice((safePage - 1) * pageSize, safePage * pageSize);
  return {
    page: safePage,
    pageSize,
    total,
    pages,
    pageItems,
    setPage: (p: number) => setPageRaw(Math.min(Math.max(1, p), pages)),
    setPageSize: (s: number) => { setPageSizeRaw(s); setPageRaw(1); },
    paginationProps: {
      page: safePage, pageSize, total,
      onPageChange: (p: number) => setPageRaw(Math.min(Math.max(1, p), pages)),
      onPageSizeChange: (s: number) => { setPageSizeRaw(s); setPageRaw(1); },
    } satisfies PaginationProps,
  };
}

/** "Load more" for lists that can grow inside cards (notes, timelines, lanes). */
export function useLoadMore<T>(items: readonly T[], step = 15, resetKey = "") {
  const [count, setCount] = useState(step);
  const [lastKey, setLastKey] = useState(resetKey);
  let cur = count;
  if (resetKey !== lastKey) { setLastKey(resetKey); setCount(step); cur = step; }
  return {
    visible: items.slice(0, cur),
    remaining: Math.max(0, items.length - cur),
    total: items.length,
    more: () => setCount(cur + step),
    less: () => setCount(step),
    expanded: cur > step,
    step,
  };
}

export function LoadMore({ lm, className }: { lm: Pick<ReturnType<typeof useLoadMore>, "remaining" | "more" | "less" | "expanded" | "step" | "total">; className?: string }) {
  if (lm.remaining === 0 && !lm.expanded) return null;
  return (
    <div className={cn("flex items-center justify-center gap-3 pt-2 text-xs", className)} data-testid="load-more">
      {lm.remaining > 0 && (
        <button type="button" onClick={lm.more} className="rounded border border-border bg-card px-3 py-1.5 font-medium hover:bg-secondary focus-visible:outline-none focus-visible:border-black dark:focus-visible:border-white/60" data-testid="load-more-btn">
          Load {Math.min(lm.step, lm.remaining)} more <span className="text-muted-foreground">({lm.remaining} remaining)</span>
        </button>
      )}
      {lm.expanded && (
        <button type="button" onClick={lm.less} className="text-muted-foreground underline underline-offset-2 hover:text-foreground" data-testid="show-less-btn">Show less</button>
      )}
    </div>
  );
}
