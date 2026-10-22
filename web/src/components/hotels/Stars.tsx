import { Star } from "lucide-react";
import { cn } from "@/lib/utils";

export function Stars({ n, className }: { n: number | null | undefined; className?: string }) {
  if (!n) return null;
  return (
    <span className={cn("inline-flex items-center gap-px text-amber-500", className)} aria-label={`${n} star`}>
      {Array.from({ length: n }).map((_, i) => <Star key={i} size={11} fill="currentColor" />)}
    </span>
  );
}
