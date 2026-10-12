/**
 * The Leads page groups the precise lead statuses into a few stages. Every lead keeps its own status;
 * a stage is only a tab. Keep in step with App\Support\LeadStages on the API.
 */
export interface LeadStage {
  key: string;
  label: string;
  statuses: string[];
}

export const STAGES: LeadStage[] = [
  { key: "new", label: "New", statuses: ["New Lead"] },
  { key: "progress", label: "In Progress", statuses: ["Supplier Mail Sent", "Quotation Awaiting", "Quote Received", "Follow-up"] },
  { key: "quoted", label: "Quote Sent", statuses: ["Quote Sent to Customer"] },
  { key: "booked", label: "Booked", statuses: ["Booked", "Payment Pending", "Payment Received"] },
  { key: "completed", label: "Completed", statuses: ["Travel Completed", "Closed"] },
  { key: "lost", label: "Lost", statuses: ["Cancelled"] },
];

export const stageByKey = (key: string) => STAGES.find((s) => s.key === key);
