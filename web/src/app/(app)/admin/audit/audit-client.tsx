"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  UserPlus,
  UserMinus,
  UserCog,
  Mail,
  KeyRound,
  Users,
  Activity,
  Loader2,
  ScrollText,
} from "lucide-react";
import { clsx } from "clsx";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import Avatar from "@/core/ui/avatar";

type Actor = {
  id: string;
  email: string;
  name: string | null;
  avatar_url?: string | null;
};

type Project = {
  id: string;
  name: string;
  slug: string;
  color: string;
};

type LogEntry = {
  id: string;
  project_id: string | null;
  actor_id: string | null;
  action: string;
  subject_type: string | null;
  subject_id: string | null;
  subject_name: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  actor: Actor | null;
  project: Project | null;
};

type Paginated = {
  data: LogEntry[];
  current_page: number;
  last_page: number;
  total: number;
};

type Scope = "global" | "projects" | "all";

const SCOPE_LABELS: Record<Scope, string> = {
  global: "Actions admin",
  projects: "Activité projets",
  all: "Tout",
};

export default function AuditClient() {
  const toast = useToast();
  const [scope, setScope] = useState<Scope>("global");
  const [logs, setLogs] = useState<LogEntry[]>([]);
  const [meta, setMeta] = useState({
    current_page: 1,
    last_page: 1,
    total: 0,
  });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      try {
        const res = await apiFetch(
          `/api/admin/audit-logs?scope=${scope}&page=${page}&per_page=50`,
        );
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}`);
        }
        const body: Paginated = await res.json();
        if (cancelled) return;
        setLogs(body.data);
        setMeta({
          current_page: body.current_page,
          last_page: body.last_page,
          total: body.total,
        });
      } catch (e) {
        if (!cancelled) {
          toast.error(
            "Chargement impossible",
            e instanceof Error ? e.message : "Erreur inconnue",
          );
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [scope, page, toast]);

  return (
    <div className="space-y-6">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Administration
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight flex items-center gap-2">
          <ScrollText size={20} strokeWidth={1.75} />
          Journal d&apos;audit
        </h1>
        <p className="mt-1 text-sm text-[var(--muted)]">
          Toutes les actions sensibles tracées sur la plateforme.
        </p>
      </header>

      <div className="flex items-center gap-1 border-b border-[var(--border)]">
        {(Object.keys(SCOPE_LABELS) as Scope[]).map((s) => (
          <button
            key={s}
            onClick={() => {
              setScope(s);
              setPage(1);
            }}
            className={clsx(
              "px-3 py-2 text-sm border-b-2 -mb-px transition-colors",
              scope === s
                ? "border-[var(--color-brand-red)] text-[var(--foreground)] font-medium"
                : "border-transparent text-[var(--muted)] hover:text-[var(--foreground)]",
            )}
          >
            {SCOPE_LABELS[s]}
          </button>
        ))}
        <span className="ml-auto text-xs text-[var(--muted)]">
          {meta.total} entrée{meta.total > 1 ? "s" : ""}
        </span>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-16 text-[var(--muted)]">
          <Loader2 className="w-5 h-5 animate-spin" />
        </div>
      ) : logs.length === 0 ? (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] py-12 text-center">
          <p className="text-sm text-[var(--muted)]">Aucune entrée.</p>
        </div>
      ) : (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] overflow-hidden">
          <ul className="divide-y divide-[var(--border)]">
            {logs.map((log) => (
              <LogRow key={log.id} log={log} />
            ))}
          </ul>
        </div>
      )}

      {meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <button
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            disabled={page <= 1 || loading}
            className="px-3 py-1.5 rounded-md border border-[var(--border)] disabled:opacity-30 disabled:cursor-not-allowed hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
          >
            ← Précédent
          </button>
          <span className="text-xs text-[var(--muted)]">
            Page {meta.current_page} / {meta.last_page}
          </span>
          <button
            onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
            disabled={page >= meta.last_page || loading}
            className="px-3 py-1.5 rounded-md border border-[var(--border)] disabled:opacity-30 disabled:cursor-not-allowed hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
          >
            Suivant →
          </button>
        </div>
      )}
    </div>
  );
}

function LogRow({ log }: { log: LogEntry }) {
  const { Icon, label, color } = describeAction(log.action);

  return (
    <li className="flex items-start gap-3 px-4 py-3 hover:bg-[var(--color-neutral-100)]/40 dark:hover:bg-[var(--color-neutral-800)]/30">
      <div className="relative shrink-0 mt-0.5">
        <Avatar user={log.actor} size="md" />
        <span
          className="absolute -bottom-1 -right-1 size-4 rounded-full flex items-center justify-center ring-2 ring-[var(--surface)]"
          style={{
            background: `color-mix(in srgb, ${color} 18%, var(--surface))`,
            color,
          }}
          title={label}
        >
          <Icon size={10} strokeWidth={2} />
        </span>
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-baseline gap-2 flex-wrap">
          <span className="text-sm font-medium">
            {log.actor?.name ?? log.actor?.email ?? "Système"}
          </span>
          <span className="text-sm text-[var(--muted)]">{label}</span>
          {log.subject_name && (
            <span className="text-sm font-mono text-[var(--foreground)]">
              {log.subject_name}
            </span>
          )}
          {log.project && (
            <Link
              href={`/projects/${log.project.id}`}
              className="text-xs inline-flex items-center gap-1 text-[var(--muted)] hover:text-[var(--foreground)]"
            >
              <span
                className="size-2 rounded-full"
                style={{ background: log.project.color }}
              />
              {log.project.name}
            </Link>
          )}
        </div>
        {log.metadata && Object.keys(log.metadata).length > 0 && (
          <p className="mt-0.5 text-xs text-[var(--muted)] font-mono truncate">
            {formatMetadata(log.metadata)}
          </p>
        )}
      </div>
      <time
        className="text-xs text-[var(--muted)] tabular-nums shrink-0"
        title={new Date(log.created_at).toLocaleString("fr-FR")}
      >
        {formatRelative(log.created_at)}
      </time>
    </li>
  );
}

function describeAction(action: string): {
  Icon: typeof Activity;
  label: string;
  color: string;
} {
  const map: Record<string, { Icon: typeof Activity; label: string; color: string }> = {
    "admin.user.invited": {
      Icon: UserPlus,
      label: "a invité",
      color: "var(--color-success)",
    },
    "admin.user.invitation_resent": {
      Icon: Mail,
      label: "a renvoyé l'invitation à",
      color: "var(--color-info)",
    },
    "admin.user.updated": {
      Icon: UserCog,
      label: "a modifié",
      color: "var(--color-warning)",
    },
    "admin.user.deleted": {
      Icon: UserMinus,
      label: "a supprimé",
      color: "var(--color-danger)",
    },
    "user.account_activated": {
      Icon: KeyRound,
      label: "a activé son compte",
      color: "var(--color-success)",
    },
    "project.member.added": {
      Icon: UserPlus,
      label: "a ajouté",
      color: "var(--color-success)",
    },
    "project.member.removed": {
      Icon: UserMinus,
      label: "a retiré",
      color: "var(--color-danger)",
    },
    "project.member.role_changed": {
      Icon: Users,
      label: "a changé le rôle de",
      color: "var(--color-warning)",
    },
  };
  return (
    map[action] ?? {
      Icon: Activity,
      label: action,
      color: "var(--color-neutral-500)",
    }
  );
}

function formatMetadata(meta: Record<string, unknown>): string {
  return Object.entries(meta)
    .map(([k, v]) => {
      if (v && typeof v === "object") return `${k}: ${JSON.stringify(v)}`;
      return `${k}: ${String(v)}`;
    })
    .join(" · ");
}

function formatRelative(iso: string): string {
  const now = Date.now();
  const t = new Date(iso).getTime();
  const diffSec = Math.round((now - t) / 1000);
  if (diffSec < 60) return `${diffSec}s`;
  if (diffSec < 3600) return `${Math.round(diffSec / 60)}min`;
  if (diffSec < 86400) return `${Math.round(diffSec / 3600)}h`;
  if (diffSec < 30 * 86400) return `${Math.round(diffSec / 86400)}j`;
  return new Date(iso).toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "short",
  });
}
