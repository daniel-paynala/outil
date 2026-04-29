"use client";

import { useState } from "react";
import { Plus, Package, Loader2, Pencil, Trash2, X } from "lucide-react";
import type { Release, RoadmapItem } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import ItemCard from "./item-card";

export default function ViewReleases({
  items,
  releases,
  projectId,
  onOpenItem,
  onReleaseCreated,
  onReleaseUpdated,
  onReleaseDeleted,
}: {
  items: RoadmapItem[];
  releases: Release[];
  projectId: string;
  onOpenItem: (id: string) => void;
  onReleaseCreated: (r: Release) => void;
  onReleaseUpdated: (r: Release) => void;
  onReleaseDeleted: (id: string) => void;
}) {
  const [creating, setCreating] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);

  const sortedReleases = [...releases].sort((a, b) => {
    if (a.shipped_at && b.shipped_at) {
      return (
        new Date(b.shipped_at).getTime() - new Date(a.shipped_at).getTime()
      );
    }
    if (!a.shipped_at && b.shipped_at) return -1; // unshipped first
    if (a.shipped_at && !b.shipped_at) return 1;
    return a.name.localeCompare(b.name);
  });

  const itemsByRelease = new Map<string | null, RoadmapItem[]>();
  items.forEach((i) => {
    const key = i.release_id;
    const list = itemsByRelease.get(key) ?? [];
    list.push(i);
    itemsByRelease.set(key, list);
  });

  const orphaned = itemsByRelease.get(null) ?? [];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h3 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)]">
          {releases.length} release{releases.length > 1 ? "s" : ""}
        </h3>
        {!creating && (
          <button
            onClick={() => setCreating(true)}
            className="inline-flex items-center gap-1 text-xs rounded border border-[var(--border)] hover:border-[var(--color-neutral-400)] px-2 py-1"
          >
            <Plus size={11} />
            Nouvelle release
          </button>
        )}
      </div>

      {creating && (
        <ReleaseForm
          projectId={projectId}
          onClose={() => setCreating(false)}
          onSaved={(r) => {
            onReleaseCreated(r);
            setCreating(false);
          }}
        />
      )}

      {sortedReleases.length === 0 && !creating && (
        <p className="text-sm text-[var(--muted)] py-8 text-center border border-dashed border-[var(--border)] rounded-lg">
          Aucune release. Crée-en une pour grouper les items livrés.
        </p>
      )}

      {sortedReleases.map((release) => {
        const releaseItems = itemsByRelease.get(release.id) ?? [];
        return (
          <section
            key={release.id}
            className="rounded-lg border border-[var(--border)] bg-[var(--surface)] overflow-hidden"
          >
            <header className="flex items-center justify-between gap-3 px-4 py-3 border-b border-[var(--border)]">
              <div className="flex items-center gap-3 min-w-0">
                <span
                  className="size-3 rounded-full shrink-0"
                  style={{ background: release.color }}
                />
                <div className="min-w-0">
                  <p className="text-sm font-semibold truncate">{release.name}</p>
                  <p className="text-[11px] text-[var(--muted)]">
                    {release.shipped_at
                      ? `Livrée le ${new Date(release.shipped_at).toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" })}`
                      : "Non livrée — date à fixer"}{" "}
                    · {releaseItems.length} item
                    {releaseItems.length > 1 ? "s" : ""}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-1">
                <button
                  onClick={() =>
                    setEditingId(editingId === release.id ? null : release.id)
                  }
                  className="p-1.5 rounded text-[var(--muted)] hover:text-[var(--foreground)]"
                  title="Modifier"
                >
                  <Pencil size={13} />
                </button>
              </div>
            </header>

            {editingId === release.id && (
              <div className="px-4 py-3 border-b border-[var(--border)]">
                <ReleaseForm
                  projectId={projectId}
                  release={release}
                  onClose={() => setEditingId(null)}
                  onSaved={(r) => {
                    onReleaseUpdated(r);
                    setEditingId(null);
                  }}
                  onDeleted={() => {
                    onReleaseDeleted(release.id);
                    setEditingId(null);
                  }}
                />
              </div>
            )}

            <div className="p-3 grid grid-cols-1 md:grid-cols-2 gap-2">
              {releaseItems.length === 0 ? (
                <p className="text-xs text-[var(--muted)] py-4 text-center md:col-span-2">
                  Aucun item dans cette release.
                </p>
              ) : (
                releaseItems.map((item) => (
                  <ItemCard
                    key={item.id}
                    item={item}
                    onOpen={() => onOpenItem(item.id)}
                  />
                ))
              )}
            </div>
          </section>
        );
      })}

      {orphaned.length > 0 && (
        <section>
          <h3 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)] mb-3 flex items-center gap-1">
            <Package size={12} />
            Sans release ({orphaned.length})
          </h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
            {orphaned.map((item) => (
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

function ReleaseForm({
  projectId,
  release,
  onClose,
  onSaved,
  onDeleted,
}: {
  projectId: string;
  release?: Release;
  onClose: () => void;
  onSaved: (r: Release) => void;
  onDeleted?: () => void;
}) {
  const [name, setName] = useState(release?.name ?? "");
  const [description, setDescription] = useState(release?.description ?? "");
  const [shippedAt, setShippedAt] = useState(
    release?.shipped_at ? release.shipped_at.slice(0, 10) : "",
  );
  const [color, setColor] = useState(release?.color ?? "#737375");
  const [loading, setLoading] = useState(false);

  const COLORS = [
    "#737375",
    "#d9342b",
    "#f5b800",
    "#2563eb",
    "#16a34a",
    "#9333ea",
  ];

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    const body = {
      name: name.trim(),
      description: description || null,
      shipped_at: shippedAt || null,
      color,
    };
    const url = release
      ? `/api/releases/${release.id}`
      : `/api/projects/${projectId}/releases`;
    const res = await apiFetch(url, {
      method: release ? "PATCH" : "POST",
      body: JSON.stringify(body),
    });
    setLoading(false);
    if (!res.ok) return;
    onSaved((await res.json()) as Release);
  }

  async function handleDelete() {
    if (!release) return;
    if (!confirm(`Supprimer la release "${release.name}" ?`)) return;
    const res = await apiFetch(`/api/releases/${release.id}`, {
      method: "DELETE",
    });
    if (res.ok && onDeleted) onDeleted();
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label className="text-xs font-medium">Nom *</label>
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
            placeholder="ex: V1.0"
            className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label className="text-xs font-medium">Date de livraison</label>
          <input
            type="date"
            value={shippedAt}
            onChange={(e) => setShippedAt(e.target.value)}
            className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
          />
        </div>
      </div>
      <div>
        <label className="text-xs font-medium">Description</label>
        <textarea
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          rows={2}
          className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
        />
      </div>
      <div>
        <label className="text-xs font-medium">Couleur</label>
        <div className="flex gap-1.5 mt-1">
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
      <div className="flex gap-2 pt-1">
        <button
          type="submit"
          disabled={loading || !name.trim()}
          className="inline-flex items-center gap-1 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1.5 text-xs font-medium disabled:opacity-50"
        >
          {loading && <Loader2 size={11} className="animate-spin" />}
          {release ? "Enregistrer" : "Créer"}
        </button>
        {release && onDeleted && (
          <button
            type="button"
            onClick={handleDelete}
            className="inline-flex items-center gap-1 rounded-md text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 px-3 py-1.5 text-xs"
          >
            <Trash2 size={11} />
            Supprimer
          </button>
        )}
        <button
          type="button"
          onClick={onClose}
          className="text-xs text-[var(--muted)] hover:text-[var(--foreground)] px-2"
        >
          <X size={13} className="inline" /> Annuler
        </button>
      </div>
    </form>
  );
}
