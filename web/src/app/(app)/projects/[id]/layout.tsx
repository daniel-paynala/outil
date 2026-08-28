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
    <div className="space-y-4">
      <Link
        href="/projects"
        className="inline-flex items-center gap-1 text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
      >
        <ChevronLeft size={13} strokeWidth={2} />
        Tous les projets
      </Link>

      <div className="flex gap-5 items-start -ml-4 sm:-ml-6 lg:-ml-8">
        {/* Sidebar projet — sticky, ne scrolle pas avec le contenu */}
        <aside className="w-52 shrink-0 self-start sticky top-4 max-h-[calc(100vh-2rem)] overflow-y-auto pl-1">
          <header className="flex items-start gap-2 mb-5 px-3">
            <span
              className="mt-1.5 size-2.5 rounded-full shrink-0"
              style={{ background: project.color }}
              aria-hidden
            />
            <div className="flex-1 min-w-0">
              <h1 className="text-base font-semibold tracking-tight leading-tight truncate">
                {project.name}
              </h1>
              <p className="mt-0.5 text-[10px] text-[var(--muted)] font-mono truncate">
                {project.slug}
              </p>
            </div>
          </header>

          <ProjectTabs projectId={id} />
        </aside>

        {/* Contenu */}
        <main className="flex-1 min-w-0">{children}</main>
      </div>
    </div>
  );
}
