import { apiJson } from "@/lib/api/server";
import { createClient } from "@/lib/supabase/server";
import type { BoardColumn, Label, ProjectDetail } from "@/lib/types";
import Board from "./board";

export default async function TasksPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  const [columns, project, labels] = await Promise.all([
    apiJson<BoardColumn[]>(`/api/projects/${id}/columns`),
    apiJson<ProjectDetail>(`/api/projects/${id}`),
    apiJson<Label[]>(`/api/projects/${id}/labels`),
  ]);

  return (
    <Board
      projectId={id}
      initialColumns={columns}
      members={project.members ?? []}
      initialLabels={labels}
      currentUserId={user?.id ?? ""}
    />
  );
}
