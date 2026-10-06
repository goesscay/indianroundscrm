import type { Metadata, Viewport } from "next";
import { Inter, Inter_Tight, Instrument_Serif } from "next/font/google";
import "./globals.css";
import { ThemeProvider, THEME_INIT_SCRIPT } from "@/context/ThemeContext";
import { AuthProvider } from "@/context/AuthContext";
import { Toaster } from "@/components/ui/sonner";
import ServiceWorkerRegister from "@/components/app/ServiceWorkerRegister";
import InstallPrompt from "@/components/app/InstallPrompt";

const inter = Inter({ subsets: ["latin"], variable: "--font-inter", display: "swap" });
const interTight = Inter_Tight({ subsets: ["latin"], variable: "--font-inter-tight", display: "swap" });
const serif = Instrument_Serif({ subsets: ["latin"], weight: "400", style: "italic", variable: "--font-serif-italic", display: "swap" });

export const metadata: Metadata = {
  title: "Indian Rounds CRM",
  description: "Indian Rounds — Travel Lead Management CRM",
  manifest: "/manifest.json",
  applicationName: "Indian Rounds CRM",
  appleWebApp: { capable: true, title: "IR CRM", statusBarStyle: "black-translucent" },
};

export const viewport: Viewport = {
  themeColor: "#0A0A0A",
  viewportFit: "cover",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en" suppressHydrationWarning className={`${inter.variable} ${interTight.variable} ${serif.variable}`}>
      <head>
        <script dangerouslySetInnerHTML={{ __html: THEME_INIT_SCRIPT }} />
      </head>
      <body>
        <ThemeProvider>
        <AuthProvider>
          {children}
          <Toaster position="top-right" richColors />
        </AuthProvider>
        </ThemeProvider>
        <ServiceWorkerRegister />
        <InstallPrompt />
      </body>
    </html>
  );
}
