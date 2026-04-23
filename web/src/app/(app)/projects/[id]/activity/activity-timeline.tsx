"use client";

import { useMemo, useState } from "react";
import {
  ListTodo,
  Columns3,
  Tag,
  BookOpen,
  Paperclip,
  KeyRound,
  ScrollText,
  Timer,
  FolderKanban,
  Activity,
  ArrowRight,
  Plus,
  Pencil,
  Trash2,
  Eye,
  RotateCcw,
  type LucideIcon,
} from "lucide-react";
import { clsx } from "clsx";
import type { ActivityLog } from "@/lib/types";

const SUBJECT_ICONS: Record<string, LucideIcon> = {
  card: ListTodo,
  column: Columns3,
  label: Tag,
  doc_page: BookOpen,
  file: Paperclip,
  vault_entry: KeyRound,
  decision: ScrollText,
  time_entry: Timer,
  project: FolderKanban,
};

const SUBJECT_LABELS: Record<string, string> = {
  card: "Tâche",
  column: "Colonne",
  label: "Label",
  doc_page: "Documentation",
  file: "Fichier",
  vault_entry: "Coffre",
  decision: "Décision",
  time_entry: "Temps",
  project: "Projet",
};

export default function ActivityTimeline({
  initialLogs,
}: {
  initialLogs: ActivityLog[];
}) {
  const [typeFilter, setTypeFilter] = useState<string>("");
  const [actorFilter, setActorFilter] = useState<string>("");

  const types = useMemo(() => {
    const set = new Set<string>();
    initialLogs.forEach((l) => l.subject_type && set.add(l.subject_type));
    return Array.from(set).sort();
  }, [initialLogs]);

  const actors = useMemo(() => {
    const map = new Map<string, { id: string; email: string }>();
    initialLogs.forEach((l) => {
      if (l.actor && !map.has(l.actor.id)) {
        map.set(l.actor.id, { id: l.actor.id, email: l.actor.email });
      }
    });
    return Array.from(map.values()).sort((a, b) =>
      a.email.localeCompare(b.email),
    );
  }, [initialLogs]);

  const filtered = useMemo(() => {
    return initialLogs.filter((l) => {
      if (typeFilter && l.subject_type !== typeFilter) return false;
      if (actorFilter && l.actor_id !== actorFilter) return false;
      return true;
    });
  }, [initialLogs, typeFilter, actorFilter]);

  const groups = useMemo(() => groupByDay(filtered), [filtered]);

  return (
    <div className="space-y-6">
      <header className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
            Historique cross-modules
          </p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight flex items-center gap-2">
            <Activity size={22} strokeWidth={1.75} />
            Journal d'activité
          </h1>
          <p className="mt-2 text-sm text-[var(--muted)]">
            Toutes les interactions sur ce projet, chronologiquement.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <select
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
            className="rounded-md border border-[var(--border)] bg-transparent px-2 py-1.5 text-xs"
          >
            <option value="">Tous les modules</option>
            {types.map((t) => (
              <option key={t} value={t}>
                {SUBJECT_LABELS[t] ?? t}
              </option>
            ))}
          </select>
          <select
            value={actorFilter}
            onChange={(e) => setActorFilter(e.target.value)}
            className="rounded-md border border-[var(--border)] bg-transparent px-2 py-1.5 text-xs"
          >
            <option value="">Tous les utilisateurs</option>
            {actors.map((a) => (
              <option key={a.id} value={a.id}>
                {a.email}
              </option>
            ))}
          </select>
        </div>
      </header>

      {filtered.length === 0 ? (
        <div className="border border-dashed border-[var(--border)] rounded-lg py-16 text-center">
          <Activity
            size={22}
            strokeWidth={1.5}
            className="mx-auto text-[var(--muted)]"
          />
          <p className="mt-3 text-sm text-[var(--muted)]">
            Aucune activité pour l'instant.
          </p>
        </div>
      ) : (
        <div className="space-y-6">
          {groups.map(([day, dayLogs]) => (
            <section key={day}>
              <h2 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)] mb-3 sticky top-0 bg-[var(--background)] py-1">
                {day}
              </h2>
              <ul className="space-y-2">
                {dayLogs.map((log) => (
                  <LogRow key={log.id} log={log} />
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}

function LogRow({ log }: { log: ActivityLog }) {
  const subjectType = log.subject_type ?? "";
  const SubjectIcon = SUBJECT_ICONS[subjectType] ?? Activity;
  const verbIcon = verbIconFor(log.action);

  return (
    <li className="flex items-start gap-3 rounded-md border border-[var(--border)] bg-[var(--surface)] px-4 py-3">
      <span className="size-8 shrink-0 rounded-md bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] flex items-center justify-center">
        <SubjectIcon size={14} strokeWidth={1.75} />
      </span>
      <div className="flex-1 min-w-0">
        <p className="text-sm flex items-center gap-1.5 flex-wrap">
          <span className="font-medium">
            {log.actor?.email ?? "Système"}
          </span>
          <span className="text-[var(--muted)]">
            {verbIcon && (
              <span className="inline-flex items-center gap-1">
                <verbIcon.Icon size={11} />
                {verbIcon.label}
              </span>
            )}
          </span>
          {log.subject_name && (
            <span className="inline-flex items-center gap-1.5 text-[var(--foreground)]">
              <span className="text-[10px] uppercase tracking-wider rounded bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] px-1.5 py-0.5 text-[var(--muted)]">
                {SUBJECT_LABELS[subjectType] ?? subjectType}
              </span>
              <span className="font-medium">{log.subject_name}</span>
            </span>
          )}
        </p>
        {log.metadata && renderMetadata(log.action, log.metadata)}
      </div>
      <span className="text-xs text-[var(--muted)] shrink-0 tabular-nums">
        {new Date(log.created_at).toLocaleTimeString("fr-FR", {
          hour: "2-digit",
          minute: "2-digit",
        })}
      </span>
    </li>
  );
}

function verbIconFor(action: string): { Icon: LucideIcon; label: string } | null {
  const verb = action.split(".")[1] ?? "";
  switch (verb) {
    case "created":
      return { Icon: Plus, label: "a créé" };
    case "updated":
    case "status_changed":
      return { Icon: Pencil, label: "a modifié" };
    case "deleted":
      return { Icon: Trash2, label: "a supprimé" };
    case "moved":
      return { Icon: ArrowRight, label: "a déplacé" };
    case "revealed":
      return { Icon: Eye, label: "a révélé" };
    case "restored":
      return { Icon: RotateCcw, label: "a restauré" };
    case "uploaded":
      return { Icon: Plus, label: "a uploadé" };
    case "logged":
      return { Icon: Timer, label: "a loggué du temps sur" };
    default:
      return null;
  }
}

function renderMetadata(
  action: string,
  metadata: Record<string, unknown>,
): React.ReactNode {
  if (action === "card.moved" && metadata.from && metadata.to) {
    return (
      <p className="mt-1 text-xs text-[var(--muted)] inline-flex items-center gap-1.5">
        <span>{String(metadata.from)}</span>
        <ArrowRight size={10} />
        <span className="text-[var(--foreground)]">{String(metadata.to)}</span>
      </p>
    );
  }
  if (action === "decision.status_changed" && metadata.from && metadata.to) {
    return (
      <p className="mt-1 text-xs text-[var(--muted)] inline-flex items-center gap-1.5">
        <span>{String(metadata.from)}</span>
        <ArrowRight size={10} />
        <span className="text-[var(--foreground)]">{String(metadata.to)}</span>
      </p>
    );
  }
  if (action === "card.updated" && Array.isArray(metadata.fields)) {
    return (
      <p className="mt-1 text-xs text-[var(--muted)]">
        Champs : {(metadata.fields as string[]).join(", ")}
      </p>
    );
  }
  if (action === "time.logged" && typeof metadata.seconds === "number") {
    return (
      <p className="mt-1 text-xs text-[var(--muted)]">
        Durée : {formatHours(metadata.seconds)}
      </p>
    );
  }
  return null;
}

function groupByDay(logs: ActivityLog[]): [string, ActivityLog[]][] {
  const groups = new Map<string, ActivityLog[]>();
  for (const l of logs) {
    const key = dayKey(new Date(l.created_at));
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key)!.push(l);
  }
  return Array.from(groups.entries());
}

function dayKey(d: Date): string {
  const today = new Date();
  const isToday =
    d.getDate() === today.getDate() &&
    d.getMonth() === today.getMonth() &&
    d.getFullYear() === today.getFullYear();
  const y = new Date();
  y.setDate(today.getDate() - 1);
  const isYesterday =
    d.getDate() === y.getDate() &&
    d.getMonth() === y.getMonth() &&
    d.getFullYear() === y.getFullYear();
  if (isToday) return "Aujourd'hui";
  if (isYesterday) return "Hier";
  return d.toLocaleDateString("fr-FR", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

function formatHours(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h === 0) return `${m}min`;
  if (m === 0) return `${h}h`;
  return `${h}h${String(m).padStart(2, "0")}`;
}
