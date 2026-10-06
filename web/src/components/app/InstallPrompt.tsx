"use client";

import { useEffect, useState } from "react";
import { Download, X } from "lucide-react";
import { Button } from "@/components/ui/button";

interface InstallEvent extends Event { prompt: () => Promise<void>; userChoice: Promise<{ outcome: string }> }

const DISMISS_KEY = "ir-install-dismissed";

/** Small "Install app" banner. Chrome/Edge/Android use the native prompt; iPhone/iPad Safari get an Add-to-Home-Screen hint. */
export default function InstallPrompt() {
  const [evt, setEvt] = useState<InstallEvent | null>(null);
  const [ios, setIos] = useState(false);
  const [hidden, setHidden] = useState(true);

  useEffect(() => {
    let dismissed = false;
    try { dismissed = localStorage.getItem(DISMISS_KEY) === "1"; } catch { /* storage blocked */ }
    const standalone = window.matchMedia("(display-mode: standalone)").matches || (navigator as unknown as { standalone?: boolean }).standalone === true;
    if (dismissed || standalone) return;
    const onPrompt = (e: Event) => { e.preventDefault(); setEvt(e as InstallEvent); setHidden(false); };
    const onInstalled = () => setHidden(true);
    window.addEventListener("beforeinstallprompt", onPrompt);
    window.addEventListener("appinstalled", onInstalled);
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) && !/crios|fxios/i.test(navigator.userAgent);
    if (isIos) { setIos(true); setHidden(false); }
    return () => { window.removeEventListener("beforeinstallprompt", onPrompt); window.removeEventListener("appinstalled", onInstalled); };
  }, []);

  const dismiss = () => { setHidden(true); try { localStorage.setItem(DISMISS_KEY, "1"); } catch { /* ignore */ } };
  const install = async () => { if (!evt) return; await evt.prompt(); await evt.userChoice; setEvt(null); setHidden(true); };

  if (hidden || (!evt && !ios)) return null;
  return (
    <div className="fixed bottom-4 left-4 z-50 flex max-w-[calc(100vw-2rem)] items-center gap-3 rounded-lg border border-border bg-card p-3 pr-2 shadow-lg" role="dialog" aria-label="Install app" data-testid="install-prompt">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src="/icons/icon-192.png" alt="" className="h-9 w-9 rounded" />
      <div className="text-sm">
        <div className="font-semibold">Install Indian Rounds CRM</div>
        <div className="text-xs text-muted-foreground">{ios && !evt ? "Tap Share, then Add to Home Screen." : "Opens in its own window, like an app."}</div>
      </div>
      {evt && <Button size="sm" onClick={install} data-testid="install-btn"><Download size={14} className="mr-1" />Install</Button>}
      <button type="button" aria-label="Dismiss" onClick={dismiss} className="rounded p-1 text-muted-foreground hover:text-foreground"><X size={16} /></button>
    </div>
  );
}
