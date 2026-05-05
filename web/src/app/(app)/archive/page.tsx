import Link from "next/link";
import { apiJson } from "@/lib/api/server";
import type { MyTask } from "@/lib/types";
import { Archive as ArchiveIcon, Calendar } from "lucide-react";

export const dynamic = "force-dynamic";

export default async function ArchivePage() {
  let tasks: MyTask[] = [];
  let error: string | null = null;

  try {
    tasks = await apiJson<MyTask[]>("/api/me/archive");
  } catch (e) {
    error = e instanceof Error ? e.message : String(e);
  }

  const groups = groupByMonth(tasks);

  return (
    <div className="space-y-8">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Vue cross-projets
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight">Archive</h1>
        <p className="mt-2 text-sm text-[var(--muted)]">
          Toutes les tâches que tu as terminées, du plus récent au plus ancien
          (500 dernières).
        </p>
      </header>

      {error && (
        <p className="text-sm text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded-md px-3 py-2">
          {error}
        </p>
      )}

      {tasks.length === 0 && !error && (
        <div className="border border-dashed border-[var(--border)] rounded-lg py-16 text-center">
          <ArchiveIcon
            size={24}
            strokeWidth={1.5}
            className="mx-auto text-[var(--muted)]"
          />
          <p className="mt-3 text-sm text-[var(--muted)]">
            Pas encore de tâche terminée. Glisse une carte dans une colonne de
            terminaison pour la voir apparaître ici.
          </p>
        </div>
      )}

      {groups.map((g) => (
        <section key={g.label}>
          <h2 className="flex items-center gap-2 text-sm font-medium mb-3">
            <span>{g.label}</span>
            <span className="text-[11px] text-[var(--muted)] bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] rounded-full px-1.5 py-0.5">
              {g.tasks.length}
            </span>
          </h2>

          <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
            {g.tasks.map((t) => (
              <li key={t.id}>
                <Link
                  href={`/projects/${t.project.id}/tasks?card=${t.id}`}
                  className="flex items-center gap-3 px-4 py-3 hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50"
                >
                  <span
                    className="size-2.5 rounded-full shrink-0"
                    style={{ background: t.project.color }}
                    title={t.project.name}
                    aria-hidden
                  />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm truncate line-through opacity-70">
                      {t.title}
                    </p>
                    <p className="text-xs text-[var(--muted)] truncate mt-0.5">
                      {t.project.name} · {t.column.name}
                    </p>
                  </div>

                  {t.completed_at && (
                    <span className="inline-flex items-center gap-1 text-[11px] text-[var(--muted)] shrink-0">
                      <Calendar size={11} />
                      {formatCompletedAt(t.completed_at)}
                    </span>
                  )}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}

type Group = { label: string; tasks: MyTask[] };

function groupByMonth(tasks: MyTask[]): Group[] {
  const groups: Group[] = [];
  let currentLabel: string | null = null;
  let currentBucket: MyTask[] = [];

  for (const t of tasks) {
    const label = monthLabel(t.completed_at);
    if (label !== currentLabel) {
      if (currentBucket.length > 0 && currentLabel) {
        groups.push({ label: currentLabel, tasks: currentBucket });
      }
      currentLabel = label;
      currentBucket = [];
    }
    currentBucket.push(t);
  }
  if (currentBucket.length > 0 && currentLabel) {
    groups.push({ label: currentLabel, tasks: currentBucket });
  }
  return groups;
}

function monthLabel(iso: string | null): string {
  if (!iso) return "Sans date";
  const d = new Date(iso);
  const now = new Date();
  if (d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth()) {
    return "Ce mois-ci";
  }
  const lastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  if (
    d.getFullYear() === lastMonth.getFullYear() &&
    d.getMonth() === lastMonth.getMonth()
  ) {
    return "Mois dernier";
  }
  return d.toLocaleDateString("fr-FR", { month: "long", year: "numeric" });
}

function formatCompletedAt(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}
