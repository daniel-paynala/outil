"use client";

import { useState, useCallback, useEffect } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import {
  ChevronLeft,
  Trash2,
  Check,
  Loader2,
  Pencil,
  Eye,
} from "lucide-react";
import { clsx } from "clsx";
import type { Decision, DecisionStatus } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import { StatusPill } from "../adr-list-client";

const STATUS_OPTIONS: { value: DecisionStatus; label: string }[] = [
  { value: "proposed", label: "Proposée" },
  { value: "accepted", label: "Acceptée" },
  { value: "deprecated", label: "Dépréciée" },
  { value: "superseded", label: "Remplacée" },
];

export default function DecisionEditor({
  projectId,
  decision: initialDecision,
}: {
  projectId: string;
  decision: Decision;
}) {
  const router = useRouter();
  const [decision, setDecision] = useState<Decision>(initialDecision);
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savedAt, setSavedAt] = useState<Date | null>(null);

  const [title, setTitle] = useState(initialDecision.title);
  const [status, setStatus] = useState<DecisionStatus>(initialDecision.status);
  const [decidedAt, setDecidedAt] = useState(
    initialDecision.decided_at ? initialDecision.decided_at.slice(0, 10) : "",
  );
  const [context, setContext] = useState(initialDecision.context ?? "");
  const [choice, setChoice] = useState(initialDecision.decision ?? "");
  const [consequences, setConsequences] = useState(
    initialDecision.consequences ?? "",
  );
  const [alternatives, setAlternatives] = useState(
    initialDecision.alternatives ?? "",
  );
  const [references, setReferences] = useState(
    initialDecision.references ?? "",
  );

  const save = useCallback(async () => {
    setSaving(true);
    const res = await apiFetch(`/api/decisions/${decision.id}`, {
      method: "PATCH",
      body: JSON.stringify({
        title,
        status,
        decided_at: decidedAt || null,
        context: context || null,
        decision: choice || null,
        consequences: consequences || null,
        alternatives: alternatives || null,
        references: references || null,
      }),
    });
    setSaving(false);
    if (res.ok) {
      const updated = (await res.json()) as Decision;
      setDecision(updated);
      setSavedAt(new Date());
    }
  }, [
    decision.id,
    title,
    status,
    decidedAt,
    context,
    choice,
    consequences,
    alternatives,
    references,
  ]);

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key === "s") {
        e.preventDefault();
        if (editing) save();
      }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [editing, save]);

  async function handleDelete() {
    if (!confirm(`Supprimer ADR-${decision.number} « ${decision.title} » ?`))
      return;
    const res = await apiFetch(`/api/decisions/${decision.id}`, {
      method: "DELETE",
    });
    if (res.ok) {
      router.push(`/projects/${projectId}/adr`);
      router.refresh();
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2 text-xs text-[var(--muted)]">
        <Link
          href={`/projects/${projectId}/adr`}
          className="inline-flex items-center gap-1 hover:text-[var(--foreground)]"
        >
          <ChevronLeft size={13} />
          Toutes les décisions
        </Link>
      </div>

      <header className="flex items-start gap-3">
        <span className="text-xs font-mono text-[var(--muted)] mt-2">
          ADR-{String(decision.number).padStart(3, "0")}
        </span>
        <div className="flex-1 min-w-0">
          {editing ? (
            <input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className="w-full text-2xl font-semibold tracking-tight bg-transparent border-0 outline-none focus:bg-[var(--surface)] rounded px-1 -mx-1"
            />
          ) : (
            <h1 className="text-2xl font-semibold tracking-tight">
              {decision.title}
            </h1>
          )}
          <div className="mt-2 flex items-center gap-2 text-xs">
            <StatusPill status={decision.status} />
            {decision.decided_at && (
              <span className="text-[var(--muted)]">
                Décidée le{" "}
                {new Date(decision.decided_at).toLocaleDateString("fr-FR", {
                  day: "numeric",
                  month: "long",
                  year: "numeric",
                })}
              </span>
            )}
          </div>
        </div>

        <div className="flex items-center gap-1">
          {saving ? (
            <span className="text-xs text-[var(--muted)] inline-flex items-center gap-1">
              <Loader2 size={12} className="animate-spin" />
              Sauvegarde…
            </span>
          ) : editing && savedAt ? (
            <span className="text-xs text-[var(--color-success)] inline-flex items-center gap-1">
              <Check size={12} />
              Enregistré
            </span>
          ) : null}

          <button
            onClick={() => setEditing((v) => !v)}
            className={clsx(
              "p-1.5 rounded",
              editing
                ? "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)]"
                : "text-[var(--muted)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]",
            )}
            title={editing ? "Terminer l'édition" : "Éditer"}
          >
            {editing ? <Eye size={14} /> : <Pencil size={14} />}
          </button>

          <button
            onClick={handleDelete}
            className="p-1.5 rounded text-[var(--muted)] hover:text-[var(--color-danger)]"
            title="Supprimer"
          >
            <Trash2 size={14} />
          </button>
        </div>
      </header>

      {editing ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <label className="text-xs font-medium">Statut</label>
              <select
                value={status}
                onChange={(e) => setStatus(e.target.value as DecisionStatus)}
                onBlur={save}
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
              >
                {STATUS_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-medium">Date de décision</label>
              <input
                type="date"
                value={decidedAt}
                onChange={(e) => setDecidedAt(e.target.value)}
                onBlur={save}
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
              />
            </div>
          </div>

          <EditableField
            label="Contexte"
            hint="Quel est le problème, la situation, les contraintes ?"
            value={context}
            onChange={setContext}
            onSave={save}
          />
          <EditableField
            label="Décision"
            hint="Qu'avons-nous décidé de faire ?"
            value={choice}
            onChange={setChoice}
            onSave={save}
          />
          <EditableField
            label="Conséquences"
            hint="Quels sont les effets — positifs et négatifs ?"
            value={consequences}
            onChange={setConsequences}
            onSave={save}
          />
          <EditableField
            label="Alternatives considérées"
            hint="Quelles autres options avons-nous évaluées et pourquoi rejetées ?"
            value={alternatives}
            onChange={setAlternatives}
            onSave={save}
          />
          <EditableField
            label="Références"
            hint="Liens vers tickets, PRs, docs, discussions…"
            value={references}
            onChange={setReferences}
            onSave={save}
          />
        </div>
      ) : (
        <div className="space-y-6">
          <Section title="Contexte" content={decision.context} />
          <Section title="Décision" content={decision.decision} />
          <Section title="Conséquences" content={decision.consequences} />
          <Section
            title="Alternatives considérées"
            content={decision.alternatives}
          />
          <Section title="Références" content={decision.references} />
        </div>
      )}
    </div>
  );
}

function EditableField({
  label,
  hint,
  value,
  onChange,
  onSave,
}: {
  label: string;
  hint: string;
  value: string;
  onChange: (v: string) => void;
  onSave: () => void;
}) {
  return (
    <div className="space-y-1.5">
      <label className="text-xs font-medium flex items-center gap-1.5">
        {label}
        <span className="text-[var(--muted)] font-normal">· {hint}</span>
      </label>
      <textarea
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onSave}
        rows={4}
        placeholder="Markdown supporté…"
        className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm font-mono resize-y"
      />
    </div>
  );
}

function Section({ title, content }: { title: string; content: string | null }) {
  if (!content) return null;
  return (
    <section>
      <h2 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)] mb-2">
        {title}
      </h2>
      <div className="prose-doc">
        <ReactMarkdown remarkPlugins={[remarkGfm]}>{content}</ReactMarkdown>
      </div>
    </section>
  );
}
