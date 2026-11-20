import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";

export default function Layout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute permissions={["transport.view", "transport.manage"]} inline>
      {children}
    </ProtectedRoute>
  );
}
