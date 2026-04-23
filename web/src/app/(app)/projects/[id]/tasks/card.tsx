"use client";

import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { Trash2, Link2, GitBranch, Calendar, Clock } from "lucide-react";
import { clsx } from "clsx";
import type { CardSummary } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";

export default function BoardCard({
  card,
  onDelete,
  onOpen,
  overlay = false,
}: {
  card: CardSummary;
  onDelete?: () => void;
  onOpen?: () => void;
  overlay?: boolean;
}) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: card.id, disabled: overlay });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.4 : 1,
  };

  async function handleDelete(e: React.MouseEvent) {
    e.stopPropagation();
    if (!onDelete) return;
    if (!confirm("Supprimer cette carte ?")) return;
    const res = await apiFetch(`/api/cards/${card.id}`, { method: "DELETE" });
    if (res.ok) onDelete();
  }

  const dueState = dueDateState(card.due_date);

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...attributes}
      {...listeners}
      onClick={() => !overlay && onOpen?.()}
      className={clsx(
        "group rounded-md bg-[var(--background)] border border-[var(--border)] px-3 py-2.5 shadow-sm cursor-grab active:cursor-grabbing select-none",
        overlay && "shadow-lg border-[var(--color-neutral-400)] rotate-1",
      )}
    >
      {card.labels && card.labels.length > 0 && (
        <div className="flex flex-wrap gap-1 mb-2">
          {card.labels.map((l) => (
            <span
              key={l.id}
              className="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 text-white"
              style={{ background: l.color }}
              title={l.name}
            >
              {l.name}
            </span>
          ))}
        </div>
      )}

      <div className="flex items-start gap-2">
        <div className="flex-1 min-w-0">
          <p className="text-sm leading-snug">{card.title}</p>

          <div className="flex items-center gap-2 mt-2 flex-wrap">
            {card.priority && (
              <span
                className={clsx(
                  "inline-flex items-center text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded",
                  card.priority === "urgent" &&
                    "bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]",
                  card.priority === "high" &&
                    "bg-[var(--color-warning)]/10 text-[var(--color-warning)]",
                  card.priority === "medium" &&
                    "bg-[var(--color-info)]/10 text-[var(--color-info)]",
                  card.priority === "low" &&
                    "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]",
                )}
              >
                {card.priority}
              </span>
            )}

            {card.due_date && (
              <span
                className={clsx(
                  "inline-flex items-center gap-1 text-[10px] rounded px-1.5 py-0.5",
                  dueState === "overdue" &&
                    "bg-[var(--color-danger)]/10 text-[var(--color-danger)]",
                  dueState === "soon" &&
                    "bg-[var(--color-warning)]/10 text-[var(--color-warning)]",
                  dueState === "normal" &&
                    "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]",
                )}
              >
                <Calendar size={10} strokeWidth={2} />
                {formatDueShort(card.due_date)}
              </span>
            )}

            {card.estimate && (
              <span className="inline-flex items-center gap-1 text-[10px] text-[var(--muted)]">
                <Clock size={10} strokeWidth={2} />
                {card.estimate}
              </span>
            )}

            {(card.dependencies_count ?? 0) > 0 && (
              <span
                className="inline-flex items-center gap-1 text-[10px] text-[var(--color-warning)]"
                title="Bloquée par d'autres cartes"
              >
                <Link2 size={10} strokeWidth={2} />
                {card.dependencies_count}
              </span>
            )}

            {(card.children_count ?? 0) > 0 && (
              <span
                className="inline-flex items-center gap-1 text-[10px] text-[var(--muted)]"
                title="Sous-tâches"
              >
                <GitBranch size={10} strokeWidth={2} />
                {card.children_count}
              </span>
            )}
          </div>
        </div>

        <div className="flex items-start gap-1 shrink-0">
          {card.assignee && (
            <span
              className="size-6 rounded-full bg-[var(--color-neutral-300)] dark:bg-[var(--color-neutral-600)] flex items-center justify-center text-[10px] font-medium"
              title={card.assignee.email}
            >
              {card.assignee.email.charAt(0).toUpperCase()}
            </span>
          )}
          {onDelete && (
            <button
              onPointerDown={(e) => e.stopPropagation()}
              onClick={handleDelete}
              className="opacity-0 group-hover:opacity-100 p-1 -m-1 rounded text-[var(--muted)] hover:text-[var(--color-danger)] transition-opacity"
              aria-label="Supprimer"
            >
              <Trash2 size={12} />
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

function dueDateState(iso: string | null): "overdue" | "soon" | "normal" {
  if (!iso) return "normal";
  const due = new Date(iso).getTime();
  const now = Date.now();
  const diffDays = (due - now) / (1000 * 60 * 60 * 24);
  if (diffDays < 0) return "overdue";
  if (diffDays < 3) return "soon";
  return "normal";
}

function formatDueShort(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}
