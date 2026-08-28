import TimelineClient from "./timeline-client";

export default async function TimelinePage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  return <TimelineClient projectId={id} />;
}
