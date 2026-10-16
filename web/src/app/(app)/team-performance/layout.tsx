import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";

export default function SuperAdminLayout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute superAdminOnly inline>
      {children}
    </ProtectedRoute>
  );
}
