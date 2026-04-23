"use client";

import { useState, useCallback, useMemo, useEffect } from "react";
import {
  DndContext,
  DragOverlay,
  PointerSensor,
  useSensor,
  useSensors,
  closestCorners,
  type DragStartEvent,
  type DragEndEvent,
  type DragOverEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import type {
  BoardColumn,
  CardSummary,
  Label,
  ProjectMember,
} from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import BoardCard from "./card";
import BoardColumnView from "./column";
import NewColumnButton from "./new-column-button";
import CardDrawer from "./card-drawer";

export default function Board({
  projectId,
  initialColumns,
  members,
  initialLabels,
}: {
  projectId: string;
  initialColumns: BoardColumn[];
  members: ProjectMember[];
  initialLabels: Label[];
}) {
  const [columns, setColumns] = useState<BoardColumn[]>(initialColumns);
  const [labels, setLabels] = useState<Label[]>(initialLabels);
  const [activeCard, setActiveCard] = useState<CardSummary | null>(null);
  const [openCardId, setOpenCardId] = useState<string | null>(null);
  const [mounted, setMounted] = useState(false);

  useEffect(() => setMounted(true), []);

  const firstColumnId = useMemo(
    () => (columns.length > 0 ? columns[0].id : null),
    [columns],
  );

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
  );

  const findCard = useCallback(
    (cardId: string): { column: BoardColumn; card: CardSummary } | null => {
      for (const col of columns) {
        const card = col.cards.find((c) => c.id === cardId);
        if (card) return { column: col, card };
      }
      return null;
    },
    [columns],
  );

  function onDragStart(e: DragStartEvent) {
    const found = findCard(String(e.active.id));
    setActiveCard(found?.card ?? null);
  }

  function onDragOver(e: DragOverEvent) {
    const { active, over } = e;
    if (!over || active.id === over.id) return;

    const activeId = String(active.id);
    const overId = String(over.id);
    const fromFound = findCard(activeId);
    if (!fromFound) return;

    const fromColumnId = fromFound.column.id;

    const overColumn = columns.find((c) => c.id === overId);
    if (overColumn) {
      if (overColumn.id === fromColumnId) return;
      setColumns((prev) =>
        moveCardToColumn(prev, activeId, overColumn.id, overColumn.cards.length),
      );
      return;
    }

    const toFound = findCard(overId);
    if (!toFound) return;
    if (toFound.column.id === fromColumnId) return;

    const toIndex = toFound.column.cards.findIndex((c) => c.id === overId);
    setColumns((prev) =>
      moveCardToColumn(prev, activeId, toFound.column.id, toIndex),
    );
  }

  async function onDragEnd(e: DragEndEvent) {
    const { active, over } = e;
    setActiveCard(null);
    if (!over) return;

    const activeId = String(active.id);
    const overId = String(over.id);

    const fromFound = findCard(activeId);
    if (!fromFound) return;

    let targetColumnId: string;
    let targetPosition: number;

    const overColumn = columns.find((c) => c.id === overId);
    if (overColumn) {
      targetColumnId = overColumn.id;
      targetPosition = overColumn.cards.findIndex((c) => c.id === activeId);
      if (targetPosition === -1) targetPosition = overColumn.cards.length;
    } else {
      const toFound = findCard(overId);
      if (!toFound) return;
      targetColumnId = toFound.column.id;
      const currentIndex = toFound.column.cards.findIndex((c) => c.id === activeId);
      const overIndex = toFound.column.cards.findIndex((c) => c.id === overId);
      if (currentIndex === -1) {
        targetPosition = overIndex;
      } else {
        setColumns((prev) =>
          reorderWithinColumn(prev, toFound.column.id, currentIndex, overIndex),
        );
        targetPosition = overIndex;
      }
    }

    const res = await apiFetch(`/api/projects/${projectId}/board/move`, {
      method: "POST",
      body: JSON.stringify({
        card_id: activeId,
        to_column_id: targetColumnId,
        to_position: targetPosition,
      }),
    });
    if (!res.ok) {
      const fresh = await apiFetch(`/api/projects/${projectId}/columns`);
      if (fresh.ok) setColumns(await fresh.json());
    }
  }

  function onAddCard(columnId: string, card: CardSummary) {
    setColumns((prev) =>
      prev.map((c) =>
        c.id === columnId ? { ...c, cards: [...c.cards, card] } : c,
      ),
    );
  }

  function onDeleteCard(cardId: string) {
    setColumns((prev) =>
      prev.map((c) => ({
        ...c,
        cards: c.cards.filter((card) => card.id !== cardId),
      })),
    );
  }

  function onAddColumn(col: BoardColumn) {
    setColumns((prev) => [...prev, { ...col, cards: [] }]);
  }

  function onDeleteColumn(columnId: string) {
    setColumns((prev) => prev.filter((c) => c.id !== columnId));
  }

  function onCardUpdated(updated: CardSummary) {
    setColumns((prev) =>
      prev.map((col) => ({
        ...col,
        cards: col.cards.map((c) =>
          c.id === updated.id ? { ...c, ...updated } : c,
        ),
      })),
    );
  }

  if (!mounted) {
    return <BoardSkeleton columns={columns} />;
  }

  return (
    <>
      <DndContext
        sensors={sensors}
        collisionDetection={closestCorners}
        onDragStart={onDragStart}
        onDragOver={onDragOver}
        onDragEnd={onDragEnd}
      >
        <div className="flex gap-3 overflow-x-auto pb-4 -mx-8 px-8">
          {columns.map((col) => (
            <SortableContext
              key={col.id}
              items={col.cards.map((c) => c.id)}
              strategy={verticalListSortingStrategy}
            >
              <BoardColumnView
                column={col}
                onAddCard={(card) => onAddCard(col.id, card)}
                onDeleteCard={onDeleteCard}
                onDeleteColumn={() => onDeleteColumn(col.id)}
                onOpenCard={setOpenCardId}
              />
            </SortableContext>
          ))}

          <NewColumnButton projectId={projectId} onCreated={onAddColumn} />
        </div>

        <DragOverlay>
          {activeCard ? <BoardCard card={activeCard} overlay /> : null}
        </DragOverlay>
      </DndContext>

      {openCardId && (
        <CardDrawer
          key={openCardId}
          cardId={openCardId}
          projectId={projectId}
          members={members}
          labels={labels}
          firstColumnId={firstColumnId}
          onClose={() => setOpenCardId(null)}
          onUpdated={onCardUpdated}
          onDeleted={() => onDeleteCard(openCardId)}
          onLabelsChanged={setLabels}
        />
      )}
    </>
  );
}

function BoardSkeleton({ columns }: { columns: BoardColumn[] }) {
  return (
    <div className="flex gap-3 overflow-x-auto pb-4 -mx-8 px-8">
      {columns.map((col) => (
        <div
          key={col.id}
          className="flex flex-col w-72 shrink-0 rounded-lg bg-[var(--surface)] border border-[var(--border)] max-h-[calc(100vh-16rem)]"
        >
          <header className="flex items-center gap-2 px-3 py-2.5 border-b border-[var(--border)]">
            <span className="text-sm font-medium">{col.name}</span>
            <span className="text-[11px] text-[var(--muted)] bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] rounded-full px-1.5 py-0.5">
              {col.cards.length}
            </span>
          </header>
          <div className="flex-1 overflow-y-auto p-2 space-y-2">
            {col.cards.map((c) => (
              <div
                key={c.id}
                className="rounded-md bg-[var(--background)] border border-[var(--border)] px-3 py-2.5 shadow-sm"
              >
                <p className="text-sm leading-snug">{c.title}</p>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

function moveCardToColumn(
  columns: BoardColumn[],
  cardId: string,
  toColumnId: string,
  toIndex: number,
): BoardColumn[] {
  let moving: CardSummary | null = null;
  const removed = columns.map((col) => {
    const found = col.cards.find((c) => c.id === cardId);
    if (found) {
      moving = found;
      return { ...col, cards: col.cards.filter((c) => c.id !== cardId) };
    }
    return col;
  });
  if (!moving) return columns;
  return removed.map((col) => {
    if (col.id !== toColumnId) return col;
    const next = [...col.cards];
    const idx = Math.min(toIndex, next.length);
    next.splice(idx, 0, { ...moving!, column_id: toColumnId });
    return { ...col, cards: next };
  });
}

function reorderWithinColumn(
  columns: BoardColumn[],
  columnId: string,
  from: number,
  to: number,
): BoardColumn[] {
  return columns.map((col) => {
    if (col.id !== columnId) return col;
    const next = [...col.cards];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);
    return { ...col, cards: next };
  });
}
