"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import { Plus, Loader2, X } from "lucide-react";

const COLORS = [
  "#d9342b", // brand red
  "#f5b800", // brand yellow
  "#2563eb", // blue
  "#16a34a", // green
  "#9333ea", // purple
  "#0891b2", // cyan
  "#737375", // neutral (default)
];

export default function NewProjectForm() {
  const router = useRouter();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [color, setColor] = useState(COLORS[0]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function reset() {
    setName("");
    setDescription("");
    setColor(COLORS[0]);
    setError(null);
    setOpen(false);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);

    const res = await apiFetch("/api/projects", {
      method: "POST",
      body: JSON.stringify({ name, description: description || null, color }),
    });

    setLoading(false);

    if (!res.ok) {
      const text = await res.text();
      const message = text || `Erreur ${res.status}`;
      setError(message);
      toast.error("Création du projet impossible", message);
      return;
    }

    toast.success("Projet créé", name);
    reset();
    router.refresh();
  }

  if (!open) {
    return (
      <button
        onClick={() => setOpen(true)}
        className="inline-flex items-center gap-2 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3.5 py-2 text-sm font-medium hover:border-[var(--color-neutral-400)] transition-colors"
      >
        <Plus size={15} strokeWidth={2} />
        Nouveau projet
      </button>
    );
  }

  return (
    <form
      onSubmit={handleSubmit}
      className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5 space-y-4"
    >
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-medium">Nouveau projet</h2>
        <button
          type="button"
          onClick={reset}
          className="p-1 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)]"
        >
          <X size={14} strokeWidth={2} />
        </button>
      </div>

      <div className="space-y-1.5">
        <label htmlFor="name" className="text-xs font-medium">
          Nom
        </label>
        <input
          id="name"
          required
          autoFocus
          maxLength={120}
          value={name}
          onChange={(e) => setName(e.target.value)}
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
          placeholder="ex: Refonte site vitrine"
        />
      </div>

      <div className="space-y-1.5">
        <label htmlFor="description" className="text-xs font-medium">
          Description <span className="text-[var(--muted)]">(optionnel)</span>
        </label>
        <textarea
          id="description"
          rows={2}
          maxLength={2000}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm resize-none"
        />
      </div>

      <div className="space-y-1.5">
        <p className="text-xs font-medium">Couleur</p>
        <div className="flex gap-1.5">
          {COLORS.map((c) => (
            <button
              key={c}
              type="button"
              onClick={() => setColor(c)}
              aria-label={c}
              className={`size-6 rounded-full transition-transform ${
                color === c
                  ? "ring-2 ring-offset-2 ring-[var(--foreground)] ring-offset-[var(--surface)]"
                  : "hover:scale-110"
              }`}
              style={{ background: c }}
            />
          ))}
        </div>
      </div>

      {error && (
        <p className="text-xs text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded-md px-3 py-2">
          {error}
        </p>
      )}

      <div className="flex items-center gap-2 pt-1">
        <button
          type="submit"
          disabled={loading || !name.trim()}
          className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3.5 py-2 text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {loading && <Loader2 size={13} className="animate-spin" />}
          Créer
        </button>
        <button
          type="button"
          onClick={reset}
          className="rounded-md px-3 py-2 text-sm text-[var(--muted)] hover:text-[var(--foreground)]"
        >
          Annuler
        </button>
      </div>
    </form>
  );
}
