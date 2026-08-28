import { notFound } from "next/navigation";
import { apiFetch, apiJson } from "@/lib/api/server";
import { createClient } from "@/lib/supabase/server";
import type { Project } from "@/lib/types";
import SettingsClient from "./settings-client";

type ProjectMember = {
  user_id: string;
  role: "owner" | "member";
};

type ProjectWithFlags = Project & {
  can_delete?: boolean;
  created_by?: string;
};

export default async function SettingsPage({
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

  const project = await apiJson<ProjectWithFlags>(`/api/projects/${id}`);

  const membersRes = await apiFetch(`/api/projects/${id}/members`);
  const members: ProjectMember[] = membersRes.ok ? await membersRes.json() : [];
  const me = members.find((m) => m.user_id === user.id);
  const isOwner = me?.role === "owner";

  // Suppression : admin plateforme OU créateur du projet
  const canDelete = project.can_delete === true;

  return (
    <SettingsClient
      project={project}
      isOwner={isOwner}
      canDelete={canDelete}
    />
  );
}
