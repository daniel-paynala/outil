"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Save, Trash2, AlertTriangle } from "lucide-react";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import type { Project } from "@/lib/types";

const PRESET_COLORS = [
  "#737375", "#dc2626", "#ea580c", "#d97706", "#ca8a04",
  "#65a30d", "#16a34a", "#0d9488", "#0891b2", "#2563eb",
  "#4f46e5", "#7c3aed", "#c026d3", "#db2777",
];

export default function SettingsClient({
  project,
  isOwner,
  canDelete,
}: {
  project: Project;
  isOwner: boolean;
  canDelete: boolean;
}) {
  const router = useRouter();
  const toast = useToast();

  const [name, setName] = useState(project.name);
  const [description, setDescription] = useState(project.description ?? "");
  const [color, setColor] = useState(project.color ?? "#737375");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [deleting, setDeleting] = useState(false);
  const [deleteConfirm, setDeleteConfirm] = useState("");

  const dirty =
    name !== project.name ||
    description !== (project.description ?? "") ||
    color !== (project.color ?? "#737375");

  async function save(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (!name.trim()) {
      setError("Le nom est requis.");
      return;
    }
    setSaving(true);
    try {
      const res = await apiFetch(`/api/projects/${project.id}`, {
        method: "PATCH",
        body: JSON.stringify({
          name: name.trim(),
          description: description.trim() || null,
          color,
        }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }
      toast.success("Paramètres enregistrés", project.name);
      router.refresh();
    } catch (e) {
      const msg = e instanceof Error ? e.message : "Erreur inconnue";
      setError(msg);
      toast.error("Enregistrement impossible", msg);
    } finally {
      setSaving(false);
    }
  }

  async function deleteProject() {
    if (deleteConfirm !== project.name) {
      toast.error(
        "Confirmation incorrecte",
        "Tape exactement le nom du projet pour confirmer.",
      );
      return;
    }
    setDeleting(true);
    try {
      const res = await apiFetch(`/api/projects/${project.id}`, {
        method: "DELETE",
      });
      if (!res.ok && res.status !== 204) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }
      toast.success("Projet supprimé", project.name);
      router.replace("/projects");
      router.refresh();
    } catch (e) {
      const msg = e instanceof Error ? e.message : "Erreur inconnue";
      toast.error("Suppression impossible", msg);
      setDeleting(false);
    }
  }

  if (!isOwner) {
    return (
      <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-6 text-center">
        <p className="text-sm text-[var(--muted)]">
          Seul un owner du projet peut modifier les paramètres.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-2xl">
      {/* General settings */}
      <form
        onSubmit={save}
        className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-6 space-y-5"
      >
        <h2 className="text-sm font-medium">Informations générales</h2>

        <div className="space-y-1.5">
          <label htmlFor="name" className="text-xs font-medium">
            Nom du projet
          </label>
          <input
            id="name"
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            maxLength={120}
            required
            className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
          />
        </div>

        <div className="space-y-1.5">
          <label htmlFor="description" className="text-xs font-medium">
            Description
          </label>
          <textarea
            id="description"
            rows={3}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            maxLength={2000}
            placeholder="À quoi sert ce projet ?"
            className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)] resize-none"
          />
        </div>

        <div className="space-y-2">
          <label className="text-xs font-medium">Couleur</label>
          <div className="flex flex-wrap gap-2">
            {PRESET_COLORS.map((c) => (
              <button
                key={c}
                type="button"
                onClick={() => setColor(c)}
                className={`size-7 rounded-md border-2 transition-transform ${
                  color === c
                    ? "border-[var(--foreground)] scale-110"
                    : "border-transparent hover:scale-105"
                }`}
                style={{ background: c }}
                aria-label={c}
                title={c}
              />
            ))}
            <input
              type="color"
              value={color}
              onChange={(e) => setColor(e.target.value)}
              className="size-7 rounded-md border border-[var(--border)] bg-transparent cursor-pointer"
              title="Couleur personnalisée"
            />
          </div>
        </div>

        <div className="space-y-1">
          <p className="text-xs text-[var(--muted)]">Slug</p>
          <p className="text-xs font-mono text-[var(--muted)]">{project.slug}</p>
        </div>

        {error && (
          <div className="rounded-md border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 px-3 py-2 text-xs text-[var(--color-danger)]">
            {error}
          </div>
        )}

        <div className="flex justify-end pt-2 border-t border-[var(--border)]">
          <button
            type="submit"
            disabled={!dirty || saving}
            className="inline-flex items-center gap-1.5 text-sm px-4 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white disabled:opacity-30 disabled:cursor-not-allowed hover:opacity-90"
          >
            <Save className="w-4 h-4" />
            {saving ? "Enregistrement…" : "Enregistrer"}
          </button>
        </div>
      </form>

      {/* Danger zone — visible uniquement pour admin plateforme OU créateur du projet */}
      {canDelete && (
      <div className="rounded-lg border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 p-6 space-y-4">
        <div className="flex items-start gap-3">
          <AlertTriangle className="w-5 h-5 text-[var(--color-danger)] mt-0.5 shrink-0" />
          <div>
            <h2 className="text-sm font-medium text-[var(--color-danger)]">
              Zone dangereuse
            </h2>
            <p className="text-xs text-[var(--muted)] mt-1">
              Supprimer le projet est définitif. Toutes les tâches, documents,
              décisions, secrets et entrées de temps seront archivés. Cette
              action ne peut pas être annulée depuis l&apos;UI.
            </p>
          </div>
        </div>

        <div className="space-y-2 pl-8">
          <label className="text-xs font-medium block">
            Tape{" "}
            <code className="px-1 py-0.5 rounded bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)] font-mono">
              {project.name}
            </code>{" "}
            pour confirmer
          </label>
          <input
            type="text"
            value={deleteConfirm}
            onChange={(e) => setDeleteConfirm(e.target.value)}
            placeholder={project.name}
            className="w-full max-w-sm bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-danger)]/50"
          />
          <button
            onClick={deleteProject}
            disabled={deleteConfirm !== project.name || deleting}
            type="button"
            className="inline-flex items-center gap-1.5 text-sm px-4 py-1.5 rounded-md bg-[var(--color-danger)] text-white disabled:opacity-30 disabled:cursor-not-allowed hover:opacity-90"
          >
            <Trash2 className="w-4 h-4" />
            {deleting ? "Suppression…" : "Supprimer définitivement"}
          </button>
        </div>
      </div>
      )}
    </div>
  );
}
