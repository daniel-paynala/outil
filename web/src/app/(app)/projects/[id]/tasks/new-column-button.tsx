"use client";

import { useState } from "react";
import { Plus, X } from "lucide-react";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import type { BoardColumn } from "@/lib/types";

export default function NewColumnButton({
  projectId,
  onCreated,
}: {
  projectId: string;
  onCreated: (col: BoardColumn) => void;
}) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [loading, setLoading] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim()) return;
    setLoading(true);
    try {
      const res = await apiFetch(`/api/projects/${projectId}/columns`, {
        method: "POST",
        body: JSON.stringify({ name: name.trim() }),
      });
      setLoading(false);
      if (!res.ok) {
        toast.error(
          "Création impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      const col = (await res.json()) as BoardColumn;
      toast.success("Créée", `Colonne « ${col.name} »`);
      onCreated(col);
      setName("");
      setOpen(false);
    } catch (err) {
      setLoading(false);
      toast.error(
        "Création impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  if (!open) {
    return (
      <button
        onClick={() => setOpen(true)}
        className="w-72 shrink-0 flex items-center justify-center gap-2 rounded-lg border border-dashed border-[var(--border)] text-sm text-[var(--muted)] hover:text-[var(--foreground)] hover:border-[var(--color-neutral-400)] py-3 transition-colors"
      >
        <Plus size={15} strokeWidth={2} />
        Nouvelle colonne
      </button>
    );
  }

  return (
    <form
      onSubmit={submit}
      className="w-72 shrink-0 rounded-lg border border-[var(--border)] bg-[var(--surface)] p-3 space-y-2"
    >
      <div className="flex items-center justify-between">
        <p className="text-xs font-medium">Nouvelle colonne</p>
        <button
          type="button"
          onClick={() => {
            setOpen(false);
            setName("");
          }}
          className="p-1 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)]"
        >
          <X size={12} />
        </button>
      </div>
      <input
        value={name}
        onChange={(e) => setName(e.target.value)}
        autoFocus
        maxLength={80}
        onKeyDown={(e) => e.key === "Escape" && setOpen(false)}
        placeholder="Nom de la colonne"
        className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1.5 text-sm"
      />
      <button
        type="submit"
        disabled={loading || !name.trim()}
        className="w-full rounded bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white py-1.5 text-xs font-medium disabled:opacity-50"
      >
        Ajouter
      </button>
    </form>
  );
}
