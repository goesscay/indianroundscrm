"use client";

import { Suspense } from "react";
import ProposalEditor from "@/components/crm/ProposalEditor";

export default function ProposalPage() {
  return <Suspense fallback={null}><ProposalEditor /></Suspense>;
}
