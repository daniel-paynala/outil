"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Plus, ScrollText, Loader2 } from "lucide-react";
import { clsx } from "clsx";
import type { Decision, DecisionStatus } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";

const STATUS_LABELS: Record<DecisionStatus, string> = {
  proposed: "Proposée",
  accepted: "Acceptée",
  deprecated: "Dépréciée",
  superseded: "Remplacée",
};

export default function AdrListClient({
  projectId,
  initialDecisions,
}: {
  projectId: string;
  initialDecisions: Decision[];
}) {
  const router = useRouter();
  const toast = useToast();
  const [decisions] = useState<Decision[]>(initialDecisions);
  const [creating, setCreating] = useState(false);
  const [title, setTitle] = useState("");
  const [loading, setLoading] = useState(false);

  async function create() {
    if (!title.trim()) return;
    setLoading(true);
    try {
      const res = await apiFetch(`/api/projects/${projectId}/decisions`, {
        method: "POST",
        body: JSON.stringify({
          title: title.trim(),
          status: "proposed",
        }),
      });
      setLoading(false);
      if (!res.ok) {
        toast.error(
          "Création impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      const created = (await res.json()) as Decision;
      toast.success("Créée", created.title);
      router.push(`/projects/${projectId}/adr/${created.id}`);
      router.refresh();
    } catch (err) {
      setLoading(false);
      toast.error(
        "Création impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  return (
    <div className="space-y-6">
      <header className="flex items-start justify-between gap-4">
        <div>
          <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
            Journal des décisions
          </p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight flex items-center gap-2">
            <ScrollText size={22} strokeWidth={1.75} />
            Décisions
          </h1>
          <p className="mt-2 text-sm text-[var(--muted)] max-w-2xl">
            Trace les choix structurants et leurs raisons. Chaque décision est
            numérotée, datée et garde son historique.
          </p>
        </div>
        {!creating && (
          <button
            onClick={() => setCreating(true)}
            className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1.5 text-sm font-medium"
          >
            <Plus size={14} />
            Nouvelle décision
          </button>
        )}
      </header>

      {creating && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            create();
          }}
          className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-4 flex items-center gap-2"
        >
          <input
            autoFocus
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            onKeyDown={(e) => e.key === "Escape" && setCreating(false)}
            placeholder="Titre de la décision (ex: Utiliser PostgreSQL plutôt que MySQL)"
            className="flex-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
          />
          <button
            type="submit"
            disabled={loading || !title.trim()}
            className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-2 text-sm font-medium disabled:opacity-50"
          >
            {loading && <Loader2 size={13} className="animate-spin" />}
            Créer
          </button>
          <button
            type="button"
            onClick={() => setCreating(false)}
            className="text-xs text-[var(--muted)] hover:text-[var(--foreground)] px-2"
          >
            Annuler
          </button>
        </form>
      )}

      {decisions.length === 0 && !creating ? (
        <div className="border border-dashed border-[var(--border)] rounded-lg py-16 text-center">
          <ScrollText
            size={22}
            strokeWidth={1.5}
            className="mx-auto text-[var(--muted)]"
          />
          <p className="mt-3 text-sm text-[var(--muted)]">
            Aucune décision enregistrée.
          </p>
        </div>
      ) : (
        <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
          {decisions.map((d) => (
            <li key={d.id}>
              <Link
                href={`/projects/${projectId}/adr/${d.id}`}
                className="flex items-center gap-3 px-4 py-3 hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50"
              >
                <span className="text-xs font-mono text-[var(--muted)] w-12 shrink-0">
                  ADR-{String(d.number).padStart(3, "0")}
                </span>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium truncate">{d.title}</p>
                  <p className="text-xs text-[var(--muted)] truncate mt-0.5">
                    {d.decided_at
                      ? `Décidée le ${new Date(d.decided_at).toLocaleDateString("fr-FR")}`
                      : `Modifiée ${new Date(d.updated_at).toLocaleDateString("fr-FR")}`}
                    {d.creator && <> · {d.creator.email}</>}
                  </p>
                </div>
                <StatusPill status={d.status} />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function StatusPill({ status }: { status: DecisionStatus }) {
  const cls = clsx(
    "text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 shrink-0",
    status === "accepted" &&
      "bg-[var(--color-success)]/10 text-[var(--color-success)]",
    status === "proposed" &&
      "bg-[var(--color-info)]/10 text-[var(--color-info)]",
    status === "deprecated" &&
      "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]",
    status === "superseded" &&
      "bg-[var(--color-warning)]/10 text-[var(--color-warning)]",
  );
  return <span className={cls}>{STATUS_LABELS[status]}</span>;
}
