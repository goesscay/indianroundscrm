import { useState } from "react";
import { useNavigate, Navigate } from "react-router-dom";
import { useAuth } from "@/context/AuthContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { formatApiError } from "@/lib/api";
import { Mail } from "lucide-react";

export default function Login() {
  const { user, login } = useAuth();
  const nav = useNavigate();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [err, setErr] = useState("");
  const [loading, setLoading] = useState(false);

  if (user) return <Navigate to={user.password_reset_required ? "/change-password" : "/"} replace />;

  const submit = async (e) => {
    e.preventDefault();
    setErr(""); setLoading(true);
    try {
      const data = await login(email.trim().toLowerCase(), password);
      nav(data?.password_reset_required ? "/change-password" : "/");
    } catch (e) {
      setErr(formatApiError(e));
    } finally { setLoading(false); }
  };

  return (
    <div className="min-h-screen grid grid-cols-1 lg:grid-cols-2 bg-background">
      {/* Left brand panel */}
      <div className="relative hidden lg:flex flex-col justify-between p-12 overflow-hidden"
           style={{ background: "linear-gradient(135deg, #106EBE 0%, #0d5aa0 60%, #024a37 100%)" }}
           data-testid="login-brand-panel">
        <div className="relative z-10 flex items-center gap-3">
          <div className="w-16 h-16 rounded-md flex items-center justify-center overflow-hidden bg-white shadow-md">
            <img src="https://customer-assets.emergentagent.com/job_journey-crm-hub-1/artifacts/i3r285or_Indian%20rounds_logo.png"
                 alt="Indian Rounds" className="w-full h-full object-contain p-1.5" />
          </div>
          <div className="text-white">
            <div className="font-display font-black text-xl leading-none tracking-tight">INDIAN ROUNDS</div>
            <div className="label-caps mt-1 !text-white/70">Travel CRM</div>
          </div>
        </div>
        <div className="relative z-10 text-white">
          <h1 className="font-display text-4xl lg:text-5xl font-black tracking-tight leading-[1.05]">
            Every enquiry.<br />Every itinerary.<br />
            <span style={{ color: "#0FFCBE" }}>One CRM.</span>
          </h1>
          <p className="mt-6 max-w-md text-white/80 font-medium">
            Capture leads, dispatch supplier RFQs in one click, and track bookings from first
            enquiry to travel-completed — with role-based control for Sales, Ops & Admin.
          </p>
        </div>
        <div className="relative z-10 label-caps !text-white/60">© Indian Rounds · Enterprise Travel CRM</div>
        {/* Decorative shapes */}
        <div className="absolute -right-24 -bottom-24 w-96 h-96 rounded-full opacity-25"
             style={{ background: "#0FFCBE" }} />
        <div className="absolute right-20 top-40 w-40 h-40 rounded-full opacity-15 border border-white" />
      </div>

      {/* Right form */}
      <div className="flex items-center justify-center p-6 md:p-12">
        <div className="w-full max-w-md">
          <div className="lg:hidden flex items-center gap-2 mb-8">
            <div className="w-9 h-9 rounded-md flex items-center justify-center" style={{ background: "#106EBE" }}>
              <Mail className="text-white" size={18} />
            </div>
            <div className="font-display font-black text-lg tracking-tight">INDIAN ROUNDS</div>
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
              style={{ background: "#106EBE" }}
              data-testid="login-submit"
            >
              {loading ? "Signing in…" : "Sign in →"}
            </Button>
          </form>

          <div className="mt-5 flex items-center gap-3">
            <div className="flex-1 h-px bg-border" />
            <span className="label-caps">or</span>
            <div className="flex-1 h-px bg-border" />
          </div>

          <Button
            type="button" variant="outline"
            className="w-full h-11 mt-4 text-sm font-semibold border-2"
            onClick={() => {
              // REMINDER: DO NOT HARDCODE THE URL, OR ADD ANY FALLBACKS OR REDIRECT URLS, THIS BREAKS THE AUTH
              const redirectUrl = window.location.origin + "/";
              window.location.href = `https://auth.emergentagent.com/?redirect=${encodeURIComponent(redirectUrl)}`;
            }}
            data-testid="google-login"
          >
            <svg width="18" height="18" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
              <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.6-6 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/>
              <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.6 15.1 18.9 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.6 8.3 6.3 14.7z"/>
              <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 34.9 26.7 36 24 36c-5.3 0-9.6-3.4-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
              <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.3-4.1 5.6l6.2 5.2c-.4.4 6.6-4.8 6.6-14.8 0-1.3-.1-2.4-.4-3.5z"/>
            </svg>
            Continue with Google
          </Button>

          <p className="text-[11px] text-muted-foreground mt-3 text-center">
            Only pre-registered emails can sign in with Google. Contact admin for access.
          </p>

          <div className="mt-8 p-4 rounded-md border border-border bg-secondary/40">
            <div className="label-caps mb-2">Demo Accounts</div>
            <div className="text-xs text-muted-foreground space-y-1 font-mono">
              <div>admin@indianrounds.com / admin123</div>
              <div>sales@indianrounds.com / sales123</div>
              <div>ops@indianrounds.com / ops123</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
