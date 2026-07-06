import { useEffect, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { api, formatApiError } from "@/lib/api";
import { useAuth } from "@/context/AuthContext";
import { toast } from "sonner";

// REMINDER: DO NOT HARDCODE THE URL, OR ADD ANY FALLBACKS OR REDIRECT URLS, THIS BREAKS THE AUTH
export default function AuthCallback() {
  const nav = useNavigate();
  const { setUser } = useAuth();
  const done = useRef(false);

  useEffect(() => {
    if (done.current) return;
    done.current = true;
    const hash = window.location.hash || "";
    const m = hash.match(/session_id=([^&]+)/);
    if (!m) { nav("/login", { replace: true }); return; }
    const session_id = decodeURIComponent(m[1]);
    (async () => {
      try {
        const { data } = await api.post("/auth/google-callback", { session_id });
        if (data.access_token) localStorage.setItem("ir_access_token", data.access_token);
        setUser(data);
        window.history.replaceState({}, "", "/");
        nav("/", { replace: true });
      } catch (e) {
        toast.error(formatApiError(e));
        nav("/login", { replace: true });
      }
    })();
  }, [nav, setUser]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-background">
      <div className="text-sm text-muted-foreground">Completing Google sign-in…</div>
    </div>
  );
}
