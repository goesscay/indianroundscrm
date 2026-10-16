/**
 * Six lead statuses, and the tabs on the Leads page that group them. Most of them move by themselves
 * (supplier mail sent -> In Progress, quote sent -> Quote Sent, deal won -> Booked, trip done -> Completed); the team sets Lost by hand, with a reason.
 * Follow-ups are Activities, not a status. Keep in step with App\Support\LeadStages on the API.
 */
export interface LeadStage {
  key: string;
  label: string;
  statuses: string[];
}

export const STAGES: LeadStage[] = [
  { key: "new", label: "New", statuses: ["New Lead"] },
  { key: "progress", label: "In Progress", statuses: ["In Progress"] },
  { key: "quoted", label: "Quote Sent", statuses: ["Quote Sent to Customer"] },
  { key: "booked", label: "Booked", statuses: ["Booked"] },
  { key: "completed", label: "Completed", statuses: ["Completed"] },
  { key: "lost", label: "Lost", statuses: ["Lost"] },
];

export const stageByKey = (key: string) => STAGES.find((s) => s.key === key);

/** What a salesperson may set by hand. Booked and Completed come from the deal and the trip, or from Operations. */
export const SALES_MANUAL = ["In Progress", "Quote Sent to Customer", "Lost"];

export const LOST_REASONS = ["Price too high", "Chose another agent", "Not responding", "Plans changed or postponed", "Not interested", "Wrong number or duplicate", "Other"];

/** The statuses to offer in a lead's status menu: the ones this person may set, plus the lead's current one (shown as selected). */
export function statusChoices(canSetAny: boolean, current: string): string[] {
  const all = STAGES.flatMap((s) => s.statuses);
  const allowed = canSetAny ? all : SALES_MANUAL;
  return all.filter((s) => allowed.includes(s) || s === current);
}
