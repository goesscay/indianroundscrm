"use client";

import Link from "next/link";
import { useRouter, useParams } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { ArrowLeft, CheckCircle2, XCircle, ShieldAlert, ShieldCheck } from "lucide-react";

const rules = [
  { key: "len", label: "At least 8 characters", test: (p: string) => (p || "").length >= 8 },
  { key: "upper", label: "1 uppercase letter", test: (p: string) => /[A-Z]/.test(p) },
  { key: "lower", label: "1 lowercase letter", test: (p: string) => /[a-z]/.test(p) },
  { key: "digit", label: "1 number", test: (p: string) => /\d/.test(p) },
  { key: "special", label: "1 special character", test: (p: string) => /[^A-Za-z0-9]/.test(p) },
];

export default function ResetPassword() {
  const { token } = useParams<{ token: string }>();
  const router = useRouter();
  const [status, setStatus] = useState<"verifying" | "valid" | "invalid" | "success">("verifying"); // verifying | valid | invalid | success
  const [emailForToken, setEmailForToken] = useState("");
  const [pw, setPw] = useState("");
  const [cf, setCf] = useState("");
  const [errMsg, setErrMsg] = useState("");
  const [busy, setBusy] = useState(false);
  const allPass = rules.every(r => r.test(pw));
  const match = pw && pw === cf;

  useEffect(() => {
    (async () => {
      try {
        const { data } = await api.get<{ email?: string }>(`/auth/verify-reset-token/${token}`);
        setEmailForToken(data.email || "");
        setStatus("valid");
      } catch (e) {
        setStatus("invalid");
        setErrMsg(formatApiError(e));
      }
    })();
  }, [token]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!allPass) { toast.error("Password does not meet the policy."); return; }
    if (!match) { toast.error("Passwords do not match."); return; }
    setBusy(true);
    try {
      await api.post("/auth/reset-password", { token, new_password: pw, confirm_password: cf });
      setStatus("success");
      toast.success("Password reset. Please log in.");
      setTimeout(() => router.push("/login"), 2000);
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  return (
    <div className="min-h-[100dvh] flex items-center justify-center bg-secondary/30 p-4 pt-[max(1rem,env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))]" data-testid="reset-password-page">
      <div className="w-full max-w-md">
        <Link href="/login" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground mb-6">
          <ArrowLeft size={14} /> Back to sign in
        </Link>
        <Card className="p-6 rounded-lg">
          {status === "verifying" && (
            <div className="text-center py-8" data-testid="rp-verifying">
              <div className="animate-pulse text-sm text-muted-foreground">Verifying reset link…</div>
            </div>
          )}
          {status === "invalid" && (
            <div className="text-center py-4" data-testid="rp-invalid">
              <div className="w-12 h-12 rounded bg-red-100 mx-auto dark:bg-red-950/60 flex items-center justify-center">
                <ShieldAlert className="text-red-600 dark:text-red-400" size={28} />
              </div>
              <h2 className="font-display text-2xl mt-4">Link not valid</h2>
              <p className="text-sm text-muted-foreground mt-2">{errMsg || "This reset link is invalid, used, or expired."}</p>
              <Button asChild className="mt-6 w-full">
                <Link href="/forgot-password">Request a new link</Link>
              </Button>
            </div>
          )}
          {status === "success" && (
            <div className="text-center py-4" data-testid="rp-success">
              <div className="w-12 h-12 rounded bg-emerald-100 mx-auto dark:bg-emerald-950/60 flex items-center justify-center">
                <ShieldCheck className="text-emerald-600 dark:text-emerald-400" size={28} />
              </div>
              <h2 className="font-display text-2xl mt-4">Password reset</h2>
              <p className="text-sm text-muted-foreground mt-2">Redirecting to sign in…</p>
            </div>
          )}
          {status === "valid" && (
            <form onSubmit={submit} className="space-y-4" data-testid="rp-form">
              <div>
                <div className="label-caps">Reset password</div>
                <h1 className="font-display text-3xl tracking-tight mt-1">Choose a new password</h1>
                {emailForToken && <p className="text-sm text-muted-foreground mt-1">for <b>{emailForToken}</b></p>}
              </div>
              <div>
                <Label className="label-caps">New Password</Label>
                <Input type="password" required autoFocus value={pw} onChange={e => setPw(e.target.value)}
                       className="mt-1.5" data-testid="rp-new" autoComplete="new-password" />
              </div>
              <div>
                <Label className="label-caps">Confirm New Password</Label>
                <Input type="password" required value={cf} onChange={e => setCf(e.target.value)}
                       className="mt-1.5" data-testid="rp-confirm" autoComplete="new-password" />
                {cf && !match && <div className="text-xs text-destructive mt-1">Passwords do not match</div>}
              </div>
              <div className="rounded border border-border bg-secondary/40 p-3">
                <div className="label-caps mb-2">Password requirements</div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-1 text-xs">
                  {rules.map(r => {
                    const pass = r.test(pw);
                    return (
                      <div key={r.key} className={`flex items-center gap-1.5 ${pass ? "text-emerald-700 dark:text-emerald-300" : "text-muted-foreground"}`}
                           data-testid={`rp-rule-${r.key}-${pass ? "pass" : "fail"}`}>
                        {pass ? <CheckCircle2 size={12} /> : <XCircle size={12} />}
                        {r.label}
                      </div>
                    );
                  })}
                </div>
              </div>
              <Button type="submit" disabled={busy || !allPass || !match}
                      className="w-full h-10 text-sm font-semibold"
                      data-testid="rp-submit">
                {busy ? "Saving…" : "Set new password →"}
              </Button>
            </form>
          )}
        </Card>
      </div>
    </div>
  );
}
