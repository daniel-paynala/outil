"use client";

import { LayoutGrid, List, Link2, User, X } from "lucide-react";
import { clsx } from "clsx";
import type { Label, ProjectMember } from "@/lib/types";

export type BoardFilters = {
  assigneeId: string | null;
  labelId: string | null;
  priority: string | null;
  blockedOnly: boolean;
  mineOnly: boolean;
};

export const EMPTY_FILTERS: BoardFilters = {
  assigneeId: null,
  labelId: null,
  priority: null,
  blockedOnly: false,
  mineOnly: false,
};

export type ViewMode = "board" | "list";

export default function FiltersBar({
  filters,
  setFilters,
  view,
  setView,
  members,
  labels,
  cardCount,
  totalCount,
}: {
  filters: BoardFilters;
  setFilters: (f: BoardFilters) => void;
  view: ViewMode;
  setView: (v: ViewMode) => void;
  members: ProjectMember[];
  labels: Label[];
  cardCount: number;
  totalCount: number;
}) {
  const hasFilters =
    filters.assigneeId ||
    filters.labelId ||
    filters.priority ||
    filters.blockedOnly ||
    filters.mineOnly;

  return (
    <div className="flex flex-wrap items-center gap-2 pb-4">
      <button
        onClick={() => setFilters({ ...filters, mineOnly: !filters.mineOnly })}
        className={clsx(
          "inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium border transition-colors",
          filters.mineOnly
            ? "bg-[var(--color-brand-red)] border-[var(--color-brand-red)] text-white"
            : "border-[var(--border)] hover:border-[var(--color-neutral-400)]",
        )}
      >
        <User size={12} strokeWidth={2} />
        Mes tâches
      </button>

      <button
        onClick={() =>
          setFilters({ ...filters, blockedOnly: !filters.blockedOnly })
        }
        className={clsx(
          "inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium border transition-colors",
          filters.blockedOnly
            ? "bg-[var(--color-warning)]/15 border-[var(--color-warning)] text-[var(--color-warning)]"
            : "border-[var(--border)] hover:border-[var(--color-neutral-400)]",
        )}
      >
        <Link2 size={12} strokeWidth={2} />
        Bloquées
      </button>

      <select
        value={filters.assigneeId ?? ""}
        onChange={(e) =>
          setFilters({ ...filters, assigneeId: e.target.value || null })
        }
        className="rounded-md border border-[var(--border)] bg-transparent px-2 py-1.5 text-xs"
      >
        <option value="">Tous les assignés</option>
        {members.map((m) => (
          <option key={m.id} value={m.id}>
            {m.email}
          </option>
        ))}
      </select>

      <select
        value={filters.labelId ?? ""}
        onChange={(e) =>
          setFilters({ ...filters, labelId: e.target.value || null })
        }
        className="rounded-md border border-[var(--border)] bg-transparent px-2 py-1.5 text-xs"
      >
        <option value="">Tous les labels</option>
        {labels.map((l) => (
          <option key={l.id} value={l.id}>
            {l.name}
          </option>
        ))}
      </select>

      <select
        value={filters.priority ?? ""}
        onChange={(e) =>
          setFilters({ ...filters, priority: e.target.value || null })
        }
        className="rounded-md border border-[var(--border)] bg-transparent px-2 py-1.5 text-xs"
      >
        <option value="">Toutes priorités</option>
        <option value="urgent">Urgent</option>
        <option value="high">Haute</option>
        <option value="medium">Moyenne</option>
        <option value="low">Faible</option>
      </select>

      {hasFilters && (
        <button
          onClick={() => setFilters(EMPTY_FILTERS)}
          className="inline-flex items-center gap-1 text-xs text-[var(--muted)] hover:text-[var(--foreground)] px-2 py-1.5"
        >
          <X size={12} />
          Réinitialiser
        </button>
      )}

      <div className="ml-auto flex items-center gap-3">
        <span className="text-xs text-[var(--muted)]">
          {hasFilters ? `${cardCount}/${totalCount}` : totalCount} cartes
        </span>

        <div className="inline-flex rounded-md border border-[var(--border)] p-0.5">
          <button
            onClick={() => setView("board")}
            aria-pressed={view === "board"}
            title="Vue board"
            className={clsx(
              "inline-flex items-center gap-1 rounded px-2 py-1 text-xs",
              view === "board"
                ? "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)]"
                : "text-[var(--muted)] hover:text-[var(--foreground)]",
            )}
          >
            <LayoutGrid size={12} strokeWidth={2} />
          </button>
          <button
            onClick={() => setView("list")}
            aria-pressed={view === "list"}
            title="Vue liste"
            className={clsx(
              "inline-flex items-center gap-1 rounded px-2 py-1 text-xs",
              view === "list"
                ? "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)]"
                : "text-[var(--muted)] hover:text-[var(--foreground)]",
            )}
          >
            <List size={12} strokeWidth={2} />
          </button>
        </div>
      </div>
    </div>
  );
}

export function applyFilters(
  columns: import("@/lib/types").BoardColumn[],
  filters: BoardFilters,
  currentUserId: string,
): import("@/lib/types").BoardColumn[] {
  return columns.map((col) => ({
    ...col,
    cards: col.cards.filter((c) => {
      if (filters.mineOnly && c.assigned_to !== currentUserId) return false;
      if (filters.assigneeId && c.assigned_to !== filters.assigneeId)
        return false;
      if (filters.priority && c.priority !== filters.priority) return false;
      if (filters.blockedOnly && (c.dependencies_count ?? 0) === 0)
        return false;
      if (
        filters.labelId &&
        !(c.labels ?? []).some((l) => l.id === filters.labelId)
      )
        return false;
      return true;
    }),
  }));
}
