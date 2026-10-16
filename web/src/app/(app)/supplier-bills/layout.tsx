import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";

export default function SupplierBillsLayout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute permissions={["finance.bills", "finance.view"]} inline>
      {children}
    </ProtectedRoute>
  );
}
