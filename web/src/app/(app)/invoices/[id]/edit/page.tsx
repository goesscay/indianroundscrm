"use client";

import { Suspense } from "react";
import { useParams } from "next/navigation";
import InvoiceEditor from "@/components/crm/InvoiceEditor";

export default function EditInvoicePage() {
  const { id } = useParams<{ id: string }>();
  return <Suspense fallback={null}><InvoiceEditor invoiceId={id} /></Suspense>;
}
