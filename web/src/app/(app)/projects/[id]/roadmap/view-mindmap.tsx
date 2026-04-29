"use client";

import { useMemo } from "react";
import type { RoadmapItem } from "@/lib/types";
import ItemCard from "./item-card";

const NO_TAG = "__no_tag__";

/**
 * Vue Mindmap : items groupés par tag thématique. Items sans tag dans une catégorie "Non classé".
 */
export default function ViewMindmap({
  items,
  onOpenItem,
}: {
  items: RoadmapItem[];
  onOpenItem: (id: string) => void;
}) {
  const groups = useMemo(() => {
    const map = new Map<string, RoadmapItem[]>();
    items.forEach((item) => {
      if (!item.tags || item.tags.length === 0) {
        const list = map.get(NO_TAG) ?? [];
        list.push(item);
        map.set(NO_TAG, list);
      } else {
        item.tags.forEach((tag) => {
          const list = map.get(tag) ?? [];
          list.push(item);
          map.set(tag, list);
        });
      }
    });
    return Array.from(map.entries()).sort((a, b) => {
      if (a[0] === NO_TAG) return 1;
      if (b[0] === NO_TAG) return -1;
      return b[1].length - a[1].length;
    });
  }, [items]);

  if (groups.length === 0) {
    return (
      <p className="text-sm text-[var(--muted)] py-8 text-center border border-dashed border-[var(--border)] rounded-lg">
        Ajoute des tags à tes items pour les regrouper par thème.
      </p>
    );
  }

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      {groups.map(([tag, tagItems]) => (
        <section
          key={tag}
          className="rounded-lg border border-[var(--border)] bg-[var(--surface)] overflow-hidden"
        >
          <header className="px-4 py-2.5 border-b border-[var(--border)] bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)]">
            <p className="text-sm font-semibold">
              {tag === NO_TAG ? "Non classé" : `#${tag}`}
            </p>
            <p className="text-[11px] text-[var(--muted)]">
              {tagItems.length} item{tagItems.length > 1 ? "s" : ""}
            </p>
          </header>
          <div className="p-3 space-y-2">
            {tagItems.map((item) => (
              <ItemCard
                key={`${tag}-${item.id}`}
                item={item}
                onOpen={() => onOpenItem(item.id)}
              />
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}
