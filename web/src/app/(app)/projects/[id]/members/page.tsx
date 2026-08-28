import { notFound } from "next/navigation";
import { apiJson, apiFetch } from "@/lib/api/server";
import { createClient } from "@/lib/supabase/server";
import MembersClient, { type ProjectMember } from "./members-client";

export default async function MembersPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) notFound();

  const res = await apiFetch(`/api/projects/${id}/members`);
  if (res.status === 403 || res.status === 404) notFound();

  const members: ProjectMember[] = await res.json();
  const me = members.find((m) => m.user_id === user.id);
  const isOwner = me?.role === "owner";

  // Récupère le rôle global pour info (admin = pas spécial ici, juste owner d'un projet)
  let globalRole: "admin" | "member" = "member";
  try {
    const meData = await apiJson<{ user: { role: "admin" | "member" } }>(
      "/api/me",
    );
    globalRole = meData.user.role;
  } catch {
    /* ignore */
  }

  return (
    <MembersClient
      projectId={id}
      initialMembers={members}
      currentUserId={user.id}
      isOwner={isOwner}
      isPlatformAdmin={globalRole === "admin"}
    />
  );
}
