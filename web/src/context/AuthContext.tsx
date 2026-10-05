"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { api, ensureCsrfCookie } from "@/lib/api";
import type { User } from "@/types";

// `user` is null while unauthenticated; `ready` flips true after the first /auth/me check.
interface AuthContextValue {
  user: User | null;
  ready: boolean;
  login: (email: string, password: string) => Promise<User>;
  logout: () => Promise<void>;
  setUser: (u: User | null) => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

// Inactivity timer: warn 60s before 1h idle; auto-logout at 1h.
const INACTIVITY_MS = 60 * 60 * 1000; // 1 hour
const WARN_MS = 59 * 60 * 1000; // warn at 59min

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [ready, setReady] = useState(false);
  const [warnOpen, setWarnOpen] = useState(false);
  const [warnSecondsLeft, setWarnSecondsLeft] = useState(60);
  const idleTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const warnTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const countdownRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const router = useRouter();

  useEffect(() => {
    (async () => {
      try {
        const { data } = await api.get<User>("/auth/me");
        setUser(data);
      } catch {
        setUser(null);
      } finally {
        setReady(true);
      }
    })();
  }, []);

  const clearTimers = useCallback(() => {
    if (idleTimerRef.current) clearTimeout(idleTimerRef.current);
    if (warnTimerRef.current) clearTimeout(warnTimerRef.current);
    if (countdownRef.current) clearInterval(countdownRef.current);
    idleTimerRef.current = warnTimerRef.current = countdownRef.current = null;
  }, []);

  const doLogout = useCallback(async () => {
    clearTimers();
    setWarnOpen(false);
    try {
      await api.post("/auth/logout");
    } catch {
      /* ignore */
    }
    setUser(null);
    router.push("/login");
  }, [clearTimers, router]);

  const armTimers = useCallback(() => {
    clearTimers();
    warnTimerRef.current = setTimeout(() => {
      setWarnSecondsLeft(60);
      setWarnOpen(true);
      countdownRef.current = setInterval(() => {
        setWarnSecondsLeft((s) => Math.max(0, s - 1));
      }, 1000);
    }, WARN_MS);
    idleTimerRef.current = setTimeout(() => {
      toast.info("Session expired due to inactivity");
      void doLogout();
    }, INACTIVITY_MS);
  }, [clearTimers, doLogout]);

  const stayLoggedIn = async () => {
    setWarnOpen(false);
    try {
      await api.post("/auth/refresh");
    } catch {
      /* ignore */
    }
    armTimers();
  };

  useEffect(() => {
    if (!user) {
      clearTimers();
      return;
    }
    armTimers();
    const reset = () => {
      if (!warnOpen) armTimers();
    };
    const events = ["mousemove", "keydown", "click", "scroll", "touchstart"];
    events.forEach((e) => window.addEventListener(e, reset, { passive: true }));
    return () => {
      events.forEach((e) => window.removeEventListener(e, reset));
      clearTimers();
    };
  }, [user, warnOpen, armTimers, clearTimers]);

  const login = async (email: string, password: string) => {
    // Sanctum SPA auth: obtain a fresh XSRF-TOKEN cookie before posting credentials.
    await ensureCsrfCookie(true);
    const { data } = await api.post<User>("/auth/login", { email, password });
    setUser(data);
    if (data.password_reset_required) router.push("/change-password");
    return data;
  };

  return (
    <AuthContext.Provider value={{ user, ready, login, logout: doLogout, setUser }}>
      {children}
      {warnOpen && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm"
          data-testid="inactivity-modal"
        >
          <div className="bg-white rounded-xl shadow-xl max-w-md w-[92%] p-6 border border-border">
            <div className="label-caps">Security</div>
            <h3 className="font-display text-2xl font-bold mt-1">Your session is about to expire</h3>
            <p className="text-sm text-muted-foreground mt-2">
              You&apos;ve been inactive for a while. You&apos;ll be logged out in <b>{warnSecondsLeft}</b> seconds.
            </p>
            <div className="flex justify-end gap-2 mt-5">
              <button
                onClick={doLogout}
                data-testid="idle-logout"
                className="h-10 px-4 rounded-md border border-border font-medium text-sm hover:bg-secondary"
              >
                Logout
              </button>
              <button
                onClick={stayLoggedIn}
                data-testid="idle-stay"
                className="h-10 px-5 rounded-md text-white font-semibold text-sm"
                style={{ background: "#0A0A0A" }}
              >
                Stay Logged In
              </button>
            </div>
          </div>
        </div>
      )}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within an AuthProvider");
  return ctx;
}

/** Auth context for use inside protected routes, where a signed-in user is guaranteed. */
export function useCurrentUser(): Omit<AuthContextValue, "user"> & { user: User } {
  const ctx = useAuth();
  if (!ctx.user) throw new Error("useCurrentUser must be used within a protected route");
  return { ...ctx, user: ctx.user };
}
