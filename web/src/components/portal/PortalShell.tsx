"use client";

import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from "react";
import { Loader2, LogOut, ShieldCheck } from "lucide-react";
import { api, apiErrorStatus, formatApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export interface PortalUser { name: string; email: string; company: string | null }

/** Sign-in with a one-time code mailed to a registered address. No passwords. */
function PortalLogin({ onDone }: { onDone: (u: PortalUser) => void }) {
  const [step, setStep] = useState<"email" | "code">("email");
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  const [error, setError] = useState("");
  const [cooldown, setCooldown] = useState(0);

  useEffect(() => {
    if (cooldown <= 0) return;
    const t = setTimeout(() => setCooldown((c) => c - 1), 1000);
    return () => clearTimeout(t);
  }, [cooldown]);

  const sendCode = async (e?: FormEvent) => {
    e?.preventDefault();
    setBusy(true); setError("");
    try {
      const { data } = await api.post<{ message: string }>("/portal/auth/code", { email: email.trim() });
      setMsg(data.message); setStep("code"); setCooldown(30);
    } catch (err) { setError(formatApiError(err)); }
    finally { setBusy(false); }
  };

  const verify = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true); setError("");
    try {
      const { data } = await api.post<PortalUser>("/portal/auth/verify", { email: email.trim(), code: code.trim() });
      onDone(data);
    } catch (err) { setError(formatApiError(err)); }
    finally { setBusy(false); }
  };

  return (
    <div className="mx-auto mt-10 max-w-sm rounded-xl bg-white p-6 shadow-sm" data-testid="portal-login">
      <h1 className="text-xl font-bold">Sign in</h1>
      <p className="mt-1 text-sm text-neutral-600">Use the e-mail address we wrote to. We will send you a one-time code.</p>
      {step === "email" ? (
        <form onSubmit={sendCode} className="mt-5 space-y-4">
          <div>
            <Label htmlFor="portal-email">E-mail address</Label>
            <Input id="portal-email" type="email" autoComplete="email" required autoFocus className="mt-1.5" value={email} onChange={(e) => setEmail(e.target.value)} data-testid="portal-email" />
          </div>
          {error && <div className="text-sm text-red-700" role="alert">{error}</div>}
          <Button type="submit" className="w-full" disabled={busy || !email.trim()} data-testid="portal-send-code">{busy ? "Sending…" : "Send me a code"}</Button>
        </form>
      ) : (
        <form onSubmit={verify} className="mt-5 space-y-4">
          {msg && <div className="rounded-md bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{msg}</div>}
          <div>
            <Label htmlFor="portal-code">6-digit code</Label>
            <Input id="portal-code" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]*" maxLength={6} required autoFocus
                   className="mt-1.5 text-center text-lg tracking-[0.4em]" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))} data-testid="portal-code" />
          </div>
          {error && <div className="text-sm text-red-700" role="alert">{error}</div>}
          <Button type="submit" className="w-full" disabled={busy || code.length < 6} data-testid="portal-verify">{busy ? "Checking…" : "Sign in"}</Button>
          <div className="flex items-center justify-between text-xs text-neutral-600">
            <button type="button" className="underline underline-offset-2" onClick={() => { setStep("email"); setCode(""); setError(""); }}>Use a different address</button>
            <button type="button" className="underline underline-offset-2 disabled:no-underline disabled:opacity-50" disabled={cooldown > 0 || busy} onClick={() => void sendCode()}>
              {cooldown > 0 ? `Send again in ${cooldown}s` : "Send again"}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

/** Frame for every portal page: handles sign-in, shows who is signed in, and renders the page once there is a session. */
export default function PortalShell({ children }: { children: (user: PortalUser) => ReactNode }) {
  const [user, setUser] = useState<PortalUser | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    let live = true;
    api.get<PortalUser>("/portal/me")
      .then((r) => { if (live) setUser(r.data); })
      .catch((e) => { if (live && apiErrorStatus(e) !== 401) console.error(e); })
      .finally(() => { if (live) setReady(true); });
    return () => { live = false; };
  }, []);

  const signOut = useCallback(async () => {
    try { await api.post("/portal/auth/logout"); } catch { /* already out */ }
    setUser(null);
  }, []);

  // a session that ends while a page is open (expired, access removed) returns to sign-in
  useEffect(() => {
    const id = api.interceptors.response.use(undefined, (err) => {
      if (apiErrorStatus(err) === 401 && String(err?.config?.url || "").startsWith("/portal/") && !String(err.config.url).startsWith("/portal/auth/")) setUser(null);
      throw err;
    });
    return () => api.interceptors.response.eject(id);
  }, []);

  return (
    <div className="min-h-[100dvh] bg-neutral-100 px-4 py-6 text-neutral-900 sm:py-10" data-testid="portal-root">
      <div className="mx-auto max-w-3xl">
        <header className="mb-6 flex items-center justify-between gap-3">
          <a href="/portal" className="flex items-center gap-3">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/icons/icon-192.png" alt="" className="h-10 w-10 rounded-xl" />
            <div className="leading-tight">
              <div className="text-lg font-bold">Indian Rounds</div>
              <div className="text-xs text-neutral-500">Supplier portal</div>
            </div>
          </a>
          {user && (
            <div className="flex items-center gap-3 text-right text-xs text-neutral-600">
              <div className="hidden sm:block"><div className="font-medium text-neutral-900">{user.company || user.name}</div><div>{user.email}</div></div>
              <Button variant="outline" size="sm" onClick={signOut} data-testid="portal-signout"><LogOut size={14} /> Sign out</Button>
            </div>
          )}
        </header>

        {!ready && <div className="p-10 text-center text-sm text-neutral-500"><Loader2 className="mx-auto mb-2 animate-spin" size={18} />Loading…</div>}
        {ready && !user && <PortalLogin onDone={setUser} />}
        {ready && user && children(user)}

        <p className="mt-8 flex items-center justify-center gap-1.5 text-center text-xs text-neutral-500"><ShieldCheck size={13} />Private page for our suppliers. Your quotes are seen only by the Indian Rounds team.</p>
      </div>
    </div>
  );
}
