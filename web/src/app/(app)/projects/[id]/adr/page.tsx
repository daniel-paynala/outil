import Link from "next/link";
import { apiJson } from "@/lib/api/server";
import type { Decision } from "@/lib/types";
import AdrListClient from "./adr-list-client";

export default async function AdrPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const decisions = await apiJson<Decision[]>(
    `/api/projects/${id}/decisions`,
  );
  return <AdrListClient projectId={id} initialDecisions={decisions} />;
}
