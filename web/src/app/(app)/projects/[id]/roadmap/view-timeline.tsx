"use client";

import { useMemo } from "react";
import type { RoadmapItem } from "@/lib/types";
import ItemCard from "./item-card";

/**
 * Vue Timeline : items dotés d'une target_date placés sur une frise chronologique mensuelle.
 * Items sans date affichés dans une section "À planifier".
 */
export default function ViewTimeline({
  items,
  onOpenItem,
}: {
  items: RoadmapItem[];
  onOpenItem: (id: string) => void;
}) {
  const dated = items.filter((i) => i.target_date);
  const undated = items.filter((i) => !i.target_date);

  const grouped = useMemo(() => {
    const map = new Map<string, RoadmapItem[]>();
    dated.forEach((i) => {
      const d = new Date(i.target_date as string);
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
      const list = map.get(key) ?? [];
      list.push(i);
      map.set(key, list);
    });
    return Array.from(map.entries()).sort(([a], [b]) => a.localeCompare(b));
  }, [dated]);

  return (
    <div className="space-y-6">
      <div className="space-y-4">
        {grouped.length === 0 && (
          <p className="text-sm text-[var(--muted)] py-8 text-center border border-dashed border-[var(--border)] rounded-lg">
            Aucun item daté. Ajoute une <em>target_date</em> à tes items pour
            qu'ils apparaissent sur la frise.
          </p>
        )}
        {grouped.map(([yearMonth, monthItems]) => (
          <MonthRow key={yearMonth} yearMonth={yearMonth} items={monthItems} onOpen={onOpenItem} />
        ))}
      </div>

      {undated.length > 0 && (
        <section>
          <h3 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)] mb-3">
            À planifier ({undated.length})
          </h3>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
            {undated.map((item) => (
              <ItemCard
                key={item.id}
                item={item}
                onOpen={() => onOpenItem(item.id)}
              />
            ))}
          </div>
        </section>
      )}
    </div>
  );
}

function MonthRow({
  yearMonth,
  items,
  onOpen,
}: {
  yearMonth: string;
  items: RoadmapItem[];
  onOpen: (id: string) => void;
}) {
  const [year, month] = yearMonth.split("-").map((s) => parseInt(s, 10));
  const date = new Date(year, month - 1, 1);
  const label = date.toLocaleDateString("fr-FR", {
    month: "long",
    year: "numeric",
  });
  const isPast = date < new Date(new Date().getFullYear(), new Date().getMonth(), 1);

  return (
    <div className="flex gap-4">
      <div className="w-32 shrink-0">
        <div
          className={`sticky top-4 text-sm font-semibold ${
            isPast ? "text-[var(--muted)]" : "text-[var(--foreground)]"
          }`}
        >
          {label}
          <p className="text-[11px] font-normal text-[var(--muted)]">
            {items.length} item{items.length > 1 ? "s" : ""}
          </p>
        </div>
      </div>
      <div className="relative flex-1 border-l-2 border-[var(--border)] pl-4 pb-4">
        <div className="absolute -left-[5px] top-1.5 size-2.5 rounded-full bg-[var(--color-brand-red)]" />
        <div className="space-y-2">
          {items.map((item) => (
            <ItemCard
              key={item.id}
              item={item}
              onOpen={() => onOpen(item.id)}
            />
          ))}
        </div>
      </div>
    </div>
  );
}
