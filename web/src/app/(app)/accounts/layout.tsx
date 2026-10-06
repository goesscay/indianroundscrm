"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { ReactNode } from "react";
import ProtectedRoute from "@/components/app/ProtectedRoute";
import { cn } from "@/lib/utils";

const TABS = [
  { href: "/accounts", label: "Dashboard", exact: true },
  { href: "/accounts/bills", label: "Supplier bills" },
  { href: "/accounts/money", label: "Money in and out" },
  { href: "/accounts/banking", label: "Banking" },
  { href: "/accounts/journal", label: "Journal" },
  { href: "/accounts/ledger", label: "Ledger" },
  { href: "/accounts/statements", label: "Statements" },
  { href: "/accounts/reports", label: "Reports" },
  { href: "/accounts/trial-balance", label: "Trial balance" },
  { href: "/accounts/chart", label: "Chart of accounts" },
];

export default function AccountsLayout({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  return (
    <ProtectedRoute permissions={["finance.view"]} inline>
      <div className="space-y-6" data-testid="accounts-shell">
        <nav aria-label="Accounts sections" className="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1" data-testid="accounts-tabs">
          {TABS.map((t) => {
            const active = t.exact ? pathname === t.href : pathname === t.href || pathname.startsWith(`${t.href}/`);
            return (
              <Link key={t.href} href={t.href} aria-current={active ? "page" : undefined} data-testid={`tab-${t.href.split("/").pop() || "dashboard"}`}
                    className={cn("whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors",
                      active ? "border-foreground text-foreground" : "border-transparent text-muted-foreground hover:text-foreground")}>
                {t.label}
              </Link>
            );
          })}
        </nav>
        {children}
      </div>
    </ProtectedRoute>
  );
}
