"use client";

import { useEffect, useState, useCallback } from "react";
import {
  X,
  Loader2,
  Trash2,
  Plus,
  User as UserIcon,
  Calendar,
  Flag,
  Clock,
  Link2,
  GitBranch,
  Tag,
  MessageSquare,
} from "lucide-react";
import type {
  CardComment,
  CardDetail,
  CardSummary,
  Label as LabelType,
  ProjectMember,
} from "@/lib/types";
import { apiFetch } from "@/lib/api/client";

const PRIORITIES = [
  { value: "", label: "—" },
  { value: "low", label: "Faible" },
  { value: "medium", label: "Moyenne" },
  { value: "high", label: "Haute" },
  { value: "urgent", label: "Urgente" },
];

export default function CardDrawer({
  cardId,
  projectId,
  members,
  labels,
  firstColumnId,
  onClose,
  onUpdated,
  onDeleted,
  onLabelsChanged,
}: {
  cardId: string;
  projectId: string;
  members: ProjectMember[];
  labels: LabelType[];
  firstColumnId: string | null;
  onClose: () => void;
  onUpdated: (card: CardSummary) => void;
  onDeleted: () => void;
  onLabelsChanged: (labels: LabelType[]) => void;
}) {
  const [detail, setDetail] = useState<CardDetail | null>(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    const res = await apiFetch(`/api/cards/${cardId}`);
    if (res.ok) setDetail((await res.json()) as CardDetail);
  }, [cardId]);

  useEffect(() => {
    load();
  }, [load]);

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
    // Optimistic update — reflect change instantly
    setDetail({ ...detail, ...body } as CardDetail);
    setSaving(true);
    const res = await apiFetch(`/api/cards/${cardId}`, {
      method: "PATCH",
      body: JSON.stringify(body),
    });
    setSaving(false);
    if (res.ok) {
      const updated = (await res.json()) as CardSummary;
      setDetail((d) => (d ? { ...d, ...updated } : d));
      onUpdated(updated);
    } else {
      // Revert on failure
      setDetail(prev);
    }
  }

  async function handleAttachLabel(labelId: string) {
    const res = await apiFetch(`/api/cards/${cardId}/labels`, {
      method: "POST",
      body: JSON.stringify({ label_id: labelId }),
    });
    if (res.ok) {
      const list = (await res.json()) as LabelType[];
      setDetail((d) => (d ? { ...d, labels: list } : d));
      onUpdated({ ...(detail as CardSummary), labels: list });
    }
  }

  async function handleDetachLabel(labelId: string) {
    const res = await apiFetch(`/api/cards/${cardId}/labels/${labelId}`, {
      method: "DELETE",
    });
    if (res.ok) {
      const next = (detail?.labels ?? []).filter((l) => l.id !== labelId);
      setDetail((d) => (d ? { ...d, labels: next } : d));
      onUpdated({ ...(detail as CardSummary), labels: next });
    }
  }

  async function handleCreateLabel(name: string, color: string) {
    const res = await apiFetch(`/api/projects/${projectId}/labels`, {
      method: "POST",
      body: JSON.stringify({ name, color }),
    });
    if (!res.ok) return;
    const label = (await res.json()) as LabelType;
    onLabelsChanged([...labels, label]);
    await handleAttachLabel(label.id);
  }

  async function handleAddChild(title: string) {
    if (!firstColumnId) return;
    const res = await apiFetch(`/api/columns/${firstColumnId}/cards`, {
      method: "POST",
      body: JSON.stringify({ title, parent_card_id: cardId }),
    });
    if (res.ok) {
      await load();
    }
  }

  async function handleAddDependency(depId: string) {
    const res = await apiFetch(`/api/cards/${cardId}/dependencies`, {
      method: "POST",
      body: JSON.stringify({ depends_on_card_id: depId }),
    });
    if (res.ok) await load();
  }

  async function handleRemoveDependency(depId: string) {
    const res = await apiFetch(
      `/api/cards/${cardId}/dependencies/${depId}`,
      { method: "DELETE" },
    );
    if (res.ok) await load();
  }

  async function handleDelete() {
    if (!confirm("Supprimer cette carte ?")) return;
    const res = await apiFetch(`/api/cards/${cardId}`, { method: "DELETE" });
    if (res.ok) {
      onDeleted();
      onClose();
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

  const availableLabels = labels.filter(
    (l) => !(detail.labels ?? []).some((dl) => dl.id === l.id),
  );

  return (
    <Shell onClose={onClose}>
      <header className="px-6 py-4 border-b border-[var(--border)] flex items-start gap-3">
        <div className="flex-1">
          <EditableTitle
            value={detail.title}
            onSave={(v) => patch({ title: v })}
          />
          {detail.parent && (
            <p className="mt-1 text-xs text-[var(--muted)]">
              Sous-tâche de{" "}
              <span className="font-medium text-[var(--foreground)]">
                {detail.parent.title}
              </span>
            </p>
          )}
        </div>
        <div className="flex items-center gap-1 shrink-0">
          {saving && (
            <Loader2 size={14} className="animate-spin text-[var(--muted)]" />
          )}
          <button
            onClick={handleDelete}
            title="Supprimer la carte"
            className="p-1.5 rounded text-[var(--muted)] hover:text-[var(--color-danger)]"
          >
            <Trash2 size={14} />
          </button>
          <button
            onClick={onClose}
            className="p-1.5 rounded hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
            aria-label="Fermer"
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
            <FieldRow icon={UserIcon} label="Assigné">
              <select
                value={detail.assigned_to ?? ""}
                onChange={(e) =>
                  patch({ assigned_to: e.target.value || null })
                }
                className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
              >
                <option value="">Personne</option>
                {members.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.email}
                  </option>
                ))}
              </select>
            </FieldRow>

            <FieldRow icon={Calendar} label="Échéance">
              <input
                type="date"
                value={detail.due_date ? detail.due_date.slice(0, 10) : ""}
                onChange={(e) => patch({ due_date: e.target.value || null })}
                className="rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
              />
            </FieldRow>

            <FieldRow icon={Flag} label="Priorité">
              <select
                value={detail.priority ?? ""}
                onChange={(e) => patch({ priority: e.target.value || null })}
                className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
              >
                {PRIORITIES.map((p) => (
                  <option key={p.value} value={p.value}>
                    {p.label}
                  </option>
                ))}
              </select>
            </FieldRow>

            <FieldRow icon={Clock} label="Estimate">
              <DebouncedText
                value={detail.estimate ?? ""}
                placeholder="ex: 2h, 0.5j, 1 semaine"
                onSave={(v) => patch({ estimate: v || null })}
              />
            </FieldRow>
          </dl>
        </Section>

        <Section title="Labels" icon={Tag}>
          <div className="flex flex-wrap gap-1.5">
            {(detail.labels ?? []).map((l) => (
              <span
                key={l.id}
                className="inline-flex items-center gap-1.5 text-[11px] uppercase tracking-wider rounded px-2 py-0.5 text-white"
                style={{ background: l.color }}
              >
                {l.name}
                <button
                  onClick={() => handleDetachLabel(l.id)}
                  className="opacity-70 hover:opacity-100"
                  aria-label={`Retirer ${l.name}`}
                >
                  <X size={10} strokeWidth={3} />
                </button>
              </span>
            ))}
            <LabelPicker
              available={availableLabels}
              onAttach={handleAttachLabel}
              onCreate={handleCreateLabel}
            />
          </div>
        </Section>

        <Section title={`Sous-tâches (${detail.children.length})`} icon={GitBranch}>
          <ul className="space-y-1.5 mb-3">
            {detail.children.map((c) => (
              <li
                key={c.id}
                className="flex items-center gap-2 text-sm rounded border border-[var(--border)] px-3 py-1.5"
              >
                <span className="flex-1 truncate">{c.title}</span>
                {c.assignee && (
                  <span className="size-5 rounded-full bg-[var(--color-neutral-300)] dark:bg-[var(--color-neutral-600)] flex items-center justify-center text-[10px]">
                    {c.assignee.email.charAt(0).toUpperCase()}
                  </span>
                )}
              </li>
            ))}
          </ul>
          <AddInline
            placeholder="Ajouter une sous-tâche…"
            onSubmit={handleAddChild}
            disabled={!firstColumnId}
          />
        </Section>

        <Section
          title={`Bloquée par (${detail.dependencies.length})`}
          icon={Link2}
        >
          <ul className="space-y-1.5 mb-3">
            {detail.dependencies.map((d) => (
              <li
                key={d.id}
                className="flex items-center gap-2 text-sm rounded border border-[var(--border)] px-3 py-1.5"
              >
                <span className="flex-1 truncate">{d.title}</span>
                <button
                  onClick={() => handleRemoveDependency(d.id)}
                  className="p-1 rounded text-[var(--muted)] hover:text-[var(--color-danger)]"
                  aria-label="Retirer la dépendance"
                >
                  <X size={12} />
                </button>
              </li>
            ))}
          </ul>
          <DependencyPicker
            projectId={projectId}
            excludeIds={[cardId, ...detail.dependencies.map((d) => d.id)]}
            onSelect={handleAddDependency}
          />
        </Section>

        {detail.dependents.length > 0 && (
          <Section
            title={`Bloque (${detail.dependents.length})`}
            icon={Link2}
          >
            <ul className="space-y-1.5">
              {detail.dependents.map((d) => (
                <li
                  key={d.id}
                  className="text-sm rounded border border-[var(--border)] px-3 py-1.5 truncate"
                >
                  {d.title}
                </li>
              ))}
            </ul>
          </Section>
        )}

        <CommentsSection
          cardId={detail.id}
          onCountChange={(n) => {
            setDetail((d) => (d ? { ...d, comments_count: n } : d));
            onUpdated({ ...(detail as CardSummary), comments_count: n });
          }}
        />
      </div>
    </Shell>
  );
}

function CommentsSection({
  cardId,
  onCountChange,
}: {
  cardId: string;
  onCountChange: (n: number) => void;
}) {
  const [comments, setComments] = useState<CardComment[] | null>(null);
  const [content, setContent] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    (async () => {
      const res = await apiFetch(`/api/cards/${cardId}/comments`);
      if (res.ok) {
        const list = (await res.json()) as CardComment[];
        setComments(list);
        onCountChange(list.length);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cardId]);

  async function addComment(e: React.FormEvent) {
    e.preventDefault();
    if (!content.trim()) return;
    setLoading(true);
    const res = await apiFetch(`/api/cards/${cardId}/comments`, {
      method: "POST",
      body: JSON.stringify({ content: content.trim() }),
    });
    setLoading(false);
    if (!res.ok) return;
    const c = (await res.json()) as CardComment;
    const next = [...(comments ?? []), c];
    setComments(next);
    onCountChange(next.length);
    setContent("");
  }

  async function deleteComment(id: string) {
    if (!confirm("Supprimer ce commentaire ?")) return;
    const res = await apiFetch(`/api/comments/${id}`, { method: "DELETE" });
    if (!res.ok) return;
    const next = (comments ?? []).filter((c) => c.id !== id);
    setComments(next);
    onCountChange(next.length);
  }

  return (
    <section>
      <h3 className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-[var(--muted)] mb-3">
        <MessageSquare size={12} strokeWidth={2} />
        Commentaires {comments && `(${comments.length})`}
      </h3>

      {comments === null ? (
        <p className="text-xs text-[var(--muted)]">Chargement…</p>
      ) : (
        <ul className="space-y-3 mb-3">
          {comments.map((c) => (
            <li
              key={c.id}
              className="rounded-md border border-[var(--border)] bg-[var(--surface)] p-3 group"
            >
              <div className="flex items-center gap-2 mb-1">
                <span className="size-5 rounded-full bg-[var(--color-neutral-300)] dark:bg-[var(--color-neutral-600)] flex items-center justify-center text-[10px] font-medium shrink-0">
                  {c.user?.email.charAt(0).toUpperCase() ?? "?"}
                </span>
                <span className="text-xs font-medium">
                  {c.user?.email ?? "—"}
                </span>
                <span className="text-[11px] text-[var(--muted)]">
                  {new Date(c.created_at).toLocaleString("fr-FR", {
                    day: "numeric",
                    month: "short",
                    hour: "2-digit",
                    minute: "2-digit",
                  })}
                </span>
                <button
                  onClick={() => deleteComment(c.id)}
                  className="ml-auto opacity-0 group-hover:opacity-100 p-1 text-[var(--muted)] hover:text-[var(--color-danger)]"
                  title="Supprimer"
                >
                  <Trash2 size={11} />
                </button>
              </div>
              <p className="text-sm whitespace-pre-wrap">{c.content}</p>
            </li>
          ))}
        </ul>
      )}

      <form onSubmit={addComment} className="space-y-2">
        <textarea
          value={content}
          onChange={(e) => setContent(e.target.value)}
          rows={2}
          placeholder="Écrire un commentaire…"
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm resize-y"
          onKeyDown={(e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === "Enter") addComment(e);
          }}
        />
        <button
          type="submit"
          disabled={loading || !content.trim()}
          className="inline-flex items-center gap-1 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1.5 text-xs font-medium disabled:opacity-50"
        >
          {loading && <Loader2 size={11} className="animate-spin" />}
          Commenter
        </button>
      </form>
    </section>
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
  icon: Icon,
  children,
}: {
  title: string;
  icon?: React.ComponentType<{ size?: number; strokeWidth?: number; className?: string }>;
  children: React.ReactNode;
}) {
  return (
    <section>
      <h3 className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-[var(--muted)] mb-2">
        {Icon && <Icon size={12} strokeWidth={2} />}
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
  icon: React.ComponentType<{ size?: number; strokeWidth?: number; className?: string }>;
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
      placeholder="Ajoute une description (markdown supporté bientôt)…"
      className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm resize-y min-h-20"
    />
  );
}

function DebouncedText({
  value,
  placeholder,
  onSave,
}: {
  value: string;
  placeholder?: string;
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
      }}
      placeholder={placeholder}
      className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
    />
  );
}

function AddInline({
  placeholder,
  onSubmit,
  disabled,
}: {
  placeholder: string;
  onSubmit: (v: string) => void;
  disabled?: boolean;
}) {
  const [v, setV] = useState("");

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!v.trim()) return;
    onSubmit(v.trim());
    setV("");
  }

  return (
    <form onSubmit={submit} className="flex gap-2">
      <input
        value={v}
        onChange={(e) => setV(e.target.value)}
        placeholder={placeholder}
        disabled={disabled}
        className="flex-1 rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1.5 text-sm"
      />
      <button
        type="submit"
        disabled={disabled || !v.trim()}
        className="rounded bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1.5 text-xs font-medium disabled:opacity-50"
      >
        <Plus size={12} />
      </button>
    </form>
  );
}

function LabelPicker({
  available,
  onAttach,
  onCreate,
}: {
  available: LabelType[];
  onAttach: (id: string) => void;
  onCreate: (name: string, color: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState("");
  const [color, setColor] = useState("#d9342b");

  const PALETTE = [
    "#d9342b",
    "#f5b800",
    "#2563eb",
    "#16a34a",
    "#9333ea",
    "#0891b2",
    "#737375",
  ];

  return (
    <div className="relative">
      <button
        onClick={() => setOpen((v) => !v)}
        className="inline-flex items-center gap-1 text-[11px] rounded border border-dashed border-[var(--border)] px-2 py-0.5 text-[var(--muted)] hover:text-[var(--foreground)]"
      >
        <Plus size={10} />
        Label
      </button>

      {open && (
        <div className="absolute z-10 top-full mt-1.5 left-0 w-64 rounded-md border border-[var(--border)] bg-[var(--background)] shadow-lg p-2 space-y-1.5">
          {available.length > 0 && !creating && (
            <ul className="max-h-40 overflow-y-auto">
              {available.map((l) => (
                <li key={l.id}>
                  <button
                    onClick={() => {
                      onAttach(l.id);
                      setOpen(false);
                    }}
                    className="w-full flex items-center gap-2 px-2 py-1 rounded text-sm hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
                  >
                    <span
                      className="size-3 rounded shrink-0"
                      style={{ background: l.color }}
                    />
                    <span className="truncate">{l.name}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}

          {!creating && (
            <button
              onClick={() => setCreating(true)}
              className="w-full flex items-center gap-1.5 px-2 py-1.5 rounded text-xs text-[var(--muted)] hover:text-[var(--foreground)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)] border border-dashed border-[var(--border)]"
            >
              <Plus size={11} />
              Nouveau label
            </button>
          )}

          {creating && (
            <div className="space-y-2">
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                autoFocus
                maxLength={60}
                placeholder="Nom"
                className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
              />
              <div className="flex gap-1">
                {PALETTE.map((c) => (
                  <button
                    key={c}
                    onClick={() => setColor(c)}
                    className={`size-5 rounded ${
                      color === c
                        ? "ring-2 ring-offset-1 ring-[var(--foreground)] ring-offset-[var(--background)]"
                        : ""
                    }`}
                    style={{ background: c }}
                  />
                ))}
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => {
                    if (!name.trim()) return;
                    onCreate(name.trim(), color);
                    setName("");
                    setCreating(false);
                    setOpen(false);
                  }}
                  className="flex-1 rounded bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-2 py-1 text-xs"
                >
                  Créer & ajouter
                </button>
                <button
                  onClick={() => setCreating(false)}
                  className="text-xs text-[var(--muted)] px-2"
                >
                  Annuler
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function DependencyPicker({
  projectId,
  excludeIds,
  onSelect,
}: {
  projectId: string;
  excludeIds: string[];
  onSelect: (id: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [options, setOptions] = useState<
    Array<{ id: string; title: string }>
  >([]);
  const [q, setQ] = useState("");

  useEffect(() => {
    if (!open) return;
    (async () => {
      const res = await apiFetch(`/api/projects/${projectId}/columns`);
      if (!res.ok) return;
      const cols = (await res.json()) as Array<{
        cards: Array<{ id: string; title: string }>;
      }>;
      const all = cols.flatMap((c) => c.cards);
      setOptions(all.filter((c) => !excludeIds.includes(c.id)));
    })();
  }, [open, projectId, excludeIds]);

  const filtered = options.filter((o) =>
    o.title.toLowerCase().includes(q.toLowerCase()),
  );

  return (
    <div className="relative">
      <button
        onClick={() => setOpen((v) => !v)}
        className="inline-flex items-center gap-1.5 text-xs rounded border border-dashed border-[var(--border)] px-3 py-1.5 text-[var(--muted)] hover:text-[var(--foreground)]"
      >
        <Plus size={12} />
        Ajouter une dépendance
      </button>
      {open && (
        <div className="absolute z-10 top-full mt-1.5 left-0 w-80 rounded-md border border-[var(--border)] bg-[var(--background)] shadow-lg p-2 space-y-1.5">
          <input
            autoFocus
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Rechercher une carte…"
            className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
          />
          <ul className="max-h-48 overflow-y-auto">
            {filtered.length === 0 && (
              <li className="text-xs text-[var(--muted)] px-2 py-1.5">
                Aucune carte correspondante.
              </li>
            )}
            {filtered.map((c) => (
              <li key={c.id}>
                <button
                  onClick={() => {
                    onSelect(c.id);
                    setOpen(false);
                    setQ("");
                  }}
                  className="w-full text-left px-2 py-1.5 rounded text-sm hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)] truncate"
                >
                  {c.title}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
