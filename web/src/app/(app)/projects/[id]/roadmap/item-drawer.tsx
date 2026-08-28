"use client";

import { useEffect, useState } from "react";
import {
  X,
  Loader2,
  Trash2,
  CalendarDays,
  Hash,
  User as UserIcon,
  Flag,
  Tag,
} from "lucide-react";
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
import DateInput from "./date-input";

const HORIZONS: RoadmapHorizon[] = ["now", "next", "later", "done"];
const EFFORTS: RoadmapEffort[] = ["S", "M", "L", "XL"];

export default function ItemDrawer({
  itemId,
  projectId,
  members,
  releases,
  onClose,
  onUpdated,
  onDeleted,
}: {
  itemId: string;
  projectId: string;
  members: ProjectMember[];
  releases: Release[];
  onClose: () => void;
  onUpdated: (i: RoadmapItem) => void;
  onDeleted: () => void;
}) {
  const toast = useToast();
  const [detail, setDetail] = useState<RoadmapItem | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    (async () => {
      const res = await apiFetch(`/api/roadmap-items/${itemId}`);
      if (res.ok) setDetail((await res.json()) as RoadmapItem);
    })();
  }, [itemId]);

  useEffect(() => {
    function onEsc(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onEsc);
    return () => window.removeEventListener("keydown", onEsc);
  }, [onClose]);

  async function patch(body: Record<string, unknown>) {
    if (!detail) return;
    const prev = detail;
    setDetail({ ...detail, ...body } as RoadmapItem);
    setSaving(true);
    try {
      const res = await apiFetch(`/api/roadmap-items/${itemId}`, {
        method: "PATCH",
        body: JSON.stringify(body),
      });
      setSaving(false);
      if (res.ok) {
        const updated = (await res.json()) as RoadmapItem;
        setDetail(updated);
        onUpdated(updated);
      } else {
        setDetail(prev);
        toast.error(
          "Mise à jour impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
      }
    } catch (err) {
      setSaving(false);
      setDetail(prev);
      toast.error(
        "Mise à jour impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  async function handleDelete() {
    const title = detail?.title;
    if (!confirm(`Supprimer l'item "${title}" ?`)) return;
    try {
      const res = await apiFetch(`/api/roadmap-items/${itemId}`, {
        method: "DELETE",
      });
      if (res.ok) {
        toast.success("Supprimé", title);
        onDeleted();
      } else {
        toast.error(
          "Suppression impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
      }
    } catch (err) {
      toast.error(
        "Suppression impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  if (!detail) {
    return (
      <Shell onClose={onClose}>
        <div className="flex items-center justify-center h-64 text-[var(--muted)]">
          <Loader2 size={20} className="animate-spin" />
        </div>
      </Shell>
    );
  }

  return (
    <Shell onClose={onClose}>
      <header className="px-6 py-4 border-b border-[var(--border)] flex items-start gap-3">
        <div className="flex-1">
          <EditableTitle value={detail.title} onSave={(v) => patch({ title: v })} />
        </div>
        <div className="flex items-center gap-1 shrink-0">
          {saving && <Loader2 size={14} className="animate-spin text-[var(--muted)]" />}
          <button
            onClick={handleDelete}
            title="Supprimer"
            className="p-1.5 rounded text-[var(--muted)] hover:text-[var(--color-danger)]"
          >
            <Trash2 size={14} />
          </button>
          <button
            onClick={onClose}
            className="p-1.5 rounded hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
          >
            <X size={16} />
          </button>
        </div>
      </header>

      <div className="flex-1 overflow-y-auto px-6 py-5 space-y-6">
        <Section title="Description">
          <EditableDescription
            value={detail.description ?? ""}
            onSave={(v) => patch({ description: v || null })}
          />
        </Section>

        <Section title="Détails">
          <dl className="grid grid-cols-[8rem_1fr] gap-y-2.5 text-sm">
            <FieldRow icon={Flag} label="Horizon">
              <select
                value={detail.horizon}
                onChange={(e) => patch({ horizon: e.target.value })}
                className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
              >
                {HORIZONS.map((h) => (
                  <option key={h} value={h}>
                    {h.toUpperCase()}
                  </option>
                ))}
              </select>
            </FieldRow>

            <FieldRow icon={Hash} label="Release">
              <select
                value={detail.release_id ?? ""}
                onChange={(e) => patch({ release_id: e.target.value || null })}
                className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
              >
                <option value="">—</option>
                {releases.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.name}
                  </option>
                ))}
              </select>
            </FieldRow>

            <FieldRow icon={UserIcon} label="Owners">
              <OwnersPicker
                members={members}
                selected={(detail.owners ?? []).map((o) => o.id)}
                onChange={(next) => patch({ owner_ids: next, owners: members.filter((m) => next.includes(m.id)) })}
              />
            </FieldRow>

            <FieldRow icon={CalendarDays} label="Début">
              <DateInput
                value={detail.start_date}
                onCommit={(next) => patch({ start_date: next })}
                ariaLabel="Date de début"
              />
            </FieldRow>

            <FieldRow icon={CalendarDays} label="Échéance">
              <DateInput
                value={detail.target_date}
                onCommit={(next) => patch({ target_date: next })}
                min={detail.start_date ? detail.start_date.slice(0, 10) : undefined}
                ariaLabel="Date d'échéance"
              />
            </FieldRow>

            <FieldRow icon={Flag} label="Effort">
              <select
                value={detail.effort ?? ""}
                onChange={(e) => patch({ effort: e.target.value || null })}
                className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
              >
                <option value="">—</option>
                {EFFORTS.map((e) => (
                  <option key={e} value={e}>
                    {e}
                  </option>
                ))}
              </select>
            </FieldRow>

            <FieldRow icon={Tag} label="Tags">
              <TagsEditor
                value={detail.tags ?? []}
                onSave={(v) => patch({ tags: v.length > 0 ? v : null })}
              />
            </FieldRow>
          </dl>
        </Section>
      </div>
    </Shell>
  );
}

function Shell({
  children,
  onClose,
}: {
  children: React.ReactNode;
  onClose: () => void;
}) {
  return (
    <div className="fixed inset-0 z-40 flex">
      <div
        className="flex-1 bg-black/20 backdrop-blur-[1px]"
        onClick={onClose}
      />
      <aside className="w-full max-w-xl bg-[var(--background)] border-l border-[var(--border)] flex flex-col shadow-2xl">
        {children}
      </aside>
    </div>
  );
}

function Section({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section>
      <h3 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)] mb-2">
        {title}
      </h3>
      {children}
    </section>
  );
}

function FieldRow({
  icon: Icon,
  label,
  children,
}: {
  icon: React.ComponentType<{ size?: number; strokeWidth?: number }>;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <>
      <dt className="flex items-center gap-1.5 text-[var(--muted)] text-xs self-center">
        <Icon size={12} strokeWidth={2} />
        {label}
      </dt>
      <dd>{children}</dd>
    </>
  );
}

function EditableTitle({
  value,
  onSave,
}: {
  value: string;
  onSave: (v: string) => void;
}) {
  const [v, setV] = useState(value);
  useEffect(() => setV(value), [value]);
  return (
    <input
      value={v}
      onChange={(e) => setV(e.target.value)}
      onBlur={() => v !== value && onSave(v)}
      onKeyDown={(e) => {
        if (e.key === "Enter") (e.target as HTMLInputElement).blur();
        if (e.key === "Escape") setV(value);
      }}
      className="w-full text-lg font-semibold tracking-tight bg-transparent border-0 outline-none focus:bg-[var(--surface)] rounded px-1 -mx-1"
    />
  );
}

function EditableDescription({
  value,
  onSave,
}: {
  value: string;
  onSave: (v: string) => void;
}) {
  const [v, setV] = useState(value);
  useEffect(() => setV(value), [value]);
  return (
    <textarea
      value={v}
      onChange={(e) => setV(e.target.value)}
      onBlur={() => v !== value && onSave(v)}
      rows={4}
      placeholder="Décris l'initiative — contexte, motivations, périmètre…"
      className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm resize-y min-h-20"
    />
  );
}

function TagsEditor({
  value,
  onSave,
}: {
  value: string[];
  onSave: (v: string[]) => void;
}) {
  const [input, setInput] = useState(value.join(", "));
  useEffect(() => setInput(value.join(", ")), [value]);
  return (
    <input
      value={input}
      onChange={(e) => setInput(e.target.value)}
      onBlur={() => {
        const tags = input
          .split(",")
          .map((t) => t.trim())
          .filter(Boolean);
        if (tags.join(",") !== value.join(",")) onSave(tags);
      }}
      placeholder="auth, infra, tech-debt"
      className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
    />
  );
}
