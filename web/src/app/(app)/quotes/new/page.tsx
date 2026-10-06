"use client";

import { Suspense } from "react";
import QuoteEditor from "@/components/crm/QuoteEditor";

export default function NewQuotePage() {
  return <Suspense fallback={null}><QuoteEditor /></Suspense>;
}
