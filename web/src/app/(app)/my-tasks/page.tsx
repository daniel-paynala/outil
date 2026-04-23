import Link from "next/link";
import { apiJson } from "@/lib/api/server";
import type { MyTask } from "@/lib/types";
import { Calendar, Clock, Link2, GitBranch, ListChecks } from "lucide-react";

export default async function MyTasksPage() {
  let tasks: MyTask[] = [];
  let error: string | null = null;

  try {
    tasks = await apiJson<MyTask[]>("/api/me/tasks");
  } catch (e) {
    error = e instanceof Error ? e.message : String(e);
  }

  const buckets = bucketByDue(tasks);

  return (
    <div className="space-y-8">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Vue cross-projets
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
          Mes tâches
        </h1>
        <p className="mt-2 text-sm text-[var(--muted)]">
          Toutes les cartes qui te sont assignées, tous projets confondus.
        </p>
      </header>

      {error && (
        <p className="text-sm text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded-md px-3 py-2">
          {error}
        </p>
      )}

      {tasks.length === 0 && !error && (
        <div className="border border-dashed border-[var(--border)] rounded-lg py-16 text-center">
          <ListChecks
            size={24}
            strokeWidth={1.5}
            className="mx-auto text-[var(--muted)]"
          />
          <p className="mt-3 text-sm text-[var(--muted)]">
            Rien à faire — ou personne ne t'a encore assigné de carte.
          </p>
        </div>
      )}

      {buckets.overdue.length > 0 && (
        <Bucket
          title="En retard"
          tone="danger"
          count={buckets.overdue.length}
          tasks={buckets.overdue}
        />
      )}
      {buckets.soon.length > 0 && (
        <Bucket
          title="Cette semaine"
          tone="warning"
          count={buckets.soon.length}
          tasks={buckets.soon}
        />
      )}
      {buckets.later.length > 0 && (
        <Bucket
          title="Plus tard"
          count={buckets.later.length}
          tasks={buckets.later}
        />
      )}
      {buckets.noDue.length > 0 && (
        <Bucket
          title="Sans échéance"
          count={buckets.noDue.length}
          tasks={buckets.noDue}
        />
      )}
    </div>
  );
}

function Bucket({
  title,
  count,
  tasks,
  tone,
}: {
  title: string;
  count: number;
  tasks: MyTask[];
  tone?: "danger" | "warning";
}) {
  return (
    <section>
      <h2 className="flex items-center gap-2 text-sm font-medium mb-3">
        <span
          className={
            tone === "danger"
              ? "text-[var(--color-danger)]"
              : tone === "warning"
                ? "text-[var(--color-warning)]"
                : ""
          }
        >
          {title}
        </span>
        <span className="text-[11px] text-[var(--muted)] bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] rounded-full px-1.5 py-0.5">
          {count}
        </span>
      </h2>

      <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
        {tasks.map((t) => (
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
                <p className="text-sm truncate">{t.title}</p>
                <p className="text-xs text-[var(--muted)] truncate mt-0.5">
                  {t.project.name} · {t.column.name}
                </p>
              </div>

              <div className="flex items-center gap-2 shrink-0 flex-wrap justify-end">
                {(t.labels ?? []).slice(0, 3).map((l) => (
                  <span
                    key={l.id}
                    className="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 text-white"
                    style={{ background: l.color }}
                  >
                    {l.name}
                  </span>
                ))}

                {t.priority && (
                  <span
                    className={
                      t.priority === "urgent"
                        ? "text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]"
                        : t.priority === "high"
                          ? "text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-[var(--color-warning)]/10 text-[var(--color-warning)]"
                          : t.priority === "medium"
                            ? "text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-[var(--color-info)]/10 text-[var(--color-info)]"
                            : "text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]"
                    }
                  >
                    {t.priority}
                  </span>
                )}

                {t.due_date && (
                  <span className="inline-flex items-center gap-1 text-[11px] text-[var(--muted)]">
                    <Calendar size={11} />
                    {formatDue(t.due_date)}
                  </span>
                )}

                {t.estimate && (
                  <span className="inline-flex items-center gap-1 text-[11px] text-[var(--muted)]">
                    <Clock size={11} />
                    {t.estimate}
                  </span>
                )}

                {t.dependencies_count > 0 && (
                  <span
                    className="inline-flex items-center gap-1 text-[11px] text-[var(--color-warning)]"
                    title="Bloquée par d'autres"
                  >
                    <Link2 size={11} />
                    {t.dependencies_count}
                  </span>
                )}

                {t.children_count > 0 && (
                  <span
                    className="inline-flex items-center gap-1 text-[11px] text-[var(--muted)]"
                    title="Sous-tâches"
                  >
                    <GitBranch size={11} />
                    {t.children_count}
                  </span>
                )}
              </div>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

function bucketByDue(tasks: MyTask[]) {
  const now = Date.now();
  const oneWeek = 7 * 24 * 60 * 60 * 1000;

  const overdue: MyTask[] = [];
  const soon: MyTask[] = [];
  const later: MyTask[] = [];
  const noDue: MyTask[] = [];

  for (const t of tasks) {
    if (!t.due_date) {
      noDue.push(t);
      continue;
    }
    const d = new Date(t.due_date).getTime();
    if (d < now) overdue.push(t);
    else if (d - now < oneWeek) soon.push(t);
    else later.push(t);
  }

  return { overdue, soon, later, noDue };
}

function formatDue(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}
