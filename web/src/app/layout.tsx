import type { Metadata, Viewport } from "next";
import "./globals.css";
import { AuthProvider } from "@/context/AuthContext";
import { Toaster } from "@/components/ui/sonner";
import ServiceWorkerRegister from "@/components/app/ServiceWorkerRegister";

export const metadata: Metadata = {
  title: "Indian Rounds CRM",
  description: "Indian Rounds — Travel Lead Management CRM",
  manifest: "/manifest.json",
  icons: { icon: "/logo.png", apple: "/logo.png" },
};

export const viewport: Viewport = {
  themeColor: "#106EBE",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body>
        <AuthProvider>
          {children}
          <Toaster position="top-right" richColors />
        </AuthProvider>
        <ServiceWorkerRegister />
      </body>
    </html>
  );
}
