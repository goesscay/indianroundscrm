"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { ArrowLeft, Mail, CheckCircle2 } from "lucide-react";

export default function ForgotPassword() {
  const [email, setEmail] = useState("");
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!email.trim()) return;
    setBusy(true);
    try {
      await api.post("/auth/forgot-password", { email: email.trim().toLowerCase() });
      setSent(true);
    } catch (e) { toast.error(formatApiError(e)); }
    finally { setBusy(false); }
  };

  return (
    <div className="min-h-[100dvh] flex items-center justify-center bg-secondary/30 p-4 pt-[max(1rem,env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))]" data-testid="forgot-password-page">
      <div className="w-full max-w-md">
        <Link href="/login" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground mb-6" data-testid="fp-back">
          <ArrowLeft size={14} /> Back to sign in
        </Link>
        <Card className="p-6 rounded-lg">
          {!sent ? (
            <>
              <div className="label-caps">Security</div>
              <h1 className="font-display text-3xl tracking-tight mt-1">Reset your password</h1>
              <p className="text-sm text-muted-foreground mt-2">
                Enter the email associated with your Indian Rounds CRM account. We&apos;ll email you a secure link to set a new password.
              </p>
              <form onSubmit={submit} className="mt-6 space-y-4">
                <div>
                  <Label className="label-caps">Email</Label>
                  <Input type="email" required value={email} onChange={e => setEmail(e.target.value)}
                         placeholder="you@indianrounds.com" className="mt-1.5 h-10" data-testid="fp-email" autoFocus />
                </div>
                <Button type="submit" disabled={busy} className="w-full h-10 text-sm font-semibold"
                        data-testid="fp-submit">
                  {busy ? "Sending…" : "Send reset link →"}
                </Button>
              </form>
            </>
          ) : (
            <div className="text-center py-4" data-testid="fp-success">
              <div className="w-12 h-12 rounded bg-emerald-100 mx-auto dark:bg-emerald-950/60 flex items-center justify-center">
                <CheckCircle2 className="text-emerald-600 dark:text-emerald-400" size={28} />
              </div>
              <h2 className="font-display text-2xl mt-4">Check your email</h2>
              <p className="text-sm text-muted-foreground mt-2">
                If an account exists for <b>{email}</b>, we&apos;ve sent a reset link.
                The link expires in 1 hour and can only be used once.
              </p>
              <div className="mt-6 p-3 rounded bg-secondary/60 text-xs text-muted-foreground text-left">
                <Mail size={12} className="inline -mt-0.5 mr-1" />
                Didn&apos;t get an email? Check spam or contact your admin.
              </div>
              <Button asChild variant="outline" className="mt-6 w-full">
                <Link href="/login">Return to sign in</Link>
              </Button>
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
