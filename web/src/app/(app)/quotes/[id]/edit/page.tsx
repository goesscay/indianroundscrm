"use client";

import { Suspense } from "react";
import { useParams } from "next/navigation";
import QuoteEditor from "@/components/crm/QuoteEditor";

export default function EditQuotePage() {
  const { id } = useParams<{ id: string }>();
  return <Suspense fallback={null}><QuoteEditor quoteId={id} /></Suspense>;
}
