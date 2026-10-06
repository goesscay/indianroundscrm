import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";

export default function PermissionLayout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute permissions={["invoices.view"]} inline>
      {children}
    </ProtectedRoute>
  );
}
