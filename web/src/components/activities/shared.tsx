"use client";

import { Check, CheckSquare, Phone, Users, Mail, Repeat } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import type { Activity, ActivityPriority, ActivityType } from "@/types";

export const ACTIVITY_TYPES: { value: ActivityType; label: string }[] = [
  { value: "task", label: "Task" },
  { value: "call", label: "Call" },
  { value: "meeting", label: "Meeting" },
  { value: "email", label: "Email" },
  { value: "follow_up", label: "Follow-up" },
];

export const PRIORITIES: { value: ActivityPriority; label: string }[] = [
  { value: "low", label: "Low" },
  { value: "medium", label: "Medium" },
  { value: "high", label: "High" },
];

export const TYPE_META: Record<ActivityType, { label: string; icon: LucideIcon; chip: string }> = {
  task: { label: "Task", icon: CheckSquare, chip: "bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/60 dark:text-slate-200 dark:border-slate-700" },
  call: { label: "Call", icon: Phone, chip: "bg-sky-50 text-sky-800 border-sky-200 dark:bg-sky-950/50 dark:text-sky-300 dark:border-sky-900" },
  meeting: { label: "Meeting", icon: Users, chip: "bg-violet-50 text-violet-800 border-violet-200 dark:bg-violet-950/50 dark:text-violet-300 dark:border-violet-900" },
  email: { label: "Email", icon: Mail, chip: "bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-900" },
  follow_up: { label: "Follow-up", icon: Repeat, chip: "bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:border-amber-900" },
};

export const typeMeta = (t: string) => TYPE_META[t as ActivityType] ?? TYPE_META.task;

export const PRIORITY_DOT: Record<ActivityPriority, string> = {
  low: "bg-slate-400",
  medium: "bg-amber-500",
  high: "bg-red-500",
};

/** "16 Jun, 4:45 pm" (year added when not the current year), in the browser's locale. */
export function formatDue(iso: string | null): string {
  if (!iso) return "No date";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "No date";
  const sameYear = d.getFullYear() === new Date().getFullYear();
  const date = d.toLocaleDateString(undefined, { day: "numeric", month: "short", ...(sameYear ? {} : { year: "numeric" }) });
  const time = d.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
  return `${date}, ${time}`;
}

const pad = (n: number) => String(n).padStart(2, "0");

/** ISO instant -> value for <input type="datetime-local"> in local time. */
export function toLocalInput(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** datetime-local value (local time) -> ISO UTC string, or null when empty. */
export function fromLocalInput(v: string): string | null {
  if (!v) return null;
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

export function dayKey(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function DoneToggle({
  done, title, onToggle, disabled, testId, size = 22,
}: { done: boolean; title: string; onToggle: () => void; disabled?: boolean; testId?: string; size?: number }) {
  return (
    <button
      type="button"
      aria-pressed={done}
      aria-label={done ? `Reopen "${title}"` : `Mark "${title}" as done`}
      title={done ? "Reopen" : "Mark as done"}
      disabled={disabled}
      onClick={(e) => { e.stopPropagation(); onToggle(); }}
      data-testid={testId}
      style={{ width: size, height: size }}
      className={cn(
        "inline-flex shrink-0 items-center justify-center rounded-full border-2 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50",
        done
          ? "border-emerald-600 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-500 dark:text-neutral-900"
          : "border-muted-foreground/50 text-transparent hover:border-emerald-600 hover:text-emerald-600/60 dark:hover:border-emerald-400 dark:hover:text-emerald-400/60"
      )}
    >
      <Check size={Math.round(size * 0.6)} strokeWidth={3} />
    </button>
  );
}

export function dueClass(a: Pick<Activity, "overdue" | "done">): string {
  return a.overdue && !a.done ? "text-red-600 dark:text-red-400 font-medium" : "text-muted-foreground";
}

/** Whether the current user may edit / complete this activity (the API enforces too). */
export function canEditActivity(can: (...k: string[]) => boolean, myId: string, a: Activity): boolean {
  if (can("activities.manage")) return true;
  // an activity the system opened by itself (a reply to review) has no owner: anyone who can see it may work on it
  return can("activities.create") && (a.assigned_to?.id === myId || a.created_by?.id === myId || (!a.assigned_to && !a.created_by));
}
