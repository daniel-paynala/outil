"use client";

import { useMemo, useState } from "react";
import { clsx } from "clsx";
import type { ProjectMember, RoadmapItem } from "@/lib/types";
import { HORIZON_LABEL } from "./horizon-meta";

type SortKey = "title" | "horizon" | "owner" | "target_date" | "effort" | "release";

const HORIZON_ORDER: Record<string, number> = { now: 1, next: 2, later: 3, done: 4 };
const EFFORT_ORDER: Record<string, number> = { S: 1, M: 2, L: 3, XL: 4 };

export default function ViewList({
  items,
  onOpenItem,
}: {
  items: RoadmapItem[];
  members: ProjectMember[];
  onOpenItem: (id: string) => void;
}) {
  const [sortKey, setSortKey] = useState<SortKey>("horizon");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("asc");
  const [filter, setFilter] = useState("");

  const filtered = useMemo(() => {
    if (!filter) return items;
    const q = filter.toLowerCase();
    return items.filter(
      (i) =>
        i.title.toLowerCase().includes(q) ||
        i.tags?.some((t) => t.toLowerCase().includes(q)) ||
        i.owner?.email.toLowerCase().includes(q) ||
        i.release?.name.toLowerCase().includes(q),
    );
  }, [items, filter]);

  const sorted = useMemo(() => {
    const arr = [...filtered];
    arr.sort((a, b) => {
      const av = key(a, sortKey);
      const bv = key(b, sortKey);
      if (av === bv) return 0;
      if (av == null) return 1;
      if (bv == null) return -1;
      return av < bv ? -1 : 1;
    });
    return sortDir === "asc" ? arr : arr.reverse();
  }, [filtered, sortKey, sortDir]);

  function toggleSort(k: SortKey) {
    if (k === sortKey) {
      setSortDir(sortDir === "asc" ? "desc" : "asc");
    } else {
      setSortKey(k);
      setSortDir("asc");
    }
  }

  return (
    <div className="space-y-3">
      <input
        value={filter}
        onChange={(e) => setFilter(e.target.value)}
        placeholder="Filtrer par titre, tag, owner, release…"
        className="w-full max-w-md rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
      />

      <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-[var(--border)] text-[var(--muted)]">
              <Th onClick={() => toggleSort("title")} active={sortKey === "title"} dir={sortDir}>
                Titre
              </Th>
              <Th onClick={() => toggleSort("horizon")} active={sortKey === "horizon"} dir={sortDir}>
                Horizon
              </Th>
              <Th onClick={() => toggleSort("release")} active={sortKey === "release"} dir={sortDir}>
                Release
              </Th>
              <Th onClick={() => toggleSort("owner")} active={sortKey === "owner"} dir={sortDir}>
                Owner
              </Th>
              <Th onClick={() => toggleSort("target_date")} active={sortKey === "target_date"} dir={sortDir}>
                Échéance
              </Th>
              <Th onClick={() => toggleSort("effort")} active={sortKey === "effort"} dir={sortDir}>
                Effort
              </Th>
              <th className="text-left font-medium text-xs uppercase tracking-wider px-3 py-2.5">Tags</th>
            </tr>
          </thead>
          <tbody>
            {sorted.length === 0 && (
              <tr>
                <td colSpan={7} className="text-center text-[var(--muted)] py-10 text-xs">
                  Aucun item.
                </td>
              </tr>
            )}
            {sorted.map((i) => (
              <tr
                key={i.id}
                onClick={() => onOpenItem(i.id)}
                className="border-b last:border-b-0 border-[var(--border)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50 cursor-pointer"
              >
                <td className="px-3 py-2.5 max-w-[280px]">
                  <p className="truncate font-medium">{i.title}</p>
                </td>
                <td className="px-3 py-2.5">
                  <span
                    className={clsx(
                      "text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5",
                      i.horizon === "now" && "bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]",
                      i.horizon === "next" && "bg-[var(--color-info)]/10 text-[var(--color-info)]",
                      i.horizon === "later" && "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]",
                      i.horizon === "done" && "bg-[var(--color-success)]/10 text-[var(--color-success)]",
                    )}
                  >
                    {HORIZON_LABEL[i.horizon]}
                  </span>
                </td>
                <td className="px-3 py-2.5">
                  {i.release ? (
                    <span
                      className="inline-flex items-center gap-1 text-xs rounded px-1.5 py-0.5"
                      style={{
                        background: `color-mix(in oklab, ${i.release.color} 15%, transparent)`,
                        color: i.release.color,
                      }}
                    >
                      {i.release.name}
                    </span>
                  ) : (
                    <span className="text-xs text-[var(--muted)]">—</span>
                  )}
                </td>
                <td className="px-3 py-2.5">
                  {i.owner ? (
                    <span className="text-xs">{i.owner.email}</span>
                  ) : (
                    <span className="text-xs text-[var(--muted)]">—</span>
                  )}
                </td>
                <td className="px-3 py-2.5">
                  {i.target_date ? (
                    <span className="text-xs">{i.target_date.slice(0, 10)}</span>
                  ) : (
                    <span className="text-xs text-[var(--muted)]">—</span>
                  )}
                </td>
                <td className="px-3 py-2.5">
                  {i.effort ? (
                    <span className="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)]">
                      {i.effort}
                    </span>
                  ) : (
                    <span className="text-xs text-[var(--muted)]">—</span>
                  )}
                </td>
                <td className="px-3 py-2.5">
                  {i.tags && i.tags.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                      {i.tags.map((t) => (
                        <span
                          key={t}
                          className="text-[10px] rounded bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)] px-1.5 py-0.5"
                        >
                          #{t}
                        </span>
                      ))}
                    </div>
                  ) : (
                    <span className="text-xs text-[var(--muted)]">—</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
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

function key(i: RoadmapItem, k: SortKey): string | number | null {
  switch (k) {
    case "title":
      return i.title.toLowerCase();
    case "horizon":
      return HORIZON_ORDER[i.horizon];
    case "owner":
      return i.owner?.email.toLowerCase() ?? null;
    case "target_date":
      return i.target_date ?? null;
    case "effort":
      return i.effort ? EFFORT_ORDER[i.effort] : null;
    case "release":
      return i.release?.name.toLowerCase() ?? null;
  }
}
