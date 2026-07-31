import { createContext, useContext, useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "@/lib/api";
import { toast } from "sonner";

const AuthContext = createContext(null);

// Inactivity timer: warn 60s before 1h idle; auto-logout at 1h.
const INACTIVITY_MS = 60 * 60 * 1000;    // 1 hour
const WARN_MS       = 59 * 60 * 1000;    // warn at 59min

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(false);
  const [warnOpen, setWarnOpen] = useState(false);
  const [warnSecondsLeft, setWarnSecondsLeft] = useState(60);
  const idleTimerRef = useRef(null);
  const warnTimerRef = useRef(null);
  const countdownRef = useRef(null);
  const nav = useNavigate();

  useEffect(() => {
    if (typeof window !== "undefined" && window.location.hash && window.location.hash.includes("session_id=")) {
      setReady(true);
      setUser(false);
      return;
    }
    (async () => {
      try {
        const { data } = await api.get("/auth/me");
        setUser(data);
      } catch (_e) { setUser(false); }
      finally { setReady(true); }
    })();
  }, []);

  const clearTimers = () => {
    if (idleTimerRef.current) clearTimeout(idleTimerRef.current);
    if (warnTimerRef.current) clearTimeout(warnTimerRef.current);
    if (countdownRef.current) clearInterval(countdownRef.current);
    idleTimerRef.current = warnTimerRef.current = countdownRef.current = null;
  };

  const doLogout = async () => {
    clearTimers();
    setWarnOpen(false);
    try { await api.post("/auth/logout"); } catch (_e) {}
    localStorage.removeItem("ir_access_token");
    setUser(false);
    nav("/login");
  };

  const armTimers = () => {
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
      doLogout();
    }, INACTIVITY_MS);
  };

  const stayLoggedIn = async () => {
    setWarnOpen(false);
    try { await api.post("/auth/refresh"); } catch (_e) {}
    armTimers();
  };

  useEffect(() => {
    if (!user || user === false) { clearTimers(); return; }
    armTimers();
    const reset = () => { if (!warnOpen) armTimers(); };
    const events = ["mousemove", "keydown", "click", "scroll", "touchstart"];
    events.forEach(e => window.addEventListener(e, reset, { passive: true }));
    return () => {
      events.forEach(e => window.removeEventListener(e, reset));
      clearTimers();
    };
    // eslint-disable-next-line
  }, [user, warnOpen]);

  const login = async (email, password) => {
    const { data } = await api.post("/auth/login", { email, password });
    if (data.access_token) localStorage.setItem("ir_access_token", data.access_token);
    setUser(data);
    if (data.password_reset_required) nav("/change-password");
    return data;
  };

  const logout = doLogout;

  return (
    <AuthContext.Provider value={{ user, ready, login, logout, setUser }}>
      {children}
      {warnOpen && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm" data-testid="inactivity-modal">
          <div className="bg-white rounded-xl shadow-xl max-w-md w-[92%] p-6 border border-border">
            <div className="label-caps">Security</div>
            <h3 className="font-display text-2xl font-bold mt-1">Your session is about to expire</h3>
            <p className="text-sm text-muted-foreground mt-2">
              You've been inactive for a while. You'll be logged out in <b>{warnSecondsLeft}</b> seconds.
            </p>
            <div className="flex justify-end gap-2 mt-5">
              <button onClick={doLogout} data-testid="idle-logout"
                      className="h-10 px-4 rounded-md border border-border font-medium text-sm hover:bg-secondary">
                Logout
              </button>
              <button onClick={stayLoggedIn} data-testid="idle-stay"
                      className="h-10 px-5 rounded-md text-white font-semibold text-sm"
                      style={{ background: "#106EBE" }}>
                Stay Logged In
              </button>
            </div>
          </div>
        </div>
      )}
    </AuthContext.Provider>
  );
}

export const useAuth = () => useContext(AuthContext);
