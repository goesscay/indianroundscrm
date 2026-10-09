import type { Metadata } from "next";
import type { ReactNode } from "react";

// The supplier portal is private: keep it out of search results.
export const metadata: Metadata = {
  title: "Supplier portal",
  robots: { index: false, follow: false },
};

export default function PortalLayout({ children }: { children: ReactNode }) {
  return children;
}
