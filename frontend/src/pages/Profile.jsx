import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { api } from "@/lib/api";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/context/AuthContext";
import { Shield, Key, LogOut, User as UserIcon, Mail, Phone, Briefcase } from "lucide-react";

const STATUS_STYLES = {
  active: "bg-emerald-100 text-emerald-800",
  inactive: "bg-gray-100 text-gray-800",
  suspended: "bg-red-100 text-red-800",
  locked: "bg-amber-100 text-amber-800",
  password_reset_required: "bg-amber-100 text-amber-800",
};

export default function Profile() {
  const { user, logout } = useAuth();
  const nav = useNavigate();
  const [me, setMe] = useState(null);

  useEffect(() => {
    (async () => {
      try {
        const { data } = await api.get("/auth/me");
        setMe(data);
      } catch (_e) {}
    })();
  }, []);

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
            <span className="font-medium text-xs" data-testid="profile-last-login">Last login: {(data.last_login_at || "").slice(0, 16).replace("T", " ") || "—"}</span>
          </div>
        </div>
      </Card>

      <Card className="p-6 rounded-lg">
        <div className="flex items-center gap-2 mb-4">
          <Shield size={18} />
          <h3 className="font-display text-xl font-bold">Security</h3>
        </div>
        <div className="space-y-2">
          <Link to="/change-password" className="block">
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
            <div className="text-xs text-muted-foreground mt-0.5">Coming in Phase 2. Adds an authenticator-app / OTP layer for admin + finance users.</div>
          </div>
          <div className="p-4 rounded-md border border-border bg-secondary/20">
            <div className="font-semibold text-sm">Active Sessions</div>
            <div className="text-xs text-muted-foreground mt-0.5">Coming in Phase 2. See where you're logged in and revoke individual devices.</div>
          </div>
        </div>
      </Card>

      <Card className="p-6 rounded-lg">
        <div className="flex items-center gap-2 mb-4">
          <LogOut size={18} />
          <h3 className="font-display text-xl font-bold">Account</h3>
        </div>
        <Button onClick={async () => { await logout(); nav("/login"); }} variant="outline" className="text-destructive border-destructive hover:bg-destructive/10" data-testid="profile-logout">
          <LogOut size={14} /> Logout
        </Button>
      </Card>
    </div>
  );
}
