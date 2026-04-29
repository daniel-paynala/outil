"use client";

import { useState, useMemo } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { clsx } from "clsx";
import type { RoadmapItem } from "@/lib/types";

const WEEKDAYS = ["Lun", "Mar", "Mer", "Jeu", "Ven", "Sam", "Dim"];
const MONTH_NAMES = [
  "Janvier",
  "Février",
  "Mars",
  "Avril",
  "Mai",
  "Juin",
  "Juillet",
  "Août",
  "Septembre",
  "Octobre",
  "Novembre",
  "Décembre",
];

export default function ViewCalendar({
  items,
  onOpenItem,
}: {
  items: RoadmapItem[];
  onOpenItem: (id: string) => void;
}) {
  const [cursor, setCursor] = useState(() => {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1);
  });

  const dated = useMemo(
    () => items.filter((i) => i.target_date),
    [items],
  );

  const byDay = useMemo(() => {
    const map = new Map<string, RoadmapItem[]>();
    for (const i of dated) {
      const key = i.target_date!.slice(0, 10);
      const list = map.get(key) ?? [];
      list.push(i);
      map.set(key, list);
    }
    return map;
  }, [dated]);

  const cells = useMemo(() => buildCells(cursor), [cursor]);
  const todayKey = keyFromDate(new Date());
  const monthLabel = `${MONTH_NAMES[cursor.getMonth()]} ${cursor.getFullYear()}`;

  function move(delta: number) {
    setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + delta, 1));
  }

  function goToday() {
    const d = new Date();
    setCursor(new Date(d.getFullYear(), d.getMonth(), 1));
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-medium tracking-tight">{monthLabel}</h2>
        <div className="flex items-center gap-1">
          <button
            onClick={() => move(-1)}
            className="p-1.5 rounded border border-[var(--border)] hover:border-[var(--color-neutral-400)] text-[var(--muted)] hover:text-[var(--foreground)]"
          >
            <ChevronLeft size={13} />
          </button>
          <button
            onClick={goToday}
            className="px-2 py-1 text-xs rounded border border-[var(--border)] hover:border-[var(--color-neutral-400)]"
          >
            Aujourd'hui
          </button>
          <button
            onClick={() => move(1)}
            className="p-1.5 rounded border border-[var(--border)] hover:border-[var(--color-neutral-400)] text-[var(--muted)] hover:text-[var(--foreground)]"
          >
            <ChevronRight size={13} />
          </button>
        </div>
      </div>

      <div className="grid grid-cols-7 text-[11px] uppercase tracking-wider text-[var(--muted)] border-b border-[var(--border)]">
        {WEEKDAYS.map((d) => (
          <div key={d} className="px-2 py-1.5 text-center">
            {d}
          </div>
        ))}
      </div>

      <div className="grid grid-cols-7 auto-rows-[minmax(7rem,_1fr)] gap-px bg-[var(--border)] border border-[var(--border)] rounded-md overflow-hidden">
        {cells.map((cell) => {
          const key = keyFromDate(cell.date);
          const dayItems = byDay.get(key) ?? [];
          const isToday = key === todayKey;
          return (
            <div
              key={key}
              className={clsx(
                "bg-[var(--background)] p-1.5 flex flex-col overflow-hidden",
                !cell.inMonth && "opacity-40",
              )}
            >
              <div className="flex items-center gap-1 mb-1">
                <span
                  className={clsx(
                    "text-xs tabular-nums",
                    isToday &&
                      "bg-[var(--color-brand-red)] text-white rounded-full size-5 flex items-center justify-center font-medium",
                    !isToday && "text-[var(--muted)]",
                  )}
                >
                  {cell.date.getDate()}
                </span>
              </div>
              <ul className="flex-1 overflow-y-auto space-y-0.5">
                {dayItems.slice(0, 4).map((i) => (
                  <li key={i.id}>
                    <button
                      onClick={() => onOpenItem(i.id)}
                      className="w-full text-left text-[11px] truncate rounded px-1.5 py-0.5 bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)] hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)]"
                      title={i.title}
                      style={
                        i.release
                          ? {
                              background: `color-mix(in oklab, ${i.release.color} 12%, transparent)`,
                              color: i.release.color,
                            }
                          : undefined
                      }
                    >
                      {i.title}
                    </button>
                  </li>
                ))}
                {dayItems.length > 4 && (
                  <li className="text-[10px] text-[var(--muted)] px-1.5">
                    +{dayItems.length - 4}
                  </li>
                )}
              </ul>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function buildCells(cursor: Date): { date: Date; inMonth: boolean }[] {
  const firstOfMonth = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
  const firstDow = (firstOfMonth.getDay() + 6) % 7;

  const start = new Date(firstOfMonth);
  start.setDate(firstOfMonth.getDate() - firstDow);

  const cells: { date: Date; inMonth: boolean }[] = [];
  for (let i = 0; i < 42; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    cells.push({ date: d, inMonth: d.getMonth() === cursor.getMonth() });
  }
  return cells;
}

function keyFromDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
