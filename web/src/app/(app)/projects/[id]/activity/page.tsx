import { apiJson } from "@/lib/api/server";
import type { ActivityLog } from "@/lib/types";
import ActivityTimeline from "./activity-timeline";

export default async function ActivityPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const logs = await apiJson<ActivityLog[]>(`/api/projects/${id}/activity`);
  return <ActivityTimeline initialLogs={logs} />;
}
