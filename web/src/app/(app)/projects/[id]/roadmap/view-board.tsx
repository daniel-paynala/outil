"use client";

import { useState } from "react";
import type { RoadmapHorizon, RoadmapItem } from "@/lib/types";
import { HORIZONS } from "./horizon-meta";
import ItemCard from "./item-card";

export default function ViewBoard({
  items,
  onOpenItem,
  onMove,
}: {
  items: RoadmapItem[];
  onOpenItem: (id: string) => void;
  onMove: (id: string, horizon: string, position: number) => void;
}) {
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const [dragOverHorizon, setDragOverHorizon] = useState<RoadmapHorizon | null>(
    null,
  );

  const grouped: Record<RoadmapHorizon, RoadmapItem[]> = {
    now: [],
    next: [],
    later: [],
    done: [],
  };
  items.forEach((i) => grouped[i.horizon].push(i));
  Object.keys(grouped).forEach((h) =>
    grouped[h as RoadmapHorizon].sort((a, b) => a.position - b.position),
  );

  function handleDrop(horizon: RoadmapHorizon) {
    if (!draggingId) return;
    const targetCount = grouped[horizon].length;
    onMove(draggingId, horizon, targetCount);
    setDraggingId(null);
    setDragOverHorizon(null);
  }

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
      {HORIZONS.map((h) => (
        <div
          key={h.key}
          onDragOver={(e) => {
            e.preventDefault();
            setDragOverHorizon(h.key);
          }}
          onDragLeave={() => setDragOverHorizon(null)}
          onDrop={() => handleDrop(h.key)}
          className={`flex flex-col rounded-lg border bg-[var(--surface)] min-h-[16rem] ${
            dragOverHorizon === h.key
              ? "border-[var(--color-brand-red)]"
              : "border-[var(--border)]"
          }`}
        >
          <header className="flex items-center justify-between px-3 py-2.5 border-b border-[var(--border)]">
            <div>
              <p className="text-sm font-semibold">{h.label}</p>
              <p className="text-[11px] text-[var(--muted)]">{h.description}</p>
            </div>
            <span className="text-[11px] text-[var(--muted)] bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] rounded-full px-1.5 py-0.5">
              {grouped[h.key].length}
            </span>
          </header>
          <div className="flex-1 p-2 space-y-2 overflow-y-auto">
            {grouped[h.key].map((item) => (
              <ItemCard
                key={item.id}
                item={item}
                onOpen={() => onOpenItem(item.id)}
                draggable
                onDragStart={(e) => {
                  e.dataTransfer.effectAllowed = "move";
                  setDraggingId(item.id);
                }}
              />
            ))}
            {grouped[h.key].length === 0 && (
              <p className="text-xs text-[var(--muted)] text-center py-4">
                —
              </p>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}
