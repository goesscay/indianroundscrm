"use client";

import { useEffect, useState } from "react";
import { Clock } from "lucide-react";
import { useTheme } from "@/context/ThemeContext";

function fmt(secs: number) {
  const abs = Math.abs(secs);
  const d = Math.floor(abs / 86400);
  const h = Math.floor((abs % 86400) / 3600);
  const m = Math.floor((abs % 3600) / 60);
  const s = Math.floor(abs % 60);
  if (d > 0) return `${d}d ${h}h ${m}m`;
  if (h > 0) return `${h}h ${m}m ${s}s`;
  return `${m}m ${s}s`;
}

export default function FollowupCountdown({ iso }: { iso: string }) {
  const dk = useTheme().resolved === "dark";
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, []);
  const target = new Date(iso).getTime();
  const secs = Math.floor((target - now) / 1000);
  const overdue = secs < 0;
  const soon = !overdue && secs < 3600;
  const style = dk
    ? overdue
      ? { background: "#2a1010", color: "#f87171", border: "#5a1f1f" }
      : soon
      ? { background: "#2a2008", color: "#f2c96b", border: "#4a3a12" }
      : { background: "#0f1b2e", color: "#93c5fd", border: "#1e3a5f" }
    : overdue
    ? { background: "#FEE2E2", color: "#B91C1C", border: "#FCA5A5" }
    : soon
    ? { background: "#FEF3C7", color: "#92400E", border: "#FDE68A" }
    : { background: "#DBEAFE", color: "#1E40AF", border: "#BFDBFE" };
  return (
    <div className="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border text-xs font-semibold"
         style={{ background: style.background, color: style.color, borderColor: style.border }}
         data-testid="followup-countdown">
      <Clock size={12} />
      {overdue ? `Overdue by ${fmt(secs)}` : `Due in ${fmt(secs)}`}
    </div>
  );
}
