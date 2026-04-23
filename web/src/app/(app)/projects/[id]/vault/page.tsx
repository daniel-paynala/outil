import { apiJson } from "@/lib/api/server";
import type { VaultEntry } from "@/lib/types";
import VaultClient from "./vault-client";

export default async function VaultPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const entries = await apiJson<VaultEntry[]>(`/api/projects/${id}/vault`);
  return <VaultClient projectId={id} initialEntries={entries} />;
}
