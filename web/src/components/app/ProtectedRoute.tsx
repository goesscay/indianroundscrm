"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/context/AuthContext";

interface ProtectedRouteProps {
  children: ReactNode;
  /** When set, the user needs ANY of these permission keys; others are sent to the dashboard. */
  permissions?: string[];
  /** Render the loading state inline (inside an existing shell) instead of full-screen. */
  inline?: boolean;
}

export default function ProtectedRoute({ children, permissions, inline = false }: ProtectedRouteProps) {
  const { user, ready, can } = useAuth();
  const router = useRouter();

  const denied = ready && !!user && !!permissions && !can(...permissions);

  useEffect(() => {
    if (!ready) return;
    if (!user) {
      router.replace("/login");
    } else if (denied) {
      router.replace("/");
    }
  }, [ready, user, denied, router]);

  if (!ready || !user || denied) {
    return (
      <div className={inline ? "py-12 flex items-center justify-center" : "min-h-screen flex items-center justify-center bg-background"}>
        <div className="text-sm text-muted-foreground">Loading…</div>
      </div>
    );
  }
  return <>{children}</>;
}
