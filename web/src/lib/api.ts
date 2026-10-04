import axios, { AxiosError, type InternalAxiosRequestConfig } from "axios";

/**
 * Same-origin API client for the Laravel (Sanctum SPA cookie auth) backend.
 * `/api/*` and `/sanctum/*` are proxied to Laravel by next.config.ts rewrites.
 */
export const API = "/api";

export const api = axios.create({
  baseURL: API,
  withCredentials: true,
  headers: { Accept: "application/json" },
});

const MUTATING = new Set(["post", "put", "patch", "delete"]);

function readXsrfCookie(): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
}

let csrfInFlight: Promise<void> | null = null;

/** GET /sanctum/csrf-cookie so Laravel sets the XSRF-TOKEN cookie. */
export function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readXsrfCookie()) return Promise.resolve();
  if (!csrfInFlight) {
    csrfInFlight = axios
      .get("/sanctum/csrf-cookie", { withCredentials: true, headers: { Accept: "application/json" } })
      .then(() => undefined)
      .finally(() => {
        csrfInFlight = null;
      });
  }
  return csrfInFlight;
}

api.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  const method = (config.method || "get").toLowerCase();
  if (MUTATING.has(method)) {
    await ensureCsrfCookie();
    const token = readXsrfCookie();
    if (token) config.headers.set("X-XSRF-TOKEN", token);
  }
  return config;
});

// CSRF token mismatch (HTTP 419): refresh the cookie and retry once.
api.interceptors.response.use(undefined, async (error: AxiosError) => {
  const cfg = error.config as (InternalAxiosRequestConfig & { _csrfRetried?: boolean }) | undefined;
  if (error.response?.status === 419 && cfg && !cfg._csrfRetried) {
    cfg._csrfRetried = true;
    await ensureCsrfCookie(true);
    return api.request(cfg);
  }
  throw error;
});

interface ApiErrorBody {
  detail?: unknown;
  message?: string;
  errors?: Record<string, string[] | string>;
}

/** Turn FastAPI `{detail}` / Laravel `{message, errors}` errors into one readable string. */
export function formatApiError(err: unknown): string {
  const e = err as { response?: { data?: ApiErrorBody }; message?: string } | undefined;
  const data = e?.response?.data;
  const detail = data?.detail;
  if (detail != null) {
    if (typeof detail === "string") return detail;
    if (Array.isArray(detail)) {
      return detail
        .map((d) => (d && typeof d === "object" && "msg" in d ? String((d as { msg: unknown }).msg) : JSON.stringify(d)))
        .join(" ");
    }
    if (typeof detail === "object" && detail) {
      const d = detail as { msg?: unknown; message?: unknown };
      if (typeof d.msg === "string") return d.msg;
      if (typeof d.message === "string") return d.message;
    }
    return String(detail);
  }
  if (data?.errors && typeof data.errors === "object") {
    const msgs = Object.values(data.errors).flatMap((v) => (Array.isArray(v) ? v : [v]));
    if (msgs.length) return msgs.join(" ");
  }
  if (data?.message) return data.message;
  return e?.message || "Something went wrong";
}

/** HTTP status of an API error, if any. */
export function apiErrorStatus(err: unknown): number | undefined {
  return (err as { response?: { status?: number } } | undefined)?.response?.status;
}
