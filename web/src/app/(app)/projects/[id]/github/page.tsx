import { apiJson } from "@/lib/api/server";
import type { GithubCommit, GithubRepo } from "@/lib/types";
import GithubClient from "./github-client";

export default async function GithubPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const [repos, commits] = await Promise.all([
    apiJson<GithubRepo[]>(`/api/projects/${id}/github/repos`),
    apiJson<GithubCommit[]>(`/api/projects/${id}/github/commits`),
  ]);

  return <GithubClient projectId={id} initialRepos={repos} initialCommits={commits} />;
}
