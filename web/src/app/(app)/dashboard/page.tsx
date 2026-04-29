import Link from "next/link";
import { redirect } from "next/navigation";
import {
  AlertCircle,
  ArrowRight,
  BookOpen,
  CalendarClock,
  Code2,
  FolderKanban,
  GitBranch,
  KeyRound,
  Layers,
  ListChecks,
  ListTodo,
  Map,
  Paperclip,
  Bell,
  Search,
  ScrollText,
  Timer,
  Activity,
  type LucideIcon,
} from "lucide-react";
import { clsx } from "clsx";
import { createClient } from "@/lib/supabase/server";
import { apiJson } from "@/lib/api/server";
import type { MyTask, Project } from "@/lib/types";

type ModuleEntry = {
  name: string;
  description: string;
  icon: LucideIcon;
  href?: string;
  scope: "global" | "project";
  status: "active" | "soon";
};

const GLOBAL_MODULES: ModuleEntry[] = [
  { name: "Projets", description: "Tous tes projets et leurs membres", icon: FolderKanban, href: "/projects", scope: "global", status: "active" },
  { name: "Mes tâches", description: "Tâches assignées cross-projets, classées par échéance", icon: ListChecks, href: "/my-tasks", scope: "global", status: "active" },
  { name: "Recherche unifiée", description: "Cartes, docs, décisions, secrets, fichiers", icon: Search, href: "/search", scope: "global", status: "active" },
  { name: "Snippets", description: "Bibliothèque de code réutilisable", icon: Code2, scope: "global", status: "soon" },
  { name: "Agenda", description: "Vue calendrier cross-projets", icon: CalendarClock, scope: "global", status: "soon" },
  { name: "Notifications", description: "PRs à reviewer, mentions, alertes", icon: Bell, scope: "global", status: "soon" },
];

const PROJECT_MODULES: ModuleEntry[] = [
  { name: "Tâches", description: "Board Trello-like avec drag & drop", icon: ListTodo, scope: "project", status: "active" },
  { name: "Roadmap", description: "Vision et trajectoire du projet, 6 vues", icon: Map, scope: "project", status: "active" },
  { name: "Documents", description: "Fichiers du projet (Supabase Storage)", icon: Paperclip, scope: "project", status: "active" },
  { name: "Documentation", description: "Pages markdown avec versionning", icon: BookOpen, scope: "project", status: "active" },
  { name: "Coffre", description: "Secrets chiffrés AES-256 + audit log", icon: KeyRound, scope: "project", status: "active" },
  { name: "Temps", description: "Timer + entrées manuelles + agrégats", icon: Timer, scope: "project", status: "active" },
  { name: "Décisions", description: "Architectural Decision Records", icon: ScrollText, scope: "project", status: "active" },
  { name: "GitHub", description: "Repos liés (multi-plateformes), commits", icon: GitBranch, scope: "project", status: "active" },
  { name: "Journal", description: "Activité cross-modules du projet", icon: Activity, scope: "project", status: "active" },
];

export default async function DashboardPage() {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) redirect("/login");

  let projects: Project[] = [];
  let tasks: MyTask[] = [];
  let apiHealthy = true;
  try {
    const [p, t] = await Promise.all([
      apiJson<Project[]>("/api/projects"),
      apiJson<MyTask[]>("/api/me/tasks"),
    ]);
    projects = p;
    tasks = t;
  } catch {
    apiHealthy = false;
  }

  const now = Date.now();
  const overdue = tasks.filter(
    (t) => t.due_date && new Date(t.due_date).getTime() < now,
  );
  const dueThisWeek = tasks.filter((t) => {
    if (!t.due_date) return false;
    const d = new Date(t.due_date).getTime();
    if (d < now) return false;
    return d - now <= 7 * 24 * 60 * 60 * 1000;
  });

  const upcomingTasks = [...overdue, ...dueThisWeek].slice(0, 6);

  return (
    <div className="space-y-10">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Bienvenue
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
          {user.email?.split("@")[0]
            ? `Bonjour ${capitalize(user.email.split("@")[0])}`
            : "Tableau de bord"}
        </h1>
        <p className="mt-2 text-sm text-[var(--muted)]">
          {projects.length === 0
            ? "Démarre en créant ton premier projet."
            : `Tu pilotes ${projects.length} projet${projects.length > 1 ? "s" : ""} sur Arche.`}
        </p>
      </header>

      <section className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <Stat
          label="Projets actifs"
          value={projects.length}
          icon={FolderKanban}
          href="/projects"
        />
        <Stat
          label="Mes tâches"
          value={tasks.length}
          icon={ListChecks}
          href="/my-tasks"
        />
        <Stat
          label="Cette semaine"
          value={dueThisWeek.length}
          icon={CalendarClock}
          tone={dueThisWeek.length > 0 ? "warning" : "neutral"}
          href="/my-tasks"
        />
        <Stat
          label="En retard"
          value={overdue.length}
          icon={AlertCircle}
          tone={overdue.length > 0 ? "danger" : "neutral"}
          href="/my-tasks"
        />
      </section>

      {upcomingTasks.length > 0 && (
        <section>
          <div className="flex items-center justify-between mb-3">
            <h2 className="text-sm font-medium">Tâches imminentes</h2>
            <Link
              href="/my-tasks"
              className="text-xs text-[var(--color-brand-red)] hover:underline inline-flex items-center gap-1"
            >
              Toutes mes tâches <ArrowRight size={11} />
            </Link>
          </div>
          <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
            {upcomingTasks.map((t) => {
              const isOverdue = t.due_date && new Date(t.due_date).getTime() < now;
              return (
                <li key={t.id}>
                  <Link
                    href={`/projects/${t.project.id}/tasks?card=${t.id}`}
                    className="flex items-center gap-3 px-4 py-3 hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50"
                  >
                    <span
                      className="size-2.5 rounded-full shrink-0"
                      style={{ background: t.project.color }}
                    />
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium truncate">{t.title}</p>
                      <p className="text-xs text-[var(--muted)] mt-0.5 truncate">
                        {t.project.name} · {t.column.name}
                      </p>
                    </div>
                    {t.due_date && (
                      <span
                        className={clsx(
                          "text-[11px] rounded px-1.5 py-0.5 shrink-0",
                          isOverdue
                            ? "bg-[var(--color-danger)]/10 text-[var(--color-danger)]"
                            : "bg-[var(--color-warning)]/10 text-[var(--color-warning)]",
                        )}
                      >
                        {formatRelativeDue(t.due_date)}
                      </span>
                    )}
                    {t.priority && (
                      <span
                        className={clsx(
                          "text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 shrink-0",
                          t.priority === "urgent" && "bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]",
                          t.priority === "high" && "bg-[var(--color-warning)]/10 text-[var(--color-warning)]",
                          t.priority === "medium" && "bg-[var(--color-info)]/10 text-[var(--color-info)]",
                          t.priority === "low" && "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]",
                        )}
                      >
                        {t.priority}
                      </span>
                    )}
                  </Link>
                </li>
              );
            })}
          </ul>
        </section>
      )}

      {projects.length > 0 && (
        <section>
          <div className="flex items-center justify-between mb-3">
            <h2 className="text-sm font-medium">Mes projets</h2>
            <Link
              href="/projects"
              className="text-xs text-[var(--color-brand-red)] hover:underline inline-flex items-center gap-1"
            >
              Tous les projets <ArrowRight size={11} />
            </Link>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            {projects.slice(0, 6).map((p) => (
              <Link
                key={p.id}
                href={`/projects/${p.id}`}
                className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-4 hover:border-[var(--color-neutral-400)] transition-colors"
              >
                <div className="flex items-start gap-3">
                  <span
                    className="mt-1 size-3 rounded-full shrink-0"
                    style={{ background: p.color }}
                  />
                  <div className="min-w-0 flex-1">
                    <p className="font-medium truncate">{p.name}</p>
                    {p.description && (
                      <p className="text-xs text-[var(--muted)] line-clamp-2 mt-1">
                        {p.description}
                      </p>
                    )}
                    <p className="text-[11px] text-[var(--muted)] font-mono mt-2">
                      {p.slug}
                    </p>
                  </div>
                </div>
              </Link>
            ))}
          </div>
        </section>
      )}

      <section>
        <div className="mb-3">
          <h2 className="text-sm font-medium">Modules globaux</h2>
          <p className="text-xs text-[var(--muted)] mt-0.5">
            Fonctionnalités cross-projets, accessibles depuis la sidebar
          </p>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          {GLOBAL_MODULES.map((m) => (
            <ModuleCard key={m.name} module={m} />
          ))}
        </div>
      </section>

      <section>
        <div className="mb-3">
          <h2 className="text-sm font-medium flex items-center gap-2">
            <Layers size={14} strokeWidth={1.75} />
            Modules par projet
          </h2>
          <p className="text-xs text-[var(--muted)] mt-0.5">
            Disponibles dans chaque projet via les onglets du hub
          </p>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
          {PROJECT_MODULES.map((m) => (
            <ModuleCard key={m.name} module={m} />
          ))}
        </div>
      </section>

      {!apiHealthy && (
        <section>
          <div className="rounded-md border border-[var(--color-warning)]/20 bg-[var(--color-warning)]/5 px-4 py-3 flex items-start gap-2">
            <AlertCircle size={14} className="text-[var(--color-warning)] mt-0.5" />
            <p className="text-xs text-[var(--color-warning)]">
              L&apos;API Laravel ne répond pas. Vérifie que le serveur est lancé
              (php artisan serve dans api/).
            </p>
          </div>
        </section>
      )}
    </div>
  );
}

function Stat({
  label,
  value,
  icon: Icon,
  tone = "neutral",
  href,
}: {
  label: string;
  value: number;
  icon: LucideIcon;
  tone?: "neutral" | "warning" | "danger" | "success";
  href?: string;
}) {
  const cls = clsx(
    "rounded-lg border bg-[var(--surface)] p-4 flex items-start gap-3 transition-colors",
    tone === "neutral" && "border-[var(--border)]",
    tone === "warning" && "border-[var(--color-warning)]/40 bg-[var(--color-warning)]/5",
    tone === "danger" && "border-[var(--color-danger)]/40 bg-[var(--color-danger)]/5",
    tone === "success" && "border-[var(--color-success)]/40 bg-[var(--color-success)]/5",
    href && "hover:border-[var(--color-neutral-400)]",
  );
  const iconCls = clsx(
    "size-9 rounded-md flex items-center justify-center shrink-0",
    tone === "neutral" && "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--foreground)]",
    tone === "warning" && "bg-[var(--color-warning)]/15 text-[var(--color-warning)]",
    tone === "danger" && "bg-[var(--color-danger)]/15 text-[var(--color-danger)]",
    tone === "success" && "bg-[var(--color-success)]/15 text-[var(--color-success)]",
  );
  const inner = (
    <>
      <span className={iconCls}>
        <Icon size={16} strokeWidth={1.75} />
      </span>
      <div>
        <p className="text-2xl font-semibold tracking-tight tabular-nums">
          {value}
        </p>
        <p className="text-xs text-[var(--muted)]">{label}</p>
      </div>
    </>
  );
  return href ? (
    <Link href={href} className={cls}>
      {inner}
    </Link>
  ) : (
    <div className={cls}>{inner}</div>
  );
}

function ModuleCard({ module }: { module: ModuleEntry }) {
  const Icon = module.icon;
  const inactive = module.status === "soon";
  const cls = clsx(
    "rounded-lg border bg-[var(--surface)] p-4 transition-colors",
    inactive
      ? "border-[var(--border)] opacity-60 cursor-not-allowed"
      : "border-[var(--border)] hover:border-[var(--color-neutral-400)]",
  );
  const inner = (
    <>
      <div className="flex items-center gap-2 mb-2">
        <span
          className={clsx(
            "size-7 rounded-md flex items-center justify-center shrink-0",
            inactive
              ? "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]"
              : "bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]",
          )}
        >
          <Icon size={14} strokeWidth={1.75} />
        </span>
        <p className="text-sm font-medium flex-1">{module.name}</p>
        {inactive && (
          <span className="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]">
            soon
          </span>
        )}
        {!inactive && module.scope === "project" && (
          <span className="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 bg-[var(--color-info)]/10 text-[var(--color-info)]">
            projet
          </span>
        )}
      </div>
      <p className="text-xs text-[var(--muted)] leading-snug">
        {module.description}
      </p>
    </>
  );
  return module.href && !inactive ? (
    <Link href={module.href} className={cls}>
      {inner}
    </Link>
  ) : (
    <div className={cls}>{inner}</div>
  );
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}

function formatRelativeDue(iso: string): string {
  const now = Date.now();
  const due = new Date(iso).getTime();
  const days = Math.floor((due - now) / (1000 * 60 * 60 * 24));
  if (days < 0) return `${Math.abs(days)}j de retard`;
  if (days === 0) return "Aujourd'hui";
  if (days === 1) return "Demain";
  if (days < 7) return `Dans ${days}j`;
  return new Date(iso).toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}
