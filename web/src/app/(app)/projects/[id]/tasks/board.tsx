"use client";

import { useState, useCallback } from "react";
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
import type { BoardColumn, Card } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import BoardCard from "./card";
import BoardColumnView from "./column";
import NewColumnButton from "./new-column-button";

export default function Board({
  projectId,
  initialColumns,
}: {
  projectId: string;
  initialColumns: BoardColumn[];
}) {
  const [columns, setColumns] = useState<BoardColumn[]>(initialColumns);
  const [activeCard, setActiveCard] = useState<Card | null>(null);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
  );

  const findCard = useCallback(
    (cardId: string): { column: BoardColumn; card: Card } | null => {
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

    // Dropping on a column (empty zone) → move to end of that column
    const overColumn = columns.find((c) => c.id === overId);
    if (overColumn) {
      if (overColumn.id === fromColumnId) return;
      setColumns((prev) => moveCardToColumn(prev, activeId, overColumn.id, overColumn.cards.length));
      return;
    }

    // Dropping on another card
    const toFound = findCard(overId);
    if (!toFound) return;
    if (toFound.column.id === fromColumnId) return;

    const toIndex = toFound.column.cards.findIndex((c) => c.id === overId);
    setColumns((prev) => moveCardToColumn(prev, activeId, toFound.column.id, toIndex));
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
        // Same column reorder
        setColumns((prev) =>
          reorderWithinColumn(prev, toFound.column.id, currentIndex, overIndex),
        );
        targetPosition = overIndex;
      }
    }

    // Optimistic — already updated state during onDragOver / reorder.
    // Fire the API call:
    const res = await apiFetch(`/api/projects/${projectId}/board/move`, {
      method: "POST",
      body: JSON.stringify({
        card_id: activeId,
        to_column_id: targetColumnId,
        to_position: targetPosition,
      }),
    });
    if (!res.ok) {
      // Revert on failure by refetching
      const fresh = await apiFetch(`/api/projects/${projectId}/columns`);
      if (fresh.ok) setColumns(await fresh.json());
    }
  }

  function onAddCard(columnId: string, card: Card) {
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

  return (
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
            />
          </SortableContext>
        ))}

        <NewColumnButton projectId={projectId} onCreated={onAddColumn} />
      </div>

      <DragOverlay>
        {activeCard ? <BoardCard card={activeCard} overlay /> : null}
      </DragOverlay>
    </DndContext>
  );
}

function moveCardToColumn(
  columns: BoardColumn[],
  cardId: string,
  toColumnId: string,
  toIndex: number,
): BoardColumn[] {
  let moving: Card | null = null;
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
