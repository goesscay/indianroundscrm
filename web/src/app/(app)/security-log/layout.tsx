import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";

export default function RoleLayout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute roles={["admin"]} inline>
      {children}
    </ProtectedRoute>
  );
}
