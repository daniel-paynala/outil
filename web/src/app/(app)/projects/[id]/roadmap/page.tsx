import { apiJson } from "@/lib/api/server";
import { createClient } from "@/lib/supabase/server";
import type { ProjectDetail, RoadmapPayload } from "@/lib/types";
import RoadmapClient from "./roadmap-client";

export default async function RoadmapPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  const [data, project] = await Promise.all([
    apiJson<RoadmapPayload>(`/api/projects/${id}/roadmap`),
    apiJson<ProjectDetail>(`/api/projects/${id}`),
  ]);

  return (
    <RoadmapClient
      projectId={id}
      currentUserId={user?.id ?? ""}
      members={project.members ?? []}
      initialItems={data.items}
      initialReleases={data.releases}
    />
  );
}
