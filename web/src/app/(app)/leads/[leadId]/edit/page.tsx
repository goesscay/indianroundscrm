"use client";

import { useParams } from "next/navigation";
import LeadForm from "@/components/app/LeadForm";

export default function EditLeadPage() {
  const { leadId } = useParams<{ leadId: string }>();
  return <LeadForm leadId={leadId} />;
}
