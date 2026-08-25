"use client";

import { useState } from "react";
import {
  GitBranch,
  GitCommit,
  Plus,
  RefreshCw,
  Trash2,
  ExternalLink,
  Loader2,
  Smartphone,
  Globe,
  Server,
  Package,
} from "lucide-react";
import { clsx } from "clsx";
import type {
  GithubCommit as Commit,
  GithubPlatform,
  GithubRepo,
} from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import Avatar from "@/core/ui/avatar";

const PLATFORMS: { key: GithubPlatform; label: string; icon: typeof Smartphone }[] = [
  { key: "mobile", label: "Mobile", icon: Smartphone },
  { key: "web", label: "Web", icon: Globe },
  { key: "api", label: "API / Back", icon: Server },
  { key: "other", label: "Autre", icon: Package },
];

export default function GithubClient({
  projectId,
  initialRepos,
  initialCommits,
}: {
  projectId: string;
  initialRepos: GithubRepo[];
  initialCommits: Commit[];
}) {
  const toast = useToast();
  const [repos, setRepos] = useState<GithubRepo[]>(initialRepos);
  const [commits, setCommits] = useState<Commit[]>(initialCommits);
  const [linking, setLinking] = useState(false);
  const [filterRepoId, setFilterRepoId] = useState<string | null>(null);

  async function refreshCommits(repoId?: string) {
    const qs = repoId ? `?repo=${repoId}` : "";
    const res = await apiFetch(`/api/projects/${projectId}/github/commits${qs}`);
    if (res.ok) setCommits((await res.json()) as Commit[]);
  }

  async function syncRepo(repo: GithubRepo) {
    setRepos((prev) =>
      prev.map((r) => (r.id === repo.id ? { ...r, __syncing: true } as GithubRepo : r)),
    );
    try {
      const res = await apiFetch(`/api/github/repos/${repo.id}/sync`, {
        method: "POST",
      });
      setRepos((prev) =>
        prev.map((r) => (r.id === repo.id ? { ...r, __syncing: false } as GithubRepo : r)),
      );
      if (res.ok) {
        // Refresh the repo list to pick up last_synced_at
        const listRes = await apiFetch(`/api/projects/${projectId}/github/repos`);
        if (listRes.ok) setRepos((await listRes.json()) as GithubRepo[]);
        await refreshCommits(filterRepoId ?? undefined);
        toast.success("Synchronisé", repo.full_name);
      } else {
        const body = await res.json().catch(() => null);
        toast.error(
          "Synchronisation impossible",
          body?.detail ?? body?.message ?? `Erreur ${res.status}`,
        );
      }
    } catch (err) {
      setRepos((prev) =>
        prev.map((r) => (r.id === repo.id ? { ...r, __syncing: false } as GithubRepo : r)),
      );
      toast.error(
        "Synchronisation impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  async function unlinkRepo(repo: GithubRepo) {
    if (!confirm(`Délier ${repo.full_name} du projet ?`)) return;
    try {
      const res = await apiFetch(`/api/github/repos/${repo.id}`, {
        method: "DELETE",
      });
      if (res.ok) {
        setRepos((prev) => prev.filter((r) => r.id !== repo.id));
        setCommits((prev) => prev.filter((c) => c.github_repo_id !== repo.id));
        if (filterRepoId === repo.id) setFilterRepoId(null);
        toast.success("Délié", repo.full_name);
      } else {
        toast.error(
          "Déliaison impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
      }
    } catch (err) {
      toast.error(
        "Déliaison impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  const visibleCommits = filterRepoId
    ? commits.filter((c) => c.github_repo_id === filterRepoId)
    : commits;

  return (
    <div className="space-y-8">
      <header className="flex items-start justify-between gap-4">
        <div>
          <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
            Code source
          </p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight flex items-center gap-2">
            <GitBranch size={22} strokeWidth={1.75} />
            GitHub
          </h1>
          <p className="mt-2 text-sm text-[var(--muted)] max-w-2xl">
            Un projet peut lier plusieurs repos (un par plateforme : mobile,
            web, API…). Commits récupérés à la demande via l'API GitHub.
          </p>
        </div>
        <button
          onClick={() => setLinking(true)}
          className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1.5 text-sm font-medium"
        >
          <Plus size={14} />
          Lier un repo
        </button>
      </header>

      <section className="space-y-2">
        <h2 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)]">
          Repos liés ({repos.length})
        </h2>
        {repos.length === 0 ? (
          <div className="border border-dashed border-[var(--border)] rounded-lg py-10 text-center">
            <GitBranch
              size={22}
              strokeWidth={1.5}
              className="mx-auto text-[var(--muted)]"
            />
            <p className="mt-3 text-sm text-[var(--muted)]">
              Aucun repo lié. Crée un Personal Access Token GitHub avec le
              scope <span className="font-mono text-xs">repo</span>, puis lie
              ton repo.
            </p>
          </div>
        ) : (
          <ul className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {repos.map((r) => (
              <RepoCard
                key={r.id}
                repo={r}
                onSync={() => syncRepo(r)}
                onUnlink={() => unlinkRepo(r)}
                onFilter={() =>
                  setFilterRepoId((cur) => (cur === r.id ? null : r.id))
                }
                filterActive={filterRepoId === r.id}
              />
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)]">
            {filterRepoId
              ? `Commits — ${repos.find((r) => r.id === filterRepoId)?.full_name ?? ""}`
              : `Feed commits (${visibleCommits.length})`}
          </h2>
          {filterRepoId && (
            <button
              onClick={() => setFilterRepoId(null)}
              className="text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
            >
              Voir tous
            </button>
          )}
        </div>

        {visibleCommits.length === 0 ? (
          <p className="text-sm text-[var(--muted)] border border-dashed border-[var(--border)] rounded-lg py-8 text-center">
            Aucun commit récupéré. Clique "Synchroniser" sur un repo lié.
          </p>
        ) : (
          <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
            {visibleCommits.map((c) => (
              <li key={c.id} className="px-4 py-3 flex items-start gap-3">
                <Avatar
                  user={{
                    name: c.author_name,
                    email: c.author_email,
                    avatar_url: c.author_avatar_url,
                  }}
                  size="md"
                />
                <div className="flex-1 min-w-0">
                  <p className="text-sm leading-snug line-clamp-2">
                    {firstLine(c.message)}
                  </p>
                  <p className="mt-1 text-xs text-[var(--muted)] flex items-center gap-2 flex-wrap">
                    <span>{c.author_login ?? c.author_name ?? "—"}</span>
                    {c.repo && (
                      <>
                        <span>·</span>
                        <span className="font-mono">{c.repo.full_name}</span>
                      </>
                    )}
                    <span>·</span>
                    <span>{formatDate(c.authored_at)}</span>
                    <span>·</span>
                    <span className="font-mono">{c.sha.slice(0, 7)}</span>
                  </p>
                </div>
                {c.html_url && (
                  <a
                    href={c.html_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="p-1 rounded text-[var(--muted)] hover:text-[var(--foreground)]"
                    title="Voir sur GitHub"
                  >
                    <ExternalLink size={13} />
                  </a>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      {linking && (
        <LinkRepoDrawer
          projectId={projectId}
          onClose={() => setLinking(false)}
          onCreated={(repo) => {
            setRepos((prev) => [...prev, repo]);
            setLinking(false);
            // Auto-reload commits since initial sync ran on backend
            refreshCommits();
          }}
        />
      )}
    </div>
  );
}

function RepoCard({
  repo,
  onSync,
  onUnlink,
  onFilter,
  filterActive,
}: {
  repo: GithubRepo & { __syncing?: boolean };
  onSync: () => void;
  onUnlink: () => void;
  onFilter: () => void;
  filterActive: boolean;
}) {
  const meta = PLATFORMS.find((p) => p.key === repo.platform) ?? PLATFORMS[3];
  const Icon = meta.icon;

  return (
    <li
      className={clsx(
        "rounded-lg border p-4 bg-[var(--surface)] transition-colors",
        filterActive
          ? "border-[var(--color-brand-red)]"
          : "border-[var(--border)] hover:border-[var(--color-neutral-400)]",
      )}
    >
      <div className="flex items-start gap-3">
        <span className="size-9 rounded-md bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] flex items-center justify-center shrink-0">
          <Icon size={15} strokeWidth={1.75} />
        </span>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <p className="text-sm font-medium truncate">{repo.full_name}</p>
            <span className="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]">
              {meta.label}
            </span>
          </div>
          {repo.description && (
            <p className="mt-1 text-xs text-[var(--muted)] line-clamp-2">
              {repo.description}
            </p>
          )}
          <p className="mt-2 text-[11px] text-[var(--muted)]">
            <span className="font-mono">{repo.default_branch}</span>
            <> · </>
            <GitCommit size={10} className="inline" /> {repo.commits_count ?? 0} commits
            <> · </>
            {repo.last_synced_at ? (
              <>Sync {formatDate(repo.last_synced_at)}</>
            ) : (
              <>Pas encore synchronisé</>
            )}
          </p>
        </div>
      </div>

      <div className="mt-3 flex items-center gap-2">
        <button
          onClick={onSync}
          disabled={repo.__syncing}
          className="inline-flex items-center gap-1 rounded-md border border-[var(--border)] px-2 py-1 text-xs hover:border-[var(--color-neutral-400)] disabled:opacity-50"
        >
          {repo.__syncing ? (
            <Loader2 size={11} className="animate-spin" />
          ) : (
            <RefreshCw size={11} />
          )}
          Synchroniser
        </button>
        <button
          onClick={onFilter}
          className={clsx(
            "inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs",
            filterActive
              ? "border-[var(--color-brand-red)] text-[var(--color-brand-red)]"
              : "border-[var(--border)] hover:border-[var(--color-neutral-400)]",
          )}
        >
          Filtrer commits
        </button>
        <a
          href={`https://github.com/${repo.full_name}`}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1 rounded-md border border-[var(--border)] px-2 py-1 text-xs hover:border-[var(--color-neutral-400)] ml-auto"
        >
          <ExternalLink size={11} />
          GitHub
        </a>
        <button
          onClick={onUnlink}
          className="p-1.5 rounded text-[var(--muted)] hover:text-[var(--color-danger)]"
          title="Délier"
        >
          <Trash2 size={12} />
        </button>
      </div>
    </li>
  );
}

function LinkRepoDrawer({
  projectId,
  onClose,
  onCreated,
}: {
  projectId: string;
  onClose: () => void;
  onCreated: (repo: GithubRepo) => void;
}) {
  const toast = useToast();
  const [fullName, setFullName] = useState("");
  const [platform, setPlatform] = useState<GithubPlatform>("web");
  const [token, setToken] = useState("");
  const [description, setDescription] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const res = await apiFetch(`/api/projects/${projectId}/github/repos`, {
        method: "POST",
        body: JSON.stringify({
          full_name: fullName.trim(),
          platform,
          access_token: token.trim(),
          description: description.trim() || null,
        }),
      });
      setLoading(false);
      if (!res.ok) {
        const body = await res.json().catch(() => null);
        const msg = body?.message ?? body?.detail ?? `Erreur ${res.status}`;
        setError(msg);
        toast.error("Liaison impossible", msg);
        return;
      }
      const repo = (await res.json()) as GithubRepo;
      toast.success("Lié", repo.full_name);
      onCreated(repo);
    } catch (err) {
      setLoading(false);
      const msg = err instanceof Error ? err.message : String(err);
      setError(msg);
      toast.error("Liaison impossible", msg);
    }
  }

  return (
    <div className="fixed inset-0 z-40 flex">
      <div className="flex-1 bg-black/20" onClick={onClose} />
      <aside className="w-full max-w-md bg-[var(--background)] border-l border-[var(--border)] flex flex-col shadow-2xl">
        <header className="px-6 py-4 border-b border-[var(--border)] flex items-center">
          <h3 className="text-sm font-medium flex-1">Lier un repo GitHub</h3>
          <button
            onClick={onClose}
            className="text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
          >
            Fermer
          </button>
        </header>

        <form onSubmit={submit} className="flex-1 overflow-y-auto px-6 py-5 space-y-4">
          <div className="space-y-1.5">
            <label className="text-xs font-medium">
              Repo <span className="text-[var(--color-brand-red)]">*</span>
            </label>
            <input
              required
              autoFocus
              value={fullName}
              onChange={(e) => setFullName(e.target.value)}
              placeholder="owner/repo"
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm font-mono"
            />
            <p className="text-[11px] text-[var(--muted)]">
              Format <span className="font-mono">daniel-paynala/outil</span>
            </p>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-medium">
              Plateforme <span className="text-[var(--color-brand-red)]">*</span>
            </label>
            <div className="grid grid-cols-2 gap-1.5">
              {PLATFORMS.map((p) => {
                const Icon = p.icon;
                const active = platform === p.key;
                return (
                  <button
                    key={p.key}
                    type="button"
                    onClick={() => setPlatform(p.key)}
                    className={clsx(
                      "inline-flex items-center gap-1.5 rounded-md border px-2.5 py-2 text-xs",
                      active
                        ? "border-[var(--color-brand-red)] text-[var(--color-brand-red)]"
                        : "border-[var(--border)] hover:border-[var(--color-neutral-400)]",
                    )}
                  >
                    <Icon size={13} strokeWidth={1.75} />
                    {p.label}
                  </button>
                );
              })}
            </div>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-medium">
              Personal Access Token{" "}
              <span className="text-[var(--color-brand-red)]">*</span>
            </label>
            <input
              required
              type="password"
              value={token}
              onChange={(e) => setToken(e.target.value)}
              placeholder="ghp_…"
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm font-mono"
            />
            <p className="text-[11px] text-[var(--muted)]">
              Scope requis : <span className="font-mono">repo</span> (lecture).
              Stocké chiffré côté serveur.
            </p>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-medium">Description (optionnel)</label>
            <input
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
            />
          </div>

          {error && (
            <p className="text-xs text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded px-3 py-2">
              {error}
            </p>
          )}
        </form>

        <footer className="px-6 py-4 border-t border-[var(--border)] flex gap-2">
          <button
            onClick={submit}
            disabled={loading || !fullName.trim() || !token.trim()}
            className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-4 py-2 text-sm font-medium disabled:opacity-50"
          >
            {loading && <Loader2 size={13} className="animate-spin" />}
            Lier & synchroniser
          </button>
          <button
            onClick={onClose}
            className="text-sm text-[var(--muted)] hover:text-[var(--foreground)] px-3 py-2"
          >
            Annuler
          </button>
        </footer>
      </aside>
    </div>
  );
}

function firstLine(s: string): string {
  return s.split("\n")[0];
}

function formatDate(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}
