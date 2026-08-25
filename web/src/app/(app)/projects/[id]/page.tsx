import Link from "next/link";
import { apiJson } from "@/lib/api/server";
import type { Project, User } from "@/lib/types";
import Avatar from "@/core/ui/avatar";
import {
  ListChecks,
  CheckCircle2,
  AlertTriangle,
  Clock,
  Users,
  BookOpen,
  KeyRound,
  Paperclip,
  ScrollText,
  Activity,
  Timer,
  type LucideIcon,
} from "lucide-react";
import { clsx } from "clsx";

type ProjectDetail = Project & {
  creator?: User;
  members?: Array<User & { pivot: { role: string } }>;
};

type Stats = {
  cards: {
    total: number;
    open: number;
    done: number;
    overdue: number;
    upcoming_7d: number;
    by_priority: Record<string, number>;
    by_assignee: Array<{
      user_id: string;
      email: string | null;
      name: string | null;
      avatar_url?: string | null;
      count: number;
    }>;
  };
  upcoming_deadlines: Array<{
    id: string;
    title: string;
    due_date: string | null;
    priority: string | null;
    is_overdue: boolean;
  }>;
  recent_activity: Array<{
    id: string;
    action: string;
    subject_name: string | null;
    created_at: string;
    actor: { email: string; name: string | null } | null;
  }>;
  time_seconds: number;
  counts: {
    members: number;
    documents: number;
    docs: number;
    decisions: number;
    vault_entries: number;
  };
};

export default async function ProjectOverviewPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const [project, stats] = await Promise.all([
    apiJson<ProjectDetail>(`/api/projects/${id}`),
    apiJson<Stats>(`/api/projects/${id}/stats`),
  ]);

  const completionPct =
    stats.cards.total > 0
      ? Math.round((stats.cards.done / stats.cards.total) * 100)
      : 0;

  return (
    <section className="space-y-6">
      {/* Stats cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Stat
          label="Tâches ouvertes"
          value={stats.cards.open}
          icon={ListChecks}
          subtitle={`${stats.cards.done} terminées`}
          href={`/projects/${id}/tasks`}
        />
        <Stat
          label="En retard"
          value={stats.cards.overdue}
          icon={AlertTriangle}
          tone={stats.cards.overdue > 0 ? "danger" : "neutral"}
          href={`/projects/${id}/tasks`}
        />
        <Stat
          label="Cette semaine"
          value={stats.cards.upcoming_7d}
          icon={Clock}
          tone={stats.cards.upcoming_7d > 0 ? "warning" : "neutral"}
          href={`/projects/${id}/tasks`}
        />
        <Stat
          label="Temps cumulé"
          value={formatDuration(stats.time_seconds)}
          icon={Timer}
          href={`/projects/${id}/time`}
        />
      </div>

      {/* Progress + main grid */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-4">
          {/* Completion */}
          <Block title="Avancement">
            <div className="space-y-2">
              <div className="flex items-baseline justify-between">
                <p className="text-2xl font-semibold tabular-nums">
                  {completionPct}%
                </p>
                <p className="text-xs text-[var(--muted)]">
                  {stats.cards.done} / {stats.cards.total} tâches terminées
                </p>
              </div>
              <div className="h-2 rounded-full bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-800)] overflow-hidden">
                <div
                  className="h-full bg-[var(--color-success)] transition-all"
                  style={{ width: `${completionPct}%` }}
                />
              </div>
            </div>
          </Block>

          {/* À propos */}
          {project.description && (
            <Block title="À propos">
              <p className="text-sm text-[var(--muted)] whitespace-pre-wrap">
                {project.description}
              </p>
            </Block>
          )}

          {/* Prochaines échéances */}
          <Block
            title="Prochaines échéances"
            action={
              stats.upcoming_deadlines.length > 0 ? (
                <Link
                  href={`/projects/${id}/tasks`}
                  className="text-xs text-[var(--color-brand-red)] hover:underline"
                >
                  Voir le board →
                </Link>
              ) : null
            }
          >
            {stats.upcoming_deadlines.length === 0 ? (
              <p className="text-sm text-[var(--muted)]">
                Aucune échéance prochaine.
              </p>
            ) : (
              <ul className="space-y-2">
                {stats.upcoming_deadlines.map((c) => (
                  <li key={c.id}>
                    <Link
                      href={`/projects/${id}/tasks?card=${c.id}`}
                      className="flex items-center gap-3 px-3 py-2 -mx-3 rounded-md hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50"
                    >
                      <span className="flex-1 min-w-0 text-sm truncate">
                        {c.title}
                      </span>
                      {c.priority && (
                        <span
                          className={clsx(
                            "text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 shrink-0",
                            c.priority === "urgent" &&
                              "bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]",
                            c.priority === "high" &&
                              "bg-[var(--color-warning)]/10 text-[var(--color-warning)]",
                            c.priority === "medium" &&
                              "bg-[var(--color-info)]/10 text-[var(--color-info)]",
                            c.priority === "low" &&
                              "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]",
                          )}
                        >
                          {c.priority}
                        </span>
                      )}
                      {c.due_date && (
                        <span
                          className={clsx(
                            "text-[11px] rounded px-1.5 py-0.5 shrink-0",
                            c.is_overdue
                              ? "bg-[var(--color-danger)]/10 text-[var(--color-danger)]"
                              : "bg-[var(--color-warning)]/10 text-[var(--color-warning)]",
                          )}
                        >
                          {formatDue(c.due_date)}
                        </span>
                      )}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Block>

          {/* Activité récente */}
          <Block
            title="Activité récente"
            action={
              <Link
                href={`/projects/${id}/activity`}
                className="text-xs text-[var(--color-brand-red)] hover:underline"
              >
                Tout voir →
              </Link>
            }
          >
            {stats.recent_activity.length === 0 ? (
              <p className="text-sm text-[var(--muted)]">
                Pas encore d&apos;activité.
              </p>
            ) : (
              <ul className="space-y-1.5">
                {stats.recent_activity.slice(0, 6).map((log) => (
                  <li
                    key={log.id}
                    className="flex items-center gap-2 text-sm py-1"
                  >
                    <Activity
                      size={12}
                      strokeWidth={1.75}
                      className="text-[var(--muted)] shrink-0"
                    />
                    <span className="font-medium">
                      {log.actor?.name ?? log.actor?.email ?? "Système"}
                    </span>
                    <span className="text-[var(--muted)]">{log.action}</span>
                    {log.subject_name && (
                      <span className="font-mono text-xs truncate">
                        {log.subject_name}
                      </span>
                    )}
                    <time className="ml-auto text-xs text-[var(--muted)] tabular-nums shrink-0">
                      {formatRelative(log.created_at)}
                    </time>
                  </li>
                ))}
              </ul>
            )}
          </Block>
        </div>

        <aside className="space-y-4">
          {/* Détails */}
          <Block title="Détails">
            <dl className="text-sm space-y-2">
              <Row
                label="Créé le"
                value={new Date(project.created_at).toLocaleDateString(
                  "fr-FR",
                  { day: "numeric", month: "long", year: "numeric" },
                )}
              />
              <Row label="Slug" value={project.slug} mono />
              {project.creator && (
                <Row label="Créé par" value={project.creator.email} />
              )}
            </dl>
          </Block>

          {/* Charge par membre */}
          {stats.cards.by_assignee.length > 0 && (
            <Block title="Charge par assigné">
              <ul className="space-y-2">
                {stats.cards.by_assignee.map((a) => (
                  <li
                    key={a.user_id}
                    className="flex items-center gap-2.5 text-sm"
                  >
                    <Avatar user={a} size="sm" />
                    <span className="flex-1 truncate text-xs">
                      {a.name ?? a.email}
                    </span>
                    <span className="text-xs font-medium tabular-nums">
                      {a.count}
                    </span>
                  </li>
                ))}
              </ul>
            </Block>
          )}

          {/* Modules */}
          <Block title="Contenu">
            <ul className="space-y-1.5">
              <ContentRow
                label="Membres"
                value={stats.counts.members}
                icon={Users}
                href={`/projects/${id}/members`}
              />
              <ContentRow
                label="Documents"
                value={stats.counts.documents}
                icon={Paperclip}
                href={`/projects/${id}/documents`}
              />
              <ContentRow
                label="Pages de documentation"
                value={stats.counts.docs}
                icon={BookOpen}
                href={`/projects/${id}/docs`}
              />
              <ContentRow
                label="Décisions"
                value={stats.counts.decisions}
                icon={ScrollText}
                href={`/projects/${id}/adr`}
              />
              <ContentRow
                label="Secrets"
                value={stats.counts.vault_entries}
                icon={KeyRound}
                href={`/projects/${id}/vault`}
              />
            </ul>
          </Block>

          {/* Priorités */}
          {Object.keys(stats.cards.by_priority).length > 0 && (
            <Block title="Priorités (ouvertes)">
              <ul className="space-y-1.5">
                {Object.entries(stats.cards.by_priority).map(([p, n]) => (
                  <li
                    key={p}
                    className="flex items-center justify-between text-sm"
                  >
                    <span
                      className={clsx(
                        "text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5",
                        p === "urgent" &&
                          "bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]",
                        p === "high" &&
                          "bg-[var(--color-warning)]/10 text-[var(--color-warning)]",
                        p === "medium" &&
                          "bg-[var(--color-info)]/10 text-[var(--color-info)]",
                        p === "low" &&
                          "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]",
                      )}
                    >
                      {p}
                    </span>
                    <span className="font-medium tabular-nums">{n}</span>
                  </li>
                ))}
              </ul>
            </Block>
          )}
        </aside>
      </div>
    </section>
  );
}

// ── Sub-components ──

function Block({
  title,
  action,
  children,
}: {
  title: string;
  action?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5">
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-sm font-medium">{title}</h2>
        {action}
      </div>
      {children}
    </div>
  );
}

function Stat({
  label,
  value,
  icon: Icon,
  subtitle,
  tone = "neutral",
  href,
}: {
  label: string;
  value: number | string;
  icon: LucideIcon;
  subtitle?: string;
  tone?: "neutral" | "warning" | "danger" | "success";
  href?: string;
}) {
  const cls = clsx(
    "rounded-lg border bg-[var(--surface)] p-4 flex items-start gap-3 transition-colors",
    tone === "neutral" && "border-[var(--border)]",
    tone === "warning" &&
      "border-[var(--color-warning)]/40 bg-[var(--color-warning)]/5",
    tone === "danger" &&
      "border-[var(--color-danger)]/40 bg-[var(--color-danger)]/5",
    tone === "success" &&
      "border-[var(--color-success)]/40 bg-[var(--color-success)]/5",
    href && "hover:border-[var(--color-neutral-400)]",
  );
  const iconCls = clsx(
    "size-9 rounded-md flex items-center justify-center shrink-0",
    tone === "neutral" &&
      "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--foreground)]",
    tone === "warning" &&
      "bg-[var(--color-warning)]/15 text-[var(--color-warning)]",
    tone === "danger" && "bg-[var(--color-danger)]/15 text-[var(--color-danger)]",
    tone === "success" &&
      "bg-[var(--color-success)]/15 text-[var(--color-success)]",
  );
  const inner = (
    <>
      <span className={iconCls}>
        <Icon size={16} strokeWidth={1.75} />
      </span>
      <div className="min-w-0">
        <p className="text-2xl font-semibold tracking-tight tabular-nums truncate">
          {value}
        </p>
        <p className="text-xs text-[var(--muted)]">{label}</p>
        {subtitle && (
          <p className="text-[10px] text-[var(--muted)] mt-0.5">{subtitle}</p>
        )}
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

function ContentRow({
  label,
  value,
  icon: Icon,
  href,
}: {
  label: string;
  value: number;
  icon: LucideIcon;
  href: string;
}) {
  return (
    <li>
      <Link
        href={href}
        className="flex items-center justify-between gap-2 px-2 py-1.5 -mx-2 rounded text-sm hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50"
      >
        <span className="flex items-center gap-2 text-[var(--muted)]">
          <Icon size={13} strokeWidth={1.75} />
          {label}
        </span>
        <span className="font-medium tabular-nums">{value}</span>
      </Link>
    </li>
  );
}

function Row({
  label,
  value,
  mono,
}: {
  label: string;
  value: string;
  mono?: boolean;
}) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-[var(--muted)] text-xs">{label}</dt>
      <dd className={mono ? "font-mono text-xs" : ""}>{value}</dd>
    </div>
  );
}

// ── Utils ──

function formatDuration(seconds: number): string {
  if (!seconds) return "0h";
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h === 0) return `${m}min`;
  if (m === 0) return `${h}h`;
  return `${h}h${String(m).padStart(2, "0")}`;
}

function formatRelative(iso: string): string {
  const now = Date.now();
  const t = new Date(iso).getTime();
  const sec = Math.round((now - t) / 1000);
  if (sec < 60) return `${sec}s`;
  if (sec < 3600) return `${Math.round(sec / 60)}min`;
  if (sec < 86400) return `${Math.round(sec / 3600)}h`;
  if (sec < 30 * 86400) return `${Math.round(sec / 86400)}j`;
  return new Date(iso).toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "short",
  });
}

function formatDue(iso: string): string {
  const now = Date.now();
  const t = new Date(iso).getTime();
  const days = Math.floor((t - now) / (1000 * 60 * 60 * 24));
  if (days < 0) return `${Math.abs(days)}j de retard`;
  if (days === 0) return "Aujourd'hui";
  if (days === 1) return "Demain";
  if (days < 7) return `Dans ${days}j`;
  return new Date(iso).toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "short",
  });
}

