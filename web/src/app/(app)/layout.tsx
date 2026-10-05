import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";
import { ChatRealtimeProvider } from "@/context/ChatRealtimeContext";
import { SupplierRepliesProvider } from "@/context/SupplierRepliesContext";
import { ChatUnreadProvider } from "@/context/ChatUnreadContext";
import { ChatDockProvider } from "@/context/ChatDockContext";
import ChatDock from "@/components/chat/ChatDock";
import { AiPanelProvider } from "@/components/ai/AiPanelContext";
import Layout from "@/components/app/Layout";

// Authenticated app shell (sidebar + content). Any signed-in role may enter;
// per-route role restrictions live in the nested route layouts.
export default function AppShellLayout({ children }: { children: ReactNode }) {
  return (
    <ProtectedRoute>
      <ChatRealtimeProvider>
        <SupplierRepliesProvider>
        <ChatUnreadProvider>
          <ChatDockProvider>
            <AiPanelProvider>
              <Layout>{children}</Layout>
            </AiPanelProvider>
            <ChatDock />
          </ChatDockProvider>
        </ChatUnreadProvider>
        </SupplierRepliesProvider>
      </ChatRealtimeProvider>
    </ProtectedRoute>
  );
}
