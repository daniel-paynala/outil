import Link from "next/link";
import { apiJson } from "@/lib/api/server";
import type { Project } from "@/lib/types";
import { FolderKanban } from "lucide-react";
import NewProjectForm from "./new-project-form";

export default async function ProjectsPage() {
  let projects: Project[] = [];
  let error: string | null = null;

  try {
    projects = await apiJson<Project[]>("/api/projects");
  } catch (e) {
    error = e instanceof Error ? e.message : String(e);
  }

  return (
    <div className="space-y-8">
      <header className="flex items-end justify-between">
        <div>
          <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
            Espace de travail
          </p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight">
            Projets
          </h1>
          <p className="mt-2 text-sm text-[var(--muted)]">
            Chaque projet regroupe ses tâches, sa doc, ses commits, ses secrets.
          </p>
        </div>
      </header>

      <NewProjectForm />

      {error && (
        <p className="text-sm text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded-md px-3 py-2">
          {error}
        </p>
      )}

      {projects.length === 0 && !error && (
        <div className="border border-dashed border-[var(--border)] rounded-lg py-16 text-center">
          <FolderKanban
            size={24}
            strokeWidth={1.5}
            className="mx-auto text-[var(--muted)]"
          />
          <p className="mt-3 text-sm text-[var(--muted)]">
            Aucun projet pour le moment.
          </p>
          <p className="mt-1 text-xs text-[var(--muted)]">
            Crée ton premier projet ci-dessus.
          </p>
        </div>
      )}

      {projects.length > 0 && (
        <ul className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          {projects.map((p) => (
            <li key={p.id}>
              <Link
                href={`/projects/${p.id}`}
                className="block rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5 hover:border-[var(--color-neutral-400)] transition-colors"
              >
                <div className="flex items-start gap-3">
                  <span
                    className="mt-1 size-3 rounded-full shrink-0"
                    style={{ background: p.color }}
                    aria-hidden
                  />
                  <div className="flex-1 min-w-0">
                    <p className="font-medium truncate">{p.name}</p>
                    {p.description && (
                      <p className="mt-1 text-xs text-[var(--muted)] line-clamp-2">
                        {p.description}
                      </p>
                    )}
                    <p className="mt-3 text-[11px] text-[var(--muted)] font-mono">
                      {p.slug}
                    </p>
                  </div>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
