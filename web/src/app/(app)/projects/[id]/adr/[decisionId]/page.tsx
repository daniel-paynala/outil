import { apiJson } from "@/lib/api/server";
import type { Decision } from "@/lib/types";
import DecisionEditor from "./decision-editor";

export default async function DecisionRoute({
  params,
}: {
  params: Promise<{ id: string; decisionId: string }>;
}) {
  const { id, decisionId } = await params;
  const decision = await apiJson<Decision>(`/api/decisions/${decisionId}`);
  return <DecisionEditor projectId={id} decision={decision} />;
}
