"use client";

import { CalendarDays, Hash, Link2, User as UserIcon } from "lucide-react";
import type { RoadmapItem } from "@/lib/types";

const EFFORT_COLOR: Record<string, string> = {
  S: "var(--color-neutral-400)",
  M: "var(--color-info)",
  L: "var(--color-warning)",
  XL: "var(--color-brand-red)",
};

export default function ItemCard({
  item,
  onOpen,
  draggable = false,
  onDragStart,
}: {
  item: RoadmapItem;
  onOpen?: () => void;
  draggable?: boolean;
  onDragStart?: (e: React.DragEvent) => void;
}) {
  return (
    <div
      draggable={draggable}
      onDragStart={onDragStart}
      onClick={onOpen}
      className="group rounded-md bg-[var(--background)] border border-[var(--border)] px-3 py-2.5 shadow-sm cursor-pointer hover:border-[var(--color-neutral-400)] transition-colors"
    >
      <p className="text-sm leading-snug">{item.title}</p>
      <div className="flex items-center gap-2 mt-2 flex-wrap">
        {item.release && (
          <span
            className="inline-flex items-center gap-1 text-[10px] rounded px-1.5 py-0.5"
            style={{
              background: `color-mix(in oklab, ${item.release.color} 15%, transparent)`,
              color: item.release.color,
            }}
          >
            <Hash size={9} strokeWidth={2} />
            {item.release.name}
          </span>
        )}
        {item.effort && (
          <span
            className="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5"
            style={{
              background: `color-mix(in oklab, ${EFFORT_COLOR[item.effort]} 12%, transparent)`,
              color: EFFORT_COLOR[item.effort],
            }}
          >
            {item.effort}
          </span>
        )}
        {item.target_date && (
          <span className="inline-flex items-center gap-1 text-[10px] text-[var(--muted)]">
            <CalendarDays size={10} strokeWidth={2} />
            {new Date(item.target_date).toLocaleDateString("fr-FR", {
              day: "numeric",
              month: "short",
            })}
          </span>
        )}
        {(item.cards_count ?? 0) > 0 && (
          <span
            className="inline-flex items-center gap-1 text-[10px] text-[var(--muted)]"
            title="Cartes liées"
          >
            <Link2 size={10} strokeWidth={2} />
            {item.cards_count}
          </span>
        )}
        {item.tags && item.tags.length > 0 && (
          <div className="flex gap-1">
            {item.tags.slice(0, 3).map((t) => (
              <span
                key={t}
                className="text-[10px] rounded bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)] px-1.5 py-0.5"
              >
                #{t}
              </span>
            ))}
            {item.tags.length > 3 && (
              <span className="text-[10px] text-[var(--muted)]">
                +{item.tags.length - 3}
              </span>
            )}
          </div>
        )}
        {item.owner && (
          <span
            className="ml-auto size-5 rounded-full bg-[var(--color-neutral-300)] dark:bg-[var(--color-neutral-600)] flex items-center justify-center text-[10px] font-medium"
            title={item.owner.email}
          >
            {item.owner.email.charAt(0).toUpperCase()}
          </span>
        )}
      </div>
    </div>
  );
}
