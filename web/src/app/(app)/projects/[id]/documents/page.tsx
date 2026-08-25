import { apiJson } from "@/lib/api/server";
import type { ProjectFile, ProjectFolder } from "@/lib/types";
import DocumentsClient from "./documents-client";

export default async function DocumentsPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const [files, folders] = await Promise.all([
    apiJson<ProjectFile[]>(`/api/projects/${id}/files`),
    apiJson<ProjectFolder[]>(`/api/projects/${id}/folders`).catch(
      () => [] as ProjectFolder[],
    ),
  ]);

  return (
    <DocumentsClient
      projectId={id}
      initialFiles={files}
      initialFolders={folders}
    />
  );
}
