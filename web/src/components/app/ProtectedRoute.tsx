"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/context/AuthContext";
import type { Role } from "@/types";

interface ProtectedRouteProps {
  children: ReactNode;
  /** When set, only these roles may view the route; others are sent to the dashboard. */
  roles?: Role[];
  /** Render the loading state inline (inside an existing shell) instead of full-screen. */
  inline?: boolean;
}

export default function ProtectedRoute({ children, roles, inline = false }: ProtectedRouteProps) {
  const { user, ready } = useAuth();
  const router = useRouter();

  const denied = ready && !!user && !!roles && !roles.includes(user.role);

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
