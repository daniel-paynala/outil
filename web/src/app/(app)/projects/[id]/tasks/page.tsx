import { apiJson } from "@/lib/api/server";
import type { BoardColumn } from "@/lib/types";
import Board from "./board";

export default async function TasksPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const columns = await apiJson<BoardColumn[]>(`/api/projects/${id}/columns`);

  return <Board projectId={id} initialColumns={columns} />;
}
