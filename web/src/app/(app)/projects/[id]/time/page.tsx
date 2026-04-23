import { apiJson } from "@/lib/api/server";
import { createClient } from "@/lib/supabase/server";
import type { BoardColumn, TimeEntry } from "@/lib/types";
import TimeClient from "./time-client";

export default async function TimePage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  const [entries, columns, running] = await Promise.all([
    apiJson<TimeEntry[]>(`/api/projects/${id}/time`),
    apiJson<BoardColumn[]>(`/api/projects/${id}/columns`),
    apiJson<TimeEntry | null>(`/api/time/running`),
  ]);

  const cards = columns.flatMap((c) =>
    c.cards.map((card) => ({ id: card.id, title: card.title })),
  );

  const activeHere = running && running.project_id === id ? running : null;

  return (
    <TimeClient
      projectId={id}
      currentUserId={user?.id ?? ""}
      initialEntries={entries}
      initialCards={cards}
      initialRunning={activeHere}
    />
  );
}
