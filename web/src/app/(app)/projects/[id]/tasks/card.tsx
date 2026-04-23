"use client";

import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { Trash2, GripVertical } from "lucide-react";
import { clsx } from "clsx";
import type { Card } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";

export default function BoardCard({
  card,
  onDelete,
  overlay = false,
}: {
  card: Card;
  onDelete?: () => void;
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

  async function handleDelete() {
    if (!onDelete) return;
    if (!confirm("Supprimer cette carte ?")) return;
    const res = await apiFetch(`/api/cards/${card.id}`, { method: "DELETE" });
    if (res.ok) onDelete();
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...attributes}
      {...listeners}
      className={clsx(
        "group rounded-md bg-[var(--background)] border border-[var(--border)] px-3 py-2.5 shadow-sm cursor-grab active:cursor-grabbing",
        overlay && "shadow-lg border-[var(--color-neutral-400)] rotate-1",
      )}
    >
      <div className="flex items-start gap-2">
        <div className="flex-1 min-w-0">
          <p className="text-sm leading-snug">{card.title}</p>
          {card.priority && (
            <span
              className={clsx(
                "inline-block mt-2 text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded",
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
        </div>
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
  );
}
