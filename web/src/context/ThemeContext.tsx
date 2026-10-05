"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";

export type ThemePreference = "light" | "dark" | "system";
export const THEME_KEY = "ir-theme";

interface ThemeContextValue {
  preference: ThemePreference;
  resolved: "light" | "dark";
  setPreference: (p: ThemePreference) => void;
}

const ThemeContext = createContext<ThemeContextValue | null>(null);

function readPreference(): ThemePreference {
  try {
    const v = localStorage.getItem(THEME_KEY);
    if (v === "light" || v === "dark" || v === "system") return v;
  } catch {
    /* storage unavailable */
  }
  return "system";
}

function systemPrefersDark(): boolean {
  try {
    return window.matchMedia("(prefers-color-scheme: dark)").matches;
  } catch {
    return false;
  }
}

function applyTheme(dark: boolean) {
  const el = document.documentElement;
  el.classList.toggle("dark", dark);
  el.style.colorScheme = dark ? "dark" : "light";
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [preference, setPref] = useState<ThemePreference>("system");
  const [systemDark, setSystemDark] = useState(false);

  // Hydrate from storage after mount (the inline head script already applied the class).
  useEffect(() => {
    setPref(readPreference());
    setSystemDark(systemPrefersDark());
    let mq: MediaQueryList | null = null;
    const onMq = (e: MediaQueryListEvent) => setSystemDark(e.matches);
    try {
      mq = window.matchMedia("(prefers-color-scheme: dark)");
      mq.addEventListener("change", onMq);
    } catch {
      /* matchMedia unavailable */
    }
    const onStorage = (e: StorageEvent) => {
      if (e.key === THEME_KEY || e.key === null) setPref(readPreference());
    };
    window.addEventListener("storage", onStorage);
    return () => {
      mq?.removeEventListener("change", onMq);
      window.removeEventListener("storage", onStorage);
    };
  }, []);

  const resolved: "light" | "dark" = preference === "system" ? (systemDark ? "dark" : "light") : preference;

  useEffect(() => {
    applyTheme(resolved === "dark");
  }, [resolved]);

  const setPreference = useCallback((p: ThemePreference) => {
    setPref(p);
    try {
      localStorage.setItem(THEME_KEY, p);
    } catch {
      /* storage unavailable */
    }
  }, []);

  const value = useMemo(() => ({ preference, resolved, setPreference }), [preference, resolved, setPreference]);
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error("useTheme must be used within a ThemeProvider");
  return ctx;
}

/** Blocking script (runs before first paint) that applies the saved theme to avoid a flash. */
export const THEME_INIT_SCRIPT = `(function(){try{var p=null;try{p=localStorage.getItem('${THEME_KEY}')}catch(e){}var d=p==='dark'||(p!=='light'&&window.matchMedia('(prefers-color-scheme: dark)').matches);var el=document.documentElement;if(d)el.classList.add('dark');else el.classList.remove('dark');el.style.colorScheme=d?'dark':'light';}catch(e){}})();`;
