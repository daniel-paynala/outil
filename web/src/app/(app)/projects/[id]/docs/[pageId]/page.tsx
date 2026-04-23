import { apiJson } from "@/lib/api/server";
import type { DocPage } from "@/lib/types";
import DocEditor from "./doc-editor";

export default async function DocPageRoute({
  params,
}: {
  params: Promise<{ id: string; pageId: string }>;
}) {
  const { id, pageId } = await params;
  const page = await apiJson<DocPage>(`/api/docs/${pageId}`);

  return <DocEditor projectId={id} page={page} />;
}
