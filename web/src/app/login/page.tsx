"use client";

/* eslint-disable @next/next/no-img-element */
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { useAuth } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { formatApiError } from "@/lib/api";

export default function Login() {
  const { user, login } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [err, setErr] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (user) router.replace(user.password_reset_required ? "/change-password" : "/");
  }, [user, router]);

  if (user) return null;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setErr(""); setLoading(true);
    try {
      const data = await login(email.trim().toLowerCase(), password);
      router.push(data?.password_reset_required ? "/change-password" : "/");
    } catch (e) {
      setErr(formatApiError(e));
    } finally { setLoading(false); }
  };

  return (
    <div className="min-h-screen grid grid-cols-1 lg:grid-cols-2 bg-background">
      {/* Left brand panel */}
      <div className="relative hidden lg:flex flex-col justify-between p-12 overflow-hidden bg-black"
           data-testid="login-brand-panel">
        <div className="relative z-10">
          <img src="/logo-dark.png" alt="Indian Rounds" className="w-56 h-auto" />
        </div>
        <div className="relative z-10 text-white">
          <h1 className="font-display text-4xl xl:text-5xl font-black tracking-tight leading-[1.05]">
            Every enquiry.<br />Every itinerary.<br />
            <span className="text-white/45">One CRM.</span>
          </h1>
          <p className="mt-6 max-w-md text-white/60 text-sm leading-relaxed">
            Capture leads, dispatch supplier RFQs in one click, and track bookings from first
            enquiry to travel-completed, with role-based control for Sales, Ops &amp; Admin.
          </p>
        </div>
        <div className="relative z-10 text-[10px] uppercase tracking-[0.3em] text-white/35">Travel beyond borders</div>
        <div className="absolute -right-32 -bottom-32 w-[28rem] h-[28rem] rounded-full border border-white/10" />
        <div className="absolute -right-12 -bottom-12 w-[20rem] h-[20rem] rounded-full border border-white/10" />
      </div>

      {/* Right form */}
      <div className="flex items-center justify-center p-6 md:p-12">
        <div className="w-full max-w-md">
          <div className="lg:hidden flex items-center gap-2 mb-8">
            <div className="rounded-md bg-black px-3 py-2">
              <img src="/logo-dark.png" alt="Indian Rounds" className="h-8 w-auto" />
            </div>
          </div>
          <div className="label-caps">Sign in</div>
          <h2 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-2">Welcome back.</h2>
          <p className="text-muted-foreground text-sm mt-2">Use your Indian Rounds credentials to continue.</p>

          <form onSubmit={submit} className="mt-8 space-y-4" data-testid="login-form">
            <div>
              <Label htmlFor="email" className="label-caps">Email</Label>
              <Input
                id="email" type="email" required
                value={email} onChange={(e) => setEmail(e.target.value)}
                placeholder="you@indianrounds.com"
                className="mt-1.5 h-11"
                data-testid="login-email"
              />
            </div>
            <div>
              <Label htmlFor="password" className="label-caps">Password</Label>
              <Input
                id="password" type="password" required
                value={password} onChange={(e) => setPassword(e.target.value)}
                className="mt-1.5 h-11"
                data-testid="login-password"
              />
            </div>
            {err && <div className="text-sm text-destructive font-medium" data-testid="login-error">{err}</div>}
            <Button
              type="submit" disabled={loading}
              className="w-full h-11 text-base font-semibold"
              style={{ background: "#0A0A0A" }}
              data-testid="login-submit"
            >
              {loading ? "Signing in…" : "Sign in →"}
            </Button>
          </form>

          <div className="mt-3 text-right">
            <Link href="/forgot-password" className="text-xs font-semibold text-[#0A0A0A] hover:underline" data-testid="login-forgot">
              Forgot password?
            </Link>
          </div>

          <div className="mt-8 p-4 rounded-md border border-border bg-secondary/40">
            <div className="label-caps mb-2">Demo Accounts</div>
            <div className="text-xs text-muted-foreground space-y-1 font-mono">
              <div>admin@indianrounds.com / admin123</div>
              <div>sales@indianrounds.com / Ir@2026Safe</div>
              <div>ops@indianrounds.com / ops123</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
