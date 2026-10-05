"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { api, formatApiError } from "@/lib/api";
import { DEFAULT_PAGE_SIZE, PAGE_SIZE_OPTIONS } from "@/components/ui/pagination";

interface Paged<T> { items: T[]; total: number; page: number; per_page: number; pages: number }

/**
 * Fetch one page. Uses the server pagination contract ({items,total,...}); if the
 * endpoint still returns a bare array, falls back to slicing client-side.
 */
export async function fetchPage<T>(path: string, params: Record<string, string>, page: number, perPage: number) {
  const { data } = await api.get<Paged<T> | T[]>(path, { params: { ...params, page, per_page: perPage } });
  if (Array.isArray(data)) {
    return { items: data.slice((page - 1) * perPage, page * perPage), total: data.length };
  }
  return { items: data.items ?? [], total: data.total ?? (data.items?.length ?? 0) };
}

/**
 * Fetch ALL rows for the filters (CSV export). The un-paged legacy response is capped
 * server-side (default 500), so walk the paged contract at the max page size instead;
 * if the endpoint still answers with a bare array, use that.
 */
export async function fetchAll<T>(path: string, params: Record<string, string>): Promise<T[]> {
  const PER = 200;
  const out: T[] = [];
  for (let page = 1; page <= 500; page++) {
    const { data } = await api.get<Paged<T> | T[]>(path, { params: { ...params, page, per_page: PER, limit: 100000 } });
    if (Array.isArray(data)) return data;
    out.push(...(data.items ?? []));
    if (page >= (data.pages ?? 1) || (data.items ?? []).length === 0) break;
  }
  return out;
}

const sizeOk = (n: number) => (PAGE_SIZE_OPTIONS as readonly number[]).includes(n);

/**
 * Server-side paged list with page/per_page persisted in the URL (?page=2&per_page=50).
 * Keeps the previous rows while loading, ignores stale responses and resets to page 1
 * when `params` (filters/search) change. Must be rendered under a <Suspense> boundary.
 */
export function usePagedList<T>(path: string, params: Record<string, string>, refreshTick = 0) {
  const router = useRouter();
  const pathname = usePathname();
  const sp = useSearchParams();
  const rawPage = parseInt(sp.get("page") ?? "", 10);
  const rawSize = parseInt(sp.get("per_page") ?? "", 10);
  const page = Number.isFinite(rawPage) && rawPage >= 1 ? rawPage : 1;
  const perPage = sizeOk(rawSize) ? rawSize : DEFAULT_PAGE_SIZE;

  const [rows, setRows] = useState<T[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [loaded, setLoaded] = useState(false);
  const [shown, setShown] = useState({ page: 1, perPage: DEFAULT_PAGE_SIZE });
  const [error, setError] = useState("");
  const reqId = useRef(0);
  const lastKey = useRef<string | null>(null);
  const key = JSON.stringify(params);

  const setUrl = useCallback((p: number, size: number) => {
    const next = new URLSearchParams(typeof window !== "undefined" ? window.location.search : "");
    if (p <= 1) next.delete("page"); else next.set("page", String(p));
    if (size === DEFAULT_PAGE_SIZE) next.delete("per_page"); else next.set("per_page", String(size));
    const qs = next.toString();
    router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
  }, [router, pathname]);

  useEffect(() => {
    if (lastKey.current !== null && lastKey.current !== key && page !== 1) {
      lastKey.current = key;
      setUrl(1, perPage);
      return; // URL change re-runs this effect with page 1
    }
    lastKey.current = key;
    const id = ++reqId.current;
    setLoading(true);
    fetchPage<T>(path, params, page, perPage)
      .then((r) => {
        if (id !== reqId.current) return;
        const pages = Math.max(1, Math.ceil(r.total / perPage));
        if (page > pages) { setUrl(pages, perPage); return; }
        setRows(r.items); setTotal(r.total); setShown({ page, perPage }); setError(""); setLoaded(true); setLoading(false);
      })
      .catch((e) => { if (id !== reqId.current) return; setError(formatApiError(e)); setLoading(false); });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, key, page, perPage, refreshTick]);

  return {
    rows, total, loading, loaded, error, page, perPage,
    paginationProps: {
      // Display what is actually on screen (previous rows stay until the new page lands).
      page: loaded ? shown.page : page, pageSize: loaded ? shown.perPage : perPage, total, disabled: !loaded,
      onPageChange: (p: number) => setUrl(p, perPage),
      onPageSizeChange: (s: number) => setUrl(1, s),
    },
  };
}
