"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { useAuth } from "@/context/AuthContext";
import AiPanel from "./AiPanel";

interface AiPanelContextValue {
  open: boolean;
  openAi: () => void;
  closeAi: () => void;
  toggleAi: () => void;
}

const AiPanelContext = createContext<AiPanelContextValue | null>(null);

export function AiPanelProvider({ children }: { children: ReactNode }) {
  const { can } = useAuth();
  const allowed = can("ai.use");
  const [open, setOpen] = useState(false);
  const openAi = useCallback(() => setOpen(true), []);
  const closeAi = useCallback(() => setOpen(false), []);
  const toggleAi = useCallback(() => setOpen((o) => !o), []);

  useEffect(() => {
    if (!allowed) return;
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === "j") {
        e.preventDefault();
        setOpen((o) => !o);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [allowed]);

  const value = useMemo(() => ({ open, openAi, closeAi, toggleAi }), [open, openAi, closeAi, toggleAi]);
  return (
    <AiPanelContext.Provider value={value}>
      {children}
      {allowed && <AiPanel open={open} onClose={closeAi} />}
    </AiPanelContext.Provider>
  );
}

export function useAiPanel(): AiPanelContextValue {
  const ctx = useContext(AiPanelContext);
  if (!ctx) throw new Error("useAiPanel must be used within an AiPanelProvider");
  return ctx;
}
