"use client";

import { useMemo, useState } from "react";
import { Calendar, Clock, Link2, GitBranch } from "lucide-react";
import { clsx } from "clsx";
import type { BoardColumn, CardSummary } from "@/lib/types";

type SortKey =
  | "title"
  | "column"
  | "assignee"
  | "due_date"
  | "priority"
  | "created_at";

const PRIORITY_RANK: Record<string, number> = {
  urgent: 4,
  high: 3,
  medium: 2,
  low: 1,
};

export default function ListView({
  columns,
  onOpenCard,
}: {
  columns: BoardColumn[];
  onOpenCard: (cardId: string) => void;
}) {
  const [sortKey, setSortKey] = useState<SortKey>("created_at");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("desc");

  const flatCards = useMemo(() => {
    const rows: Array<CardSummary & { columnName: string }> = [];
    for (const col of columns) {
      for (const card of col.cards) {
        rows.push({ ...card, columnName: col.name });
      }
    }
    return rows;
  }, [columns]);

  const sorted = useMemo(() => {
    const arr = [...flatCards];
    arr.sort((a, b) => {
      const av = keyOf(a, sortKey);
      const bv = keyOf(b, sortKey);
      if (av === bv) return 0;
      if (av == null) return 1;
      if (bv == null) return -1;
      return av < bv ? -1 : 1;
    });
    return sortDir === "asc" ? arr : arr.reverse();
  }, [flatCards, sortKey, sortDir]);

  function toggleSort(k: SortKey) {
    if (k === sortKey) {
      setSortDir(sortDir === "asc" ? "desc" : "asc");
    } else {
      setSortKey(k);
      setSortDir("asc");
    }
  }

  return (
    <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] overflow-hidden">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-[var(--border)] text-[var(--muted)]">
            <Th onClick={() => toggleSort("title")} active={sortKey === "title"} dir={sortDir}>Titre</Th>
            <Th onClick={() => toggleSort("column")} active={sortKey === "column"} dir={sortDir}>Statut</Th>
            <Th onClick={() => toggleSort("assignee")} active={sortKey === "assignee"} dir={sortDir}>Assigné</Th>
            <Th onClick={() => toggleSort("due_date")} active={sortKey === "due_date"} dir={sortDir}>Échéance</Th>
            <Th onClick={() => toggleSort("priority")} active={sortKey === "priority"} dir={sortDir}>Priorité</Th>
            <th className="text-left font-medium text-xs uppercase tracking-wider px-3 py-2.5">Labels</th>
            <th className="text-left font-medium text-xs uppercase tracking-wider px-3 py-2.5">Infos</th>
          </tr>
        </thead>
        <tbody>
          {sorted.length === 0 && (
            <tr>
              <td colSpan={7} className="text-center text-[var(--muted)] py-10 text-xs">
                Aucune carte.
              </td>
            </tr>
          )}
          {sorted.map((c) => (
            <tr
              key={c.id}
              onClick={() => onOpenCard(c.id)}
              className="border-b last:border-b-0 border-[var(--border)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50 cursor-pointer"
            >
              <td className="px-3 py-2.5 max-w-[320px]">
                <p className="truncate">{c.title}</p>
              </td>
              <td className="px-3 py-2.5 text-xs text-[var(--muted)]">
                {c.columnName}
              </td>
              <td className="px-3 py-2.5">
                {c.assignee ? (
                  <span className="inline-flex items-center gap-1.5 text-xs">
                    <span className="size-5 rounded-full bg-[var(--color-neutral-300)] dark:bg-[var(--color-neutral-600)] flex items-center justify-center text-[10px] font-medium">
                      {c.assignee.email.charAt(0).toUpperCase()}
                    </span>
                    <span className="truncate max-w-[140px]">{c.assignee.email}</span>
                  </span>
                ) : (
                  <span className="text-xs text-[var(--muted)]">—</span>
                )}
              </td>
              <td className="px-3 py-2.5">
                {c.due_date ? (
                  <span className="inline-flex items-center gap-1 text-xs">
                    <Calendar size={11} />
                    {c.due_date.slice(0, 10)}
                  </span>
                ) : (
                  <span className="text-xs text-[var(--muted)]">—</span>
                )}
              </td>
              <td className="px-3 py-2.5">
                {c.priority ? (
                  <span
                    className={clsx(
                      "inline-block text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5",
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
                ) : (
                  <span className="text-xs text-[var(--muted)]">—</span>
                )}
              </td>
              <td className="px-3 py-2.5">
                {c.labels && c.labels.length > 0 ? (
                  <div className="flex flex-wrap gap-1">
                    {c.labels.map((l) => (
                      <span
                        key={l.id}
                        className="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 text-white"
                        style={{ background: l.color }}
                      >
                        {l.name}
                      </span>
                    ))}
                  </div>
                ) : (
                  <span className="text-xs text-[var(--muted)]">—</span>
                )}
              </td>
              <td className="px-3 py-2.5">
                <div className="flex items-center gap-2 text-xs text-[var(--muted)]">
                  {c.estimate && (
                    <span className="inline-flex items-center gap-1">
                      <Clock size={11} />
                      {c.estimate}
                    </span>
                  )}
                  {(c.dependencies_count ?? 0) > 0 && (
                    <span className="inline-flex items-center gap-1 text-[var(--color-warning)]">
                      <Link2 size={11} />
                      {c.dependencies_count}
                    </span>
                  )}
                  {(c.children_count ?? 0) > 0 && (
                    <span className="inline-flex items-center gap-1">
                      <GitBranch size={11} />
                      {c.children_count}
                    </span>
                  )}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Th({
  onClick,
  active,
  dir,
  children,
}: {
  onClick: () => void;
  active: boolean;
  dir: "asc" | "desc";
  children: React.ReactNode;
}) {
  return (
    <th className="text-left font-medium text-xs uppercase tracking-wider px-3 py-2.5">
      <button
        onClick={onClick}
        className={clsx(
          "inline-flex items-center gap-1 hover:text-[var(--foreground)]",
          active && "text-[var(--foreground)]",
        )}
      >
        {children}
        {active && <span className="text-[10px]">{dir === "asc" ? "↑" : "↓"}</span>}
      </button>
    </th>
  );
}

function keyOf(
  c: CardSummary & { columnName: string },
  k: SortKey,
): string | number | null {
  switch (k) {
    case "title":
      return c.title.toLowerCase();
    case "column":
      return c.columnName.toLowerCase();
    case "assignee":
      return c.assignee?.email.toLowerCase() ?? null;
    case "due_date":
      return c.due_date ?? null;
    case "priority":
      return c.priority ? PRIORITY_RANK[c.priority] : null;
    case "created_at":
      return c.created_at;
  }
}
