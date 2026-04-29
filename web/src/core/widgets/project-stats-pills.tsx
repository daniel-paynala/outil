import { clsx } from "clsx";
import type { ProjectUserStats } from "@/lib/types";

/**
 * Affiche les compteurs de tâches assignées à l'utilisateur courant pour un projet.
 * Statuts dérivés de la position des colonnes : 1ère = à faire, dernière = terminé, milieu = en cours.
 */
export default function ProjectStatsPills({
  stats,
  className,
  size = "md",
}: {
  stats?: ProjectUserStats;
  className?: string;
  size?: "sm" | "md";
}) {
  if (!stats) return null;

  const total = stats.todo + stats.doing + stats.done;
  if (total === 0) {
    return (
      <span
        className={clsx(
          "text-[var(--muted)]",
          size === "sm" ? "text-[10px]" : "text-xs",
          className,
        )}
      >
        Aucune tâche
      </span>
    );
  }

  return (
    <div className={clsx("inline-flex items-center gap-1.5", className)}>
      {stats.todo > 0 && (
        <Pill
          count={stats.todo}
          label="à faire"
          tone="todo"
          size={size}
        />
      )}
      {stats.doing > 0 && (
        <Pill
          count={stats.doing}
          label="en cours"
          tone="doing"
          size={size}
        />
      )}
      {stats.done > 0 && (
        <Pill
          count={stats.done}
          label="terminé"
          tone="done"
          size={size}
        />
      )}
    </div>
  );
}

function Pill({
  count,
  label,
  tone,
  size,
}: {
  count: number;
  label: string;
  tone: "todo" | "doing" | "done";
  size: "sm" | "md";
}) {
  return (
    <span
      title={`${count} ${label} ${count > 1 ? "(tâches assignées)" : "(tâche assignée)"}`}
      className={clsx(
        "inline-flex items-center gap-1 rounded-full font-medium tabular-nums",
        size === "sm"
          ? "px-1.5 py-0.5 text-[10px]"
          : "px-2 py-0.5 text-xs",
        tone === "todo" &&
          "bg-[var(--color-warning)]/10 text-[var(--color-warning)]",
        tone === "doing" &&
          "bg-[var(--color-info)]/10 text-[var(--color-info)]",
        tone === "done" &&
          "bg-[var(--color-success)]/10 text-[var(--color-success)]",
      )}
    >
      <span className="font-semibold">{count}</span>
      <span className="opacity-80">{label}</span>
    </span>
  );
}
