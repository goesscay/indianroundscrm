import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";

export default function PermissionLayout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute permissions={["leads.edit", "leads.edit_limited"]} inline>
      {children}
    </ProtectedRoute>
  );
}
