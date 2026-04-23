import Link from "next/link";
import { notFound } from "next/navigation";
import { apiFetch } from "@/lib/api/server";
import type { Project } from "@/lib/types";
import { ChevronLeft } from "lucide-react";
import ProjectTabs from "./project-tabs";

export default async function ProjectLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const res = await apiFetch(`/api/projects/${id}`);
  if (res.status === 404) notFound();

  const project: Project = await res.json();

  return (
    <div className="space-y-6">
      <Link
        href="/projects"
        className="inline-flex items-center gap-1 text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
      >
        <ChevronLeft size={13} strokeWidth={2} />
        Tous les projets
      </Link>

      <header className="flex items-start gap-3">
        <span
          className="mt-1.5 size-3 rounded-full shrink-0"
          style={{ background: project.color }}
          aria-hidden
        />
        <div className="flex-1 min-w-0">
          <h1 className="text-2xl font-semibold tracking-tight truncate">
            {project.name}
          </h1>
          <p className="mt-1 text-xs text-[var(--muted)] font-mono">
            {project.slug}
          </p>
          {project.description && (
            <p className="mt-3 text-sm text-[var(--muted)] max-w-2xl">
              {project.description}
            </p>
          )}
        </div>
      </header>

      <ProjectTabs projectId={id} />

      <div>{children}</div>
    </div>
  );
}
