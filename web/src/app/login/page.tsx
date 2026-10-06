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
    <div className="min-h-[100dvh] grid grid-cols-1 lg:grid-cols-2 bg-background">
      {/* Left brand panel */}
      <div className="relative hidden lg:flex flex-col justify-between p-12 overflow-hidden bg-card border-r border-border"
           data-testid="login-brand-panel">
        <img
          src="/login-bg.jpg" alt=""
          className="absolute inset-0 w-full h-full object-cover grayscale opacity-[0.28]"
        />
        <div className="absolute inset-0 bg-gradient-to-b from-background/95 via-background/55 to-background/95" />

        <div className="relative z-10">
          <img src="/logo-word-dark.png" alt="Indian Rounds" className="h-9 w-auto dark:hidden" />
          <img src="/logo-word.png" alt="Indian Rounds" className="h-9 w-auto hidden dark:block" />
        </div>

        <div className="relative z-10 text-foreground">
          <div className="text-[11px] uppercase tracking-[0.2em] text-muted-foreground mb-5">Indian Rounds · Travel ERP</div>
          <h1 className="font-display text-5xl xl:text-6xl tracking-tight leading-[1.05]">
            Every enquiry.<br />Every itinerary.<br />
            <span className="font-serif italic font-normal text-muted-foreground">One ERP.</span>
          </h1>
          <p className="mt-6 max-w-md text-muted-foreground text-[15px] leading-relaxed">
            Capture leads, dispatch supplier RFQs in one click, and track bookings from first
            enquiry to travel-completed, with role-based control for Sales, Ops &amp; Admin.
          </p>
        </div>

        <div className="relative z-10 text-[10px] uppercase tracking-[0.3em] text-muted-foreground">Travel beyond borders</div>
      </div>

      {/* Right form */}
      <div className="flex min-h-[100dvh] items-center justify-center px-5 py-8 pt-[max(2rem,env(safe-area-inset-top))] pb-[max(2rem,env(safe-area-inset-bottom))] sm:px-10 lg:min-h-0">
        <div className="w-full max-w-[22rem]">
          <div className="lg:hidden mb-6 flex flex-col items-center text-center">
            <img src="/icons/icon-192.png" alt="" className="mb-3 h-16 w-16 rounded-2xl shadow-sm" />
            <img src="/logo-word-dark.png" alt="Indian Rounds" className="h-8 w-auto dark:hidden" />
            <img src="/logo-word.png" alt="Indian Rounds" className="hidden h-8 w-auto dark:block" />
          </div>

          <h2 className="font-display text-2xl tracking-tight text-center lg:text-left lg:text-[28px]">Sign in</h2>
          <p className="text-muted-foreground text-sm mt-1.5 text-center lg:text-left">Welcome back. Sign in to your Indian Rounds workspace.</p>

          <form onSubmit={submit} className="mt-6 space-y-4 lg:mt-8" data-testid="login-form">
            <div>
              <Label htmlFor="email" className="text-[13px] font-medium">Email</Label>
              <Input
                id="email" type="email" required autoComplete="username"
                value={email} onChange={(e) => setEmail(e.target.value)}
                placeholder="you@indianrounds.com"
                className="mt-1.5 h-11"
                data-testid="login-email"
              />
            </div>
            <div>
              <div className="flex items-center justify-between">
                <Label htmlFor="password" className="text-[13px] font-medium">Password</Label>
                <Link href="/forgot-password" className="text-xs text-muted-foreground hover:text-foreground hover:underline" data-testid="login-forgot">
                  Forgot password?
                </Link>
              </div>
              <Input
                id="password" type="password" required autoComplete="current-password"
                value={password} onChange={(e) => setPassword(e.target.value)}
                className="mt-1.5 h-11"
                data-testid="login-password"
              />
            </div>
            {err && <div className="text-sm text-destructive font-medium" data-testid="login-error">{err}</div>}
            <Button
              type="submit" disabled={loading}
              className="w-full h-11 text-sm font-semibold"
              data-testid="login-submit"
            >
              {loading ? "Signing in…" : "Sign in"}
            </Button>
          </form>
        </div>
      </div>
    </div>
  );
}
