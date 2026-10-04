import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";
import Layout from "@/components/app/Layout";

// Authenticated app shell (sidebar + content). Any signed-in role may enter;
// per-route role restrictions live in the nested route layouts.
export default function AppShellLayout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute>
      <Layout>{children}</Layout>
    </ProtectedRoute>
  );
}
