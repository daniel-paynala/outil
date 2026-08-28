"use client";

import { useState } from "react";
import { Loader2, Plus, X } from "lucide-react";
import type {
  ProjectMember,
  Release,
  RoadmapEffort,
  RoadmapHorizon,
  RoadmapItem,
} from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import OwnersPicker from "./owners-picker";

const HORIZONS: RoadmapHorizon[] = ["now", "next", "later", "done"];
const EFFORTS: RoadmapEffort[] = ["S", "M", "L", "XL"];

export default function NewItemForm({
  projectId,
  members,
  releases,
  onClose,
  onCreated,
}: {
  projectId: string;
  members: ProjectMember[];
  releases: Release[];
  onClose: () => void;
  onCreated: (item: RoadmapItem) => void;
}) {
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [horizon, setHorizon] = useState<RoadmapHorizon>("later");
  const [effort, setEffort] = useState<RoadmapEffort | "">("");
  const [startDate, setStartDate] = useState("");
  const [targetDate, setTargetDate] = useState("");
  const [releaseId, setReleaseId] = useState("");
  const [ownerIds, setOwnerIds] = useState<string[]>([]);
  const [tagsInput, setTagsInput] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);

    const tags = tagsInput
      .split(",")
      .map((t) => t.trim())
      .filter(Boolean);

    const body = {
      title: title.trim(),
      description: description || null,
      horizon,
      effort: effort || null,
      start_date: startDate || null,
      target_date: targetDate || null,
      release_id: releaseId || null,
      owner_ids: ownerIds.length > 0 ? ownerIds : null,
      tags: tags.length > 0 ? tags : null,
    };

    try {
      const res = await apiFetch(`/api/projects/${projectId}/roadmap`, {
        method: "POST",
        body: JSON.stringify(body),
      });
      setLoading(false);
      if (!res.ok) {
        const txt = await res.text();
        setError(txt);
        toast.error("Création impossible", txt || `Erreur ${res.status}`);
        return;
      }
      const created = (await res.json()) as RoadmapItem;
      toast.success("Créé", created.title);
      onCreated(created);
    } catch (err) {
      setLoading(false);
      const msg = err instanceof Error ? err.message : String(err);
      setError(msg);
      toast.error("Création impossible", msg);
    }
  }

  return (
    <form
      onSubmit={submit}
      className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5 space-y-4"
    >
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-medium">Nouvel item de roadmap</h2>
        <button
          type="button"
          onClick={onClose}
          className="p-1 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)]"
        >
          <X size={14} />
        </button>
      </div>

      <div>
        <label className="text-xs font-medium">Titre *</label>
        <input
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          required
          autoFocus
          maxLength={200}
          placeholder="ex: Refonte de l'authentification multi-factor"
          className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
        />
      </div>

      <div>
        <label className="text-xs font-medium">Description</label>
        <textarea
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          rows={3}
          placeholder="Contexte, objectif, périmètre… (markdown supporté)"
          className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm resize-y"
        />
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div>
          <label className="text-xs font-medium">Horizon</label>
          <select
            value={horizon}
            onChange={(e) => setHorizon(e.target.value as RoadmapHorizon)}
            className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-2 py-2 text-sm"
          >
            {HORIZONS.map((h) => (
              <option key={h} value={h}>
                {h.toUpperCase()}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="text-xs font-medium">Effort</label>
          <select
            value={effort}
            onChange={(e) => setEffort(e.target.value as RoadmapEffort | "")}
            className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-2 py-2 text-sm"
          >
            <option value="">—</option>
            {EFFORTS.map((e) => (
              <option key={e} value={e}>
                {e}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="text-xs font-medium">
            Début <span className="text-[var(--muted)] text-[10px]">(opt.)</span>
          </label>
          <input
            type="date"
            value={startDate}
            onChange={(e) => setStartDate(e.target.value)}
            className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-2 py-2 text-sm"
          />
        </div>
        <div>
          <label className="text-xs font-medium">
            Échéance <span className="text-[var(--muted)] text-[10px]">(opt.)</span>
          </label>
          <input
            type="date"
            value={targetDate}
            onChange={(e) => setTargetDate(e.target.value)}
            min={startDate || undefined}
            className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-2 py-2 text-sm"
          />
        </div>
        <div>
          <label className="text-xs font-medium">Release</label>
          <select
            value={releaseId}
            onChange={(e) => setReleaseId(e.target.value)}
            className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-2 py-2 text-sm"
          >
            <option value="">—</option>
            {releases.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label className="text-xs font-medium">
            Owners <span className="text-[var(--muted)] text-[10px]">(plusieurs possibles)</span>
          </label>
          <OwnersPicker
            members={members}
            selected={ownerIds}
            onChange={setOwnerIds}
          />
        </div>
        <div>
          <label className="text-xs font-medium">
            Tags <span className="text-[var(--muted)]">(séparés par virgule)</span>
          </label>
          <input
            value={tagsInput}
            onChange={(e) => setTagsInput(e.target.value)}
            placeholder="auth, infra, tech-debt"
            className="w-full mt-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
          />
        </div>
      </div>

      {error && (
        <p className="text-xs text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded px-3 py-2">
          {error}
        </p>
      )}

      <div className="flex gap-2 pt-1">
        <button
          type="submit"
          disabled={loading || !title.trim()}
          className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3.5 py-2 text-sm font-medium disabled:opacity-50"
        >
          {loading && <Loader2 size={13} className="animate-spin" />}
          <Plus size={14} />
          Créer
        </button>
        <button
          type="button"
          onClick={onClose}
          className="text-sm text-[var(--muted)] hover:text-[var(--foreground)] px-3 py-2"
        >
          Annuler
        </button>
      </div>
    </form>
  );
}
