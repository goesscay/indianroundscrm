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

  if (user) return <Navigate to="/" replace />;

  const submit = async (e) => {
    e.preventDefault();
    setErr(""); setLoading(true);
    try {
      await login(email.trim().toLowerCase(), password);
      nav("/");
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
          <div className="w-11 h-11 rounded-md bg-white flex items-center justify-center">
            <Mail size={22} style={{ color: "#106EBE" }} />
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
