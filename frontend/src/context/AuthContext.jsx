import { createContext, useContext, useEffect, useState } from "react";
import { api } from "@/lib/api";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null); // null = loading
  const [ready, setReady] = useState(false);

  useEffect(() => {
    // CRITICAL: Skip /me check when returning from Google OAuth — AuthCallback will exchange session_id first.
    // REMINDER: DO NOT HARDCODE THE URL, OR ADD ANY FALLBACKS OR REDIRECT URLS, THIS BREAKS THE AUTH
    if (typeof window !== "undefined" && window.location.hash && window.location.hash.includes("session_id=")) {
      setReady(true);
      setUser(false);
      return;
    }
    (async () => {
      try {
        const { data } = await api.get("/auth/me");
        setUser(data);
      } catch (_e) {
        setUser(false);
      } finally {
        setReady(true);
      }
    })();
  }, []);

  const login = async (email, password) => {
    const { data } = await api.post("/auth/login", { email, password });
    if (data.access_token) localStorage.setItem("ir_access_token", data.access_token);
    setUser(data);
    return data;
  };

  const logout = async () => {
    try { await api.post("/auth/logout"); } catch (_e) { /* ignore */ }
    localStorage.removeItem("ir_access_token");
    setUser(false);
  };

  return (
    <AuthContext.Provider value={{ user, ready, login, logout, setUser }}>
      {children}
    </AuthContext.Provider>
  );
}

export const useAuth = () => useContext(AuthContext);
