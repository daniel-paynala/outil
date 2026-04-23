import { apiJson } from "@/lib/api/server";
import type { DocPageSummary } from "@/lib/types";
import DocTree from "./doc-tree";

export default async function DocsLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const pages = await apiJson<DocPageSummary[]>(`/api/projects/${id}/docs`);

  return (
    <div className="flex gap-6 -mx-8 px-8 min-h-[calc(100vh-16rem)]">
      <aside className="w-64 shrink-0 border-r border-[var(--border)] pr-4">
        <DocTree projectId={id} initialPages={pages} />
      </aside>
      <div className="flex-1 min-w-0">{children}</div>
    </div>
  );
}
