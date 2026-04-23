import { apiJson } from "@/lib/api/server";
import type { ProjectFile } from "@/lib/types";
import DocumentsClient from "./documents-client";

export default async function DocumentsPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const files = await apiJson<ProjectFile[]>(`/api/projects/${id}/files`);

  return <DocumentsClient projectId={id} initialFiles={files} />;
}
