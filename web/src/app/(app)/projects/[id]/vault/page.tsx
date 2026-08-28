import { apiJson } from "@/lib/api/server";
import { createClient } from "@/lib/supabase/server";
import type { ProjectDetail, VaultEntry } from "@/lib/types";
import VaultClient from "./vault-client";

export default async function VaultPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  const [entries, project] = await Promise.all([
    apiJson<VaultEntry[]>(`/api/projects/${id}/vault`),
    apiJson<ProjectDetail>(`/api/projects/${id}`),
  ]);

  return (
    <VaultClient
      projectId={id}
      currentUserId={user?.id ?? ""}
      projectOwnerId={project.created_by}
      initialEntries={entries}
    />
  );
}
