"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { api, formatApiError } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { useCurrentUser } from "@/context/AuthContext";
import type { SessionInfo, User } from "@/types";
import { Shield, Key, LogOut, User as UserIcon, Mail, Phone, Briefcase, Monitor, X } from "lucide-react";

const STATUS_STYLES: Record<string, string> = {
  active: "bg-emerald-100 text-emerald-800",
  inactive: "bg-gray-100 text-gray-800",
  suspended: "bg-red-100 text-red-800",
  locked: "bg-amber-100 text-amber-800",
  password_reset_required: "bg-amber-100 text-amber-800",
};

const fmt = (iso?: string | null) => {
  if (!iso) return "—";
  try { return new Date(iso).toLocaleString("en-IN", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }); }
  catch { return iso.slice(0, 16); }
};

export default function Profile() {
  const { user, logout } = useCurrentUser();
  const router = useRouter();
  const [me, setMe] = useState<User | null>(null);
  const [sessions, setSessions] = useState<SessionInfo[]>([]);

  const loadSessions = async () => {
    try {
      const { data } = await api.get<SessionInfo[]>("/sessions/mine");
      setSessions(data);
    } catch { /* ignore */ }
  };

  useEffect(() => {
    (async () => {
      try {
        const { data } = await api.get<User>("/auth/me");
        setMe(data);
      } catch { /* ignore */ }
      loadSessions();
    })();
  }, []);

  const revoke = async (jti: string, isCurrent?: boolean) => {
    if (isCurrent) {
      if (!window.confirm("This will log you out of THIS device. Continue?")) return;
    } else {
      if (!window.confirm("Sign out this device?")) return;
    }
    try {
      await api.post(`/sessions/${jti}/revoke`);
      toast.success(isCurrent ? "Signed out" : "Device signed out");
      if (isCurrent) { await logout(); router.push("/login"); }
      else loadSessions();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const revokeOthers = async () => {
    if (!window.confirm("Sign out from ALL other devices? (Keeps this one active.)")) return;
    const others = sessions.filter(s => !s.is_current);
    try {
      await Promise.all(others.map(s => api.post(`/sessions/${s.jti}/revoke`)));
      toast.success(`Signed out from ${others.length} device${others.length === 1 ? "" : "s"}`);
      loadSessions();
    } catch (e) { toast.error(formatApiError(e)); }
  };

  const data = me || user;
  if (!data) return null;
  const status = data.account_status || "active";

  return (
    <div className="space-y-6 max-w-3xl" data-testid="profile-page">
      <div>
        <div className="label-caps">Account</div>
        <h1 className="font-display text-3xl sm:text-4xl font-black tracking-tight mt-1">My Account</h1>
      </div>

      <Card className="p-6 rounded-lg">
        <div className="flex items-start gap-4">
          <div className="w-16 h-16 rounded-full flex items-center justify-center text-white font-display font-black text-2xl" style={{ background: "#106EBE" }}>
            {(data.name || data.email || "?").charAt(0).toUpperCase()}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-3 flex-wrap">
              <div className="font-display font-black text-2xl">{data.name || "—"}</div>
              <span className={`text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded ${STATUS_STYLES[status] || "bg-gray-100"}`} data-testid="profile-status">
                {status.replaceAll("_", " ")}
              </span>
            </div>
            <div className="text-sm text-muted-foreground mt-1 capitalize">{data.role}</div>
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-6 text-sm">
          <div className="flex items-center gap-2">
            <Mail size={14} className="text-muted-foreground" />
            <span className="font-medium" data-testid="profile-email">{data.email}</span>
          </div>
          <div className="flex items-center gap-2">
            <Phone size={14} className="text-muted-foreground" />
            <span className="font-medium">{data.mobile || "—"}</span>
          </div>
          <div className="flex items-center gap-2">
            <Briefcase size={14} className="text-muted-foreground" />
            <span className="font-medium">{data.staff_code || "—"} {data.consultant_code ? `· ${data.consultant_code}` : ""}</span>
          </div>
          <div className="flex items-center gap-2">
            <UserIcon size={14} className="text-muted-foreground" />
            <span className="font-medium text-xs" data-testid="profile-last-login">Last login: {fmt(data.last_login_at)}</span>
          </div>
        </div>
      </Card>

      <Card className="p-6 rounded-lg">
        <div className="flex items-center gap-2 mb-4">
          <Shield size={18} />
          <h3 className="font-display text-xl font-bold">Security</h3>
        </div>
        <div className="space-y-2">
          <Link href="/change-password" className="block">
            <div className="flex items-center justify-between p-4 rounded-md border border-border hover:border-[#106EBE] hover:bg-secondary/40 transition-colors" data-testid="profile-change-password">
              <div>
                <div className="font-semibold text-sm">Change Password</div>
                <div className="text-xs text-muted-foreground">Update your password. Requires current password.</div>
              </div>
              <Key size={16} />
            </div>
          </Link>
          <div className="p-4 rounded-md border border-border bg-secondary/20">
            <div className="font-semibold text-sm">Two-Factor Authentication</div>
            <div className="text-xs text-muted-foreground mt-0.5">Coming in Phase 2B. Adds an authenticator-app / OTP layer.</div>
          </div>
        </div>
      </Card>

      <Card className="p-6 rounded-lg" data-testid="sessions-card">
        <div className="flex items-end justify-between mb-4 gap-3 flex-wrap">
          <div className="flex items-center gap-2">
            <Monitor size={18} />
            <h3 className="font-display text-xl font-bold">Active Sessions</h3>
          </div>
          {sessions.filter(s => !s.is_current).length > 0 && (
            <Button size="sm" variant="outline" onClick={revokeOthers} data-testid="revoke-others">
              Sign out other devices
            </Button>
          )}
        </div>
        {sessions.length === 0 && <div className="text-sm text-muted-foreground">No active sessions.</div>}
        <div className="space-y-2">
          {sessions.map(s => (
            <div key={s.jti}
                 className={`flex items-center justify-between p-3 rounded-md border ${s.is_current ? "border-[#106EBE] bg-[#106EBE]/5" : "border-border"}`}
                 data-testid={`session-${s.jti}`}>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <div className="font-semibold text-sm">{s.device || "Unknown device"}</div>
                  {s.is_current && (
                    <span className="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800">
                      Current
                    </span>
                  )}
                </div>
                <div className="text-xs text-muted-foreground mt-0.5 truncate">{s.ip || "—"} · Last active {fmt(s.last_active_at)}</div>
              </div>
              <Button size="sm" variant="ghost" onClick={() => revoke(s.jti, s.is_current)}
                      title={s.is_current ? "Sign out this device" : "Sign out this device"}
                      data-testid={`revoke-${s.jti}`}>
                <X size={14} className="text-destructive" />
              </Button>
            </div>
          ))}
        </div>
      </Card>

      <Card className="p-6 rounded-lg">
        <div className="flex items-center gap-2 mb-4">
          <LogOut size={18} />
          <h3 className="font-display text-xl font-bold">Account</h3>
        </div>
        <Button onClick={async () => { await logout(); router.push("/login"); }} variant="outline"
                className="text-destructive border-destructive hover:bg-destructive/10" data-testid="profile-logout">
          <LogOut size={14} /> Logout
        </Button>
      </Card>
    </div>
  );
}

