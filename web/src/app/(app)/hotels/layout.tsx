import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";

export default function Layout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute permissions={["hotels.view", "hotels.manage"]} inline>
      {children}
    </ProtectedRoute>
  );
}
