"use client";

import { useState } from "react";
import { useDroppable } from "@dnd-kit/core";
import { Plus, MoreHorizontal, Trash2, CheckCircle2, Circle } from "lucide-react";
import type { BoardColumn, CardSummary } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import BoardCard from "./card";

export default function BoardColumnView({
  column,
  onAddCard,
  onDeleteCard,
  onDeleteColumn,
  onColumnUpdated,
  onOpenCard,
}: {
  column: BoardColumn;
  onAddCard: (card: CardSummary) => void;
  onDeleteCard: (cardId: string) => void;
  onDeleteColumn: () => void;
  onColumnUpdated: (column: BoardColumn) => void;
  onOpenCard: (cardId: string) => void;
}) {
  const { setNodeRef, isOver } = useDroppable({ id: column.id });
  const [adding, setAdding] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);

  async function handleDeleteColumn() {
    if (!confirm(`Supprimer la colonne "${column.name}" et toutes ses cartes ?`))
      return;
    const res = await apiFetch(`/api/columns/${column.id}`, { method: "DELETE" });
    if (res.ok) onDeleteColumn();
  }

  async function toggleIsDone() {
    setMenuOpen(false);
    const next = !column.is_done;
    const res = await apiFetch(`/api/columns/${column.id}`, {
      method: "PATCH",
      body: JSON.stringify({ is_done: next }),
    });
    if (!res.ok) return;
    const updated = (await res.json()) as BoardColumn;
    // Le PATCH renvoie la colonne sans cards — on garde celles déjà en mémoire,
    // et on met à jour completed_at localement pour refléter le serveur.
    const now = new Date().toISOString();
    const cards = column.cards.map((c) => ({
      ...c,
      completed_at: next ? c.completed_at ?? now : null,
    }));
    onColumnUpdated({ ...column, ...updated, cards });
  }

  return (
    <div className="flex flex-col w-72 shrink-0 rounded-lg bg-[var(--surface)] border border-[var(--border)] max-h-[calc(100vh-16rem)]">
      <header className="flex items-center gap-2 px-3 py-2.5 border-b border-[var(--border)]">
        {column.is_done && (
          <CheckCircle2
            size={14}
            className="text-[var(--color-success,#16a34a)] shrink-0"
            aria-label="Colonne de terminaison"
          />
        )}
        <span className="text-sm font-medium">{column.name}</span>
        <span className="text-[11px] text-[var(--muted)] bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] rounded-full px-1.5 py-0.5">
          {column.cards.length}
        </span>
        <div className="ml-auto relative">
          <button
            onClick={() => setMenuOpen((v) => !v)}
            className="p-1 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)] text-[var(--muted)]"
            aria-label="Menu colonne"
          >
            <MoreHorizontal size={14} />
          </button>
          {menuOpen && (
            <div className="absolute right-0 top-7 z-10 rounded-md border border-[var(--border)] bg-[var(--background)] shadow-md p-1 w-56">
              <button
                onClick={toggleIsDone}
                className="flex items-center gap-2 w-full text-left px-2 py-1.5 text-xs rounded hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
              >
                {column.is_done ? <Circle size={12} /> : <CheckCircle2 size={12} />}
                {column.is_done
                  ? "Retirer comme terminaison"
                  : "Marquer comme terminaison"}
              </button>
              <button
                onClick={handleDeleteColumn}
                className="flex items-center gap-2 w-full text-left px-2 py-1.5 text-xs rounded text-[var(--color-danger)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
              >
                <Trash2 size={12} />
                Supprimer
              </button>
            </div>
          )}
        </div>
      </header>

      <div
        ref={setNodeRef}
        className={`flex-1 overflow-y-auto p-2 space-y-2 transition-colors ${
          isOver ? "bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)]/50" : ""
        }`}
      >
        {column.cards.map((card) => (
          <BoardCard
            key={card.id}
            card={card}
            onDelete={() => onDeleteCard(card.id)}
            onOpen={() => onOpenCard(card.id)}
          />
        ))}
        {column.cards.length === 0 && !adding && (
          <p className="text-xs text-[var(--muted)] px-2 py-6 text-center">
            Glisse une carte ici
          </p>
        )}
      </div>

      <div className="p-2 border-t border-[var(--border)]">
        {adding ? (
          <NewCardForm
            columnId={column.id}
            onCreated={(card) => {
              onAddCard(card);
              setAdding(false);
            }}
            onCancel={() => setAdding(false)}
          />
        ) : (
          <button
            onClick={() => setAdding(true)}
            className="flex items-center gap-1.5 w-full px-2 py-1.5 rounded text-sm text-[var(--muted)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)] hover:text-[var(--foreground)] transition-colors"
          >
            <Plus size={14} strokeWidth={2} />
            Ajouter une carte
          </button>
        )}
      </div>
    </div>
  );
}

function NewCardForm({
  columnId,
  onCreated,
  onCancel,
}: {
  columnId: string;
  onCreated: (card: CardSummary) => void;
  onCancel: () => void;
}) {
  const [title, setTitle] = useState("");
  const [loading, setLoading] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!title.trim()) return;
    setLoading(true);
    const res = await apiFetch(`/api/columns/${columnId}/cards`, {
      method: "POST",
      body: JSON.stringify({ title: title.trim() }),
    });
    setLoading(false);
    if (!res.ok) return;
    const card = (await res.json()) as CardSummary;
    onCreated(card);
  }

  return (
    <form onSubmit={submit} className="space-y-2">
      <textarea
        value={title}
        onChange={(e) => setTitle(e.target.value)}
        autoFocus
        rows={2}
        onKeyDown={(e) => {
          if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            submit(e);
          }
          if (e.key === "Escape") onCancel();
        }}
        placeholder="Titre de la carte…"
        className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1.5 text-sm resize-none"
      />
      <div className="flex items-center gap-2">
        <button
          type="submit"
          disabled={loading || !title.trim()}
          className="rounded bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1 text-xs font-medium disabled:opacity-50"
        >
          Ajouter
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
        >
          Annuler
        </button>
      </div>
    </form>
  );
}
