"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Loader2, GanttChartSquare, AlertTriangle } from "lucide-react";
import { clsx } from "clsx";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import Avatar from "@/core/ui/avatar";

type Card = {
  id: string;
  title: string;
  priority: "low" | "medium" | "high" | "urgent" | null;
  due_date: string | null;
  completed_at: string | null;
  created_at: string;
  assigned_to: string | null;
  column_id: string | null;
  assignee: { id: string; email: string; name: string | null; avatar_url?: string | null } | null;
  column: { id: string; name: string; is_done: boolean } | null;
};

type Granularity = "day" | "week" | "month";

const PRIORITY_COLORS: Record<string, string> = {
  urgent: "var(--color-brand-red)",
  high: "var(--color-warning)",
  medium: "var(--color-info)",
  low: "var(--color-neutral-400)",
};

const ROW_HEIGHT = 36;
const HEADER_HEIGHT = 40;
const SIDEBAR_WIDTH = 220;

export default function TimelineClient({ projectId }: { projectId: string }) {
  const router = useRouter();
  const toast = useToast();
  const [cards, setCards] = useState<Card[]>([]);
  const [loading, setLoading] = useState(true);
  const [granularity, setGranularity] = useState<Granularity>("day");
  const [filter, setFilter] = useState<"all" | "open" | "done">("all");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      try {
        const res = await apiFetch(`/api/projects/${projectId}/cards-timeline`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const body: Card[] = await res.json();
        if (!cancelled) setCards(body);
      } catch (e) {
        if (!cancelled) {
          toast.error(
            "Chargement impossible",
            e instanceof Error ? e.message : "Erreur",
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
  }, [projectId, toast]);

  const filtered = useMemo(() => {
    if (filter === "open") return cards.filter((c) => !c.completed_at);
    if (filter === "done") return cards.filter((c) => c.completed_at);
    return cards;
  }, [cards, filter]);

  // Groupe par assignee
  const groups = useMemo(() => {
    const map = new Map<
      string,
      { key: string; label: string; user: Card["assignee"] | null; cards: Card[] }
    >();
    for (const c of filtered) {
      const key = c.assignee?.id ?? "_unassigned";
      const label =
        c.assignee?.name ??
        c.assignee?.email ??
        "Non assignées";
      if (!map.has(key))
        map.set(key, { key, label, user: c.assignee ?? null, cards: [] });
      map.get(key)!.cards.push(c);
    }
    // Tri : non-assignées en dernier
    return Array.from(map.values()).sort((a, b) => {
      if (a.key === "_unassigned") return 1;
      if (b.key === "_unassigned") return -1;
      return a.label.localeCompare(b.label);
    });
  }, [filtered]);

  // Calcule la fenêtre temporelle
  const range = useMemo(() => {
    if (filtered.length === 0) {
      const now = new Date();
      return {
        start: addDays(now, -7),
        end: addDays(now, 7),
      };
    }
    let min = Date.now();
    let max = Date.now();
    for (const c of filtered) {
      const start = new Date(c.created_at).getTime();
      const end =
        new Date(c.completed_at ?? c.due_date ?? Date.now()).getTime();
      if (start < min) min = start;
      if (end > max) max = end;
    }
    // padding
    const padDays = granularity === "day" ? 1 : granularity === "week" ? 7 : 14;
    return {
      start: addDays(new Date(min), -padDays),
      end: addDays(new Date(Math.max(max, Date.now())), padDays),
    };
  }, [filtered, granularity]);

  const totalDays = Math.max(
    1,
    Math.ceil((range.end.getTime() - range.start.getTime()) / (24 * 3600 * 1000)),
  );

  // Largeur d'un jour en px (selon granularité)
  const dayWidth =
    granularity === "day" ? 36 : granularity === "week" ? 14 : 5;
  const gridWidth = totalDays * dayWidth;

  // Génère les têtes de colonne (jours / semaines / mois)
  const ticks = useMemo(() => {
    const out: { left: number; label: string; major: boolean }[] = [];
    const cursor = new Date(range.start);
    cursor.setHours(0, 0, 0, 0);
    while (cursor <= range.end) {
      const left = daysBetween(range.start, cursor) * dayWidth;
      let label = "";
      let major = false;
      if (granularity === "day") {
        label = cursor.toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
        major = cursor.getDay() === 1;
      } else if (granularity === "week") {
        if (cursor.getDay() === 1) {
          label = cursor.toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
          major = true;
        }
      } else {
        if (cursor.getDate() === 1) {
          label = cursor.toLocaleDateString("fr-FR", { month: "short", year: "2-digit" });
          major = true;
        }
      }
      out.push({ left, label, major });
      cursor.setDate(cursor.getDate() + 1);
    }
    return out;
  }, [range, granularity, dayWidth]);

  const todayLeft = daysBetween(range.start, new Date()) * dayWidth;

  return (
    <div className="space-y-4">
      <header className="flex items-center justify-between">
        <div>
          <h2 className="text-sm font-medium flex items-center gap-2">
            <GanttChartSquare size={14} strokeWidth={1.75} />
            Timeline des tâches
          </h2>
          <p className="text-xs text-[var(--muted)] mt-0.5">
            {filtered.length} tâche{filtered.length > 1 ? "s" : ""} ·{" "}
            {groups.length} ligne{groups.length > 1 ? "s" : ""}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <select
            value={filter}
            onChange={(e) =>
              setFilter(e.target.value as "all" | "open" | "done")
            }
            className="bg-transparent rounded border border-[var(--border)] px-2 py-1 text-xs focus:outline-none focus:border-[var(--color-neutral-400)]"
          >
            <option value="all">Toutes</option>
            <option value="open">Ouvertes</option>
            <option value="done">Terminées</option>
          </select>
          <div className="flex items-center rounded border border-[var(--border)] overflow-hidden">
            {(["day", "week", "month"] as Granularity[]).map((g) => (
              <button
                key={g}
                onClick={() => setGranularity(g)}
                className={clsx(
                  "px-2.5 py-1 text-xs transition-colors",
                  granularity === g
                    ? "bg-[var(--color-neutral-900)] text-[var(--color-neutral-0)] dark:bg-[var(--color-neutral-0)] dark:text-[var(--color-neutral-900)]"
                    : "text-[var(--muted)] hover:text-[var(--foreground)]",
                )}
              >
                {g === "day" ? "Jour" : g === "week" ? "Semaine" : "Mois"}
              </button>
            ))}
          </div>
        </div>
      </header>

      {loading ? (
        <div className="flex items-center justify-center py-16 text-[var(--muted)]">
          <Loader2 className="w-5 h-5 animate-spin" />
        </div>
      ) : groups.length === 0 ? (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] py-12 text-center">
          <p className="text-sm text-[var(--muted)]">
            Aucune tâche à afficher pour ce filtre.
          </p>
        </div>
      ) : (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] overflow-hidden">
          <div className="flex">
            {/* Sidebar : noms des assignés */}
            <div
              className="shrink-0 border-r border-[var(--border)] bg-[var(--color-neutral-50)] dark:bg-[var(--color-neutral-900)]/50"
              style={{ width: SIDEBAR_WIDTH }}
            >
              <div
                className="border-b border-[var(--border)] px-3 flex items-center text-[11px] uppercase tracking-wider text-[var(--muted)] font-medium"
                style={{ height: HEADER_HEIGHT }}
              >
                Responsable
              </div>
              {groups.map((g) => (
                <div
                  key={g.key}
                  className="border-b border-[var(--border)] px-3 flex items-center"
                  style={{ height: ROW_HEIGHT * Math.max(1, g.cards.length) }}
                >
                  <div className="flex items-center gap-2 min-w-0">
                    {g.key === "_unassigned" ? (
                      <div className="size-6 rounded-full flex items-center justify-center text-[10px] font-medium shrink-0 bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]">
                        ?
                      </div>
                    ) : (
                      <Avatar user={g.user} size="sm" />
                    )}
                    <div className="min-w-0">
                      <p className="text-xs font-medium truncate">{g.label}</p>
                      <p className="text-[10px] text-[var(--muted)]">
                        {g.cards.length} tâche{g.cards.length > 1 ? "s" : ""}
                      </p>
                    </div>
                  </div>
                </div>
              ))}
            </div>

            {/* Zone scrollable : grille temps + barres */}
            <div className="flex-1 overflow-x-auto">
              <div
                className="relative"
                style={{ width: gridWidth, minWidth: "100%" }}
              >
                {/* Header avec ticks */}
                <div
                  className="border-b border-[var(--border)] bg-[var(--color-neutral-50)] dark:bg-[var(--color-neutral-900)]/50 sticky top-0 z-10"
                  style={{ height: HEADER_HEIGHT }}
                >
                  {ticks.map((t, i) =>
                    t.label ? (
                      <span
                        key={i}
                        className={clsx(
                          "absolute top-2 text-[10px] tabular-nums",
                          t.major
                            ? "text-[var(--foreground)] font-medium"
                            : "text-[var(--muted)]",
                        )}
                        style={{ left: t.left }}
                      >
                        {t.label}
                      </span>
                    ) : null,
                  )}
                </div>

                {/* Lignes verticales (week/month boundaries) */}
                {ticks
                  .filter((t) => t.major)
                  .map((t, i) => (
                    <div
                      key={`grid-${i}`}
                      className="absolute top-0 bottom-0 border-l border-[var(--border)]/40"
                      style={{ left: t.left, top: HEADER_HEIGHT }}
                    />
                  ))}

                {/* Ligne "aujourd'hui" */}
                {todayLeft >= 0 && todayLeft <= gridWidth && (
                  <div
                    className="absolute top-0 bottom-0 w-px bg-[var(--color-brand-red)]/60 z-20 pointer-events-none"
                    style={{ left: todayLeft }}
                    title="Aujourd'hui"
                  >
                    <div className="absolute -top-1 -left-1 size-2 rounded-full bg-[var(--color-brand-red)]" />
                  </div>
                )}

                {/* Lignes (1 par groupe, contient n cartes empilées) */}
                {groups.map((g, gi) => {
                  const groupTop =
                    HEADER_HEIGHT +
                    groups.slice(0, gi).reduce(
                      (acc, gg) =>
                        acc + ROW_HEIGHT * Math.max(1, gg.cards.length),
                      0,
                    );
                  return (
                    <div key={g.key}>
                      {g.cards.map((c, ci) => {
                        const start = new Date(c.created_at);
                        const end = new Date(
                          c.completed_at ?? c.due_date ?? Date.now(),
                        );
                        const left =
                          daysBetween(range.start, start) * dayWidth;
                        const width = Math.max(
                          dayWidth * 0.5,
                          daysBetween(start, end) * dayWidth,
                        );
                        const top = groupTop + ci * ROW_HEIGHT;
                        const isOverdue =
                          c.due_date &&
                          !c.completed_at &&
                          new Date(c.due_date) < new Date();
                        const color = c.priority
                          ? PRIORITY_COLORS[c.priority]
                          : "var(--color-neutral-400)";

                        return (
                          <div
                            key={c.id}
                            className="absolute group"
                            style={{
                              left,
                              top: top + 6,
                              width,
                              height: ROW_HEIGHT - 12,
                            }}
                          >
                            <button
                              type="button"
                              onClick={() =>
                                router.push(
                                  `/projects/${projectId}/tasks?card=${c.id}`,
                                )
                              }
                              className={clsx(
                                "w-full h-full rounded text-left px-2 flex items-center gap-1.5 transition-all overflow-hidden",
                                "hover:ring-2 hover:ring-offset-1 hover:ring-offset-[var(--surface)]",
                                c.completed_at && "opacity-70",
                              )}
                              style={{
                                background: `color-mix(in srgb, ${color} 18%, transparent)`,
                                borderLeft: `3px solid ${color}`,
                              }}
                              title={`${c.title}\nCréée le ${formatDate(start)}\n${c.completed_at ? `Terminée le ${formatDate(end)}` : c.due_date ? `Échéance le ${formatDate(new Date(c.due_date))}` : ""}`}
                            >
                              {isOverdue && (
                                <AlertTriangle
                                  size={10}
                                  className="text-[var(--color-danger)] shrink-0"
                                />
                              )}
                              <span className="text-[11px] font-medium truncate">
                                {c.title}
                              </span>
                              {c.column?.is_done && (
                                <span className="text-[9px] uppercase tracking-wider text-[var(--muted)] shrink-0 ml-auto">
                                  ✓
                                </span>
                              )}
                            </button>
                          </div>
                        );
                      })}
                      {/* Row separator */}
                      {gi < groups.length - 1 && (
                        <div
                          className="absolute left-0 right-0 border-b border-[var(--border)]"
                          style={{
                            top:
                              groupTop +
                              ROW_HEIGHT * Math.max(1, g.cards.length),
                            width: gridWidth,
                          }}
                        />
                      )}
                    </div>
                  );
                })}

                {/* Hauteur totale (force le scroll vertical au container) */}
                <div
                  style={{
                    height:
                      HEADER_HEIGHT +
                      groups.reduce(
                        (acc, g) =>
                          acc + ROW_HEIGHT * Math.max(1, g.cards.length),
                        0,
                      ),
                  }}
                />
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Légende */}
      <div className="flex items-center gap-4 flex-wrap text-xs text-[var(--muted)]">
        <span className="font-medium">Priorité :</span>
        {Object.entries(PRIORITY_COLORS).map(([p, c]) => (
          <span key={p} className="inline-flex items-center gap-1.5">
            <span
              className="size-3 rounded"
              style={{ background: c, opacity: 0.8 }}
            />
            {p}
          </span>
        ))}
        <span className="ml-4 inline-flex items-center gap-1.5">
          <span className="size-2 rounded-full bg-[var(--color-brand-red)]" />
          Aujourd&apos;hui
        </span>
      </div>
    </div>
  );
}

// ── Utils ──

function addDays(d: Date, n: number): Date {
  const r = new Date(d);
  r.setDate(r.getDate() + n);
  return r;
}

function daysBetween(a: Date, b: Date): number {
  return (b.getTime() - a.getTime()) / (24 * 3600 * 1000);
}

function formatDate(d: Date): string {
  return d.toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}
