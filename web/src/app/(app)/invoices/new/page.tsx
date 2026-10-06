"use client";

import { Suspense } from "react";
import InvoiceEditor from "@/components/crm/InvoiceEditor";

export default function NewInvoicePage() {
  return <Suspense fallback={null}><InvoiceEditor /></Suspense>;
}
