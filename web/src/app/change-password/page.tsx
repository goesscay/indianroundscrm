"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { useAuth } from "@/context/AuthContext";
import ProtectedRoute from "@/components/app/ProtectedRoute";
import type { User } from "@/types";
import { Shield, CheckCircle2, XCircle } from "lucide-react";

const rules = [
  { key: "len", label: "At least 8 characters", test: (p: string) => (p || "").length >= 8 },
  { key: "upper", label: "1 uppercase letter", test: (p: string) => /[A-Z]/.test(p) },
  { key: "lower", label: "1 lowercase letter", test: (p: string) => /[a-z]/.test(p) },
  { key: "digit", label: "1 number", test: (p: string) => /\d/.test(p) },
  { key: "special", label: "1 special character", test: (p: string) => /[^A-Za-z0-9]/.test(p) },
];

function ChangePassword() {
  const { user, setUser } = useAuth();
  const router = useRouter();
  const [cur, setCur] = useState("");
  const [pw, setPw] = useState("");
  const [cf, setCf] = useState("");
  const [busy, setBusy] = useState(false);
  const isForced = user?.password_reset_required || user?.account_status === "password_reset_required";
  const allPass = rules.every(r => r.test(pw));
  const match = pw && pw === cf;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!allPass) { toast.error("Password does not meet the required policy."); return; }
    if (!match) { toast.error("Passwords do not match."); return; }
    setBusy(true);
    try {
      await api.post("/auth/change-password", {
        current_password: isForced ? "" : cur,
        new_password: pw,
        confirm_password: cf,
      });
      toast.success("Password changed successfully.");
      // Refresh user data
      try {
        const { data } = await api.get<User>("/auth/me");
        setUser(data);
      } catch { /* ignore */ }
      router.push(isForced ? "/" : "/profile");
    } catch (err) {
      toast.error(formatApiError(err));
    }
    finally { setBusy(false); }
  };

  return (
    <div className={isForced ? "min-h-screen flex items-center justify-center bg-secondary/30 p-4" : ""} data-testid="change-password-page">
      <div className={isForced ? "w-full max-w-md" : "max-w-2xl mx-auto space-y-6"}>
        {isForced && (
          <div className="text-center mb-6">
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded bg-amber-100 text-amber-800 dark:text-amber-300 dark:bg-amber-950/60 dark:text-amber-300 text-xs font-semibold">
              <Shield size={14} /> Password Reset Required
            </div>
            <p className="text-sm text-muted-foreground mt-3">
              Your password has been reset by an administrator. Please create a new password to continue.
            </p>
          </div>
        )}

        {!isForced && (
          <div>
            <div className="label-caps">Security</div>
            <h1 className="font-display text-3xl sm:text-4xl tracking-tight mt-1">Change Password</h1>
          </div>
        )}

        <Card className="p-6 rounded-lg">
          <form onSubmit={submit} className="space-y-4">
            {!isForced && (
              <div>
                <Label className="label-caps">Current Password</Label>
                <Input type="password" className="mt-1.5" value={cur} onChange={e => setCur(e.target.value)}
                       required data-testid="cp-current" autoFocus autoComplete="current-password" />
              </div>
            )}
            <div>
              <Label className="label-caps">New Password</Label>
              <Input type="password" className="mt-1.5" value={pw} onChange={e => setPw(e.target.value)}
                     required data-testid="cp-new" autoComplete="new-password"
                     autoFocus={isForced} />
            </div>
            <div>
              <Label className="label-caps">Confirm New Password</Label>
              <Input type="password" className="mt-1.5" value={cf} onChange={e => setCf(e.target.value)}
                     required data-testid="cp-confirm" autoComplete="new-password" />
              {cf && !match && (
                <div className="text-xs text-destructive mt-1">Passwords do not match</div>
              )}
            </div>

            <div className="rounded border border-border bg-secondary/40 p-3">
              <div className="label-caps mb-2">Password requirements</div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-1 text-xs">
                {rules.map(r => {
                  const pass = r.test(pw);
                  return (
                    <div key={r.key} className={`flex items-center gap-1.5 ${pass ? "text-emerald-700 dark:text-emerald-300" : "text-muted-foreground"}`}
                         data-testid={`rule-${r.key}-${pass ? "pass" : "fail"}`}>
                      {pass ? <CheckCircle2 size={12} /> : <XCircle size={12} />}
                      {r.label}
                    </div>
                  );
                })}
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-2">
              {!isForced && (
                <Button type="button" variant="outline" onClick={() => router.push("/profile")} data-testid="cp-cancel">
                  Cancel
                </Button>
              )}
              <Button type="submit" disabled={busy || !allPass || !match}
                      data-testid="cp-submit">
                {busy ? "Saving…" : "Change Password"}
              </Button>
            </div>
          </form>
        </Card>
      </div>
    </div>
  );
}

export default function ChangePasswordPage() {
  return (
    <ProtectedRoute>
      <ChangePassword />
    </ProtectedRoute>
  );
}
