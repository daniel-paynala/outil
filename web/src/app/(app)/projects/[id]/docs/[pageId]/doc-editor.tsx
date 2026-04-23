"use client";

import { useState, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import {
  Loader2,
  Trash2,
  History,
  Eye,
  Pencil,
  Columns,
  Check,
} from "lucide-react";
import { clsx } from "clsx";
import type { DocPage, DocRevision } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";

type ViewMode = "edit" | "preview" | "split";

export default function DocEditor({
  projectId,
  page,
}: {
  projectId: string;
  page: DocPage;
}) {
  const router = useRouter();

  const [title, setTitle] = useState(page.title);
  const [content, setContent] = useState(page.content ?? "");
  const [view, setView] = useState<ViewMode>("split");
  const [saving, setSaving] = useState(false);
  const [lastSavedAt, setLastSavedAt] = useState<Date | null>(null);
  const [revisionsOpen, setRevisionsOpen] = useState(false);

  // Sync local state if page changes (after restore, etc.)
  useEffect(() => {
    setTitle(page.title);
    setContent(page.content ?? "");
  }, [page.id, page.updated_at, page.title, page.content]);

  const dirty = title !== page.title || content !== (page.content ?? "");

  const save = useCallback(async () => {
    if (!dirty) return;
    setSaving(true);
    const res = await apiFetch(`/api/docs/${page.id}`, {
      method: "PATCH",
      body: JSON.stringify({ title, content }),
    });
    setSaving(false);
    if (res.ok) {
      setLastSavedAt(new Date());
      router.refresh();
    }
  }, [dirty, title, content, page.id, router]);

  // Cmd/Ctrl + S
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key === "s") {
        e.preventDefault();
        save();
      }
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [save]);

  async function handleDelete() {
    if (!confirm(`Supprimer la page "${page.title}" ?`)) return;
    const res = await apiFetch(`/api/docs/${page.id}`, { method: "DELETE" });
    if (res.ok) {
      router.push(`/projects/${projectId}/docs`);
      router.refresh();
    }
  }

  return (
    <div className="flex flex-col h-full min-h-[calc(100vh-16rem)]">
      <header className="flex items-center gap-2 pb-3 border-b border-[var(--border)]">
        <input
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          onBlur={save}
          className="flex-1 text-xl font-semibold tracking-tight bg-transparent border-0 outline-none focus:bg-[var(--surface)] rounded px-2 -mx-2"
        />

        <div className="flex items-center gap-2 text-xs text-[var(--muted)]">
          {saving ? (
            <span className="inline-flex items-center gap-1">
              <Loader2 size={12} className="animate-spin" /> Sauvegarde…
            </span>
          ) : dirty ? (
            <span>Modifications non enregistrées</span>
          ) : lastSavedAt ? (
            <span className="inline-flex items-center gap-1 text-[var(--color-success)]">
              <Check size={12} /> Enregistré
            </span>
          ) : null}
        </div>

        <div className="inline-flex rounded-md border border-[var(--border)] p-0.5">
          <ViewBtn current={view} value="edit" onClick={setView} label="Édition" icon={Pencil} />
          <ViewBtn current={view} value="split" onClick={setView} label="Split" icon={Columns} />
          <ViewBtn current={view} value="preview" onClick={setView} label="Aperçu" icon={Eye} />
        </div>

        <button
          onClick={() => setRevisionsOpen(true)}
          title="Historique"
          className="p-1.5 rounded text-[var(--muted)] hover:text-[var(--foreground)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
        >
          <History size={14} />
        </button>
        <button
          onClick={handleDelete}
          title="Supprimer"
          className="p-1.5 rounded text-[var(--muted)] hover:text-[var(--color-danger)]"
        >
          <Trash2 size={14} />
        </button>
      </header>

      <div className="flex-1 flex overflow-hidden mt-4">
        {(view === "edit" || view === "split") && (
          <textarea
            value={content}
            onChange={(e) => setContent(e.target.value)}
            onBlur={save}
            placeholder="# Commence à écrire…"
            className={clsx(
              "h-full resize-none outline-none bg-transparent font-mono text-sm leading-relaxed p-4 border border-[var(--border)] rounded-lg",
              view === "split" ? "w-1/2" : "w-full",
            )}
            spellCheck
          />
        )}
        {(view === "preview" || view === "split") && (
          <div
            className={clsx(
              "h-full overflow-y-auto p-6 prose-doc",
              view === "split" ? "w-1/2 ml-4" : "w-full",
            )}
          >
            {content.trim() ? (
              <ReactMarkdown remarkPlugins={[remarkGfm]}>
                {content}
              </ReactMarkdown>
            ) : (
              <p className="text-sm text-[var(--muted)]">
                La prévisualisation apparaîtra ici.
              </p>
            )}
          </div>
        )}
      </div>

      {revisionsOpen && (
        <RevisionsDrawer
          pageId={page.id}
          onClose={() => setRevisionsOpen(false)}
          onRestored={() => {
            setRevisionsOpen(false);
            router.refresh();
          }}
        />
      )}
    </div>
  );
}

function ViewBtn({
  current,
  value,
  onClick,
  label,
  icon: Icon,
}: {
  current: ViewMode;
  value: ViewMode;
  onClick: (v: ViewMode) => void;
  label: string;
  icon: React.ComponentType<{ size?: number; strokeWidth?: number }>;
}) {
  return (
    <button
      onClick={() => onClick(value)}
      title={label}
      className={clsx(
        "inline-flex items-center gap-1 rounded px-2 py-1 text-xs",
        current === value
          ? "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)]"
          : "text-[var(--muted)] hover:text-[var(--foreground)]",
      )}
    >
      <Icon size={12} strokeWidth={2} />
    </button>
  );
}

function RevisionsDrawer({
  pageId,
  onClose,
  onRestored,
}: {
  pageId: string;
  onClose: () => void;
  onRestored: () => void;
}) {
  const [revs, setRevs] = useState<DocRevision[] | null>(null);
  const [previewing, setPreviewing] = useState<DocRevision | null>(null);

  useEffect(() => {
    (async () => {
      const res = await apiFetch(`/api/docs/${pageId}/revisions`);
      if (res.ok) setRevs((await res.json()) as DocRevision[]);
    })();
  }, [pageId]);

  useEffect(() => {
    function onEsc(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onEsc);
    return () => window.removeEventListener("keydown", onEsc);
  }, [onClose]);

  async function loadRevision(id: string) {
    const res = await apiFetch(`/api/docs/revisions/${id}`);
    if (res.ok) setPreviewing((await res.json()) as DocRevision);
  }

  async function restore(rev: DocRevision) {
    if (!confirm(`Restaurer la version du ${formatDate(rev.created_at)} ?`))
      return;
    const res = await apiFetch(`/api/docs/${pageId}/restore/${rev.id}`, {
      method: "POST",
    });
    if (res.ok) onRestored();
  }

  return (
    <div className="fixed inset-0 z-40 flex">
      <div className="flex-1 bg-black/20" onClick={onClose} />
      <aside className="w-full max-w-2xl bg-[var(--background)] border-l border-[var(--border)] flex flex-col shadow-2xl">
        <header className="px-6 py-4 border-b border-[var(--border)] flex items-center">
          <h3 className="text-sm font-medium flex-1">Historique des versions</h3>
          <button
            onClick={onClose}
            className="text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
          >
            Fermer
          </button>
        </header>

        <div className="flex flex-1 overflow-hidden">
          <ul className="w-64 border-r border-[var(--border)] overflow-y-auto">
            {revs === null && (
              <li className="p-4 text-xs text-[var(--muted)]">Chargement…</li>
            )}
            {revs !== null && revs.length === 0 && (
              <li className="p-4 text-xs text-[var(--muted)]">
                Aucune version antérieure.
              </li>
            )}
            {revs?.map((r) => (
              <li key={r.id}>
                <button
                  onClick={() => loadRevision(r.id)}
                  className={clsx(
                    "w-full text-left px-4 py-3 text-xs hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]",
                    previewing?.id === r.id &&
                      "bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)]",
                  )}
                >
                  <p className="font-medium text-[var(--foreground)] truncate">
                    {r.title}
                  </p>
                  <p className="mt-0.5 text-[var(--muted)]">
                    {formatDate(r.created_at)}
                  </p>
                  {r.creator && (
                    <p className="text-[var(--muted)] truncate">
                      {r.creator.email}
                    </p>
                  )}
                </button>
              </li>
            ))}
          </ul>

          <div className="flex-1 overflow-y-auto flex flex-col">
            {previewing ? (
              <>
                <div className="px-6 py-3 border-b border-[var(--border)] flex items-center gap-2">
                  <p className="text-xs text-[var(--muted)] flex-1">
                    Version du {formatDate(previewing.created_at)}
                  </p>
                  <button
                    onClick={() => restore(previewing)}
                    className="rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1 text-xs font-medium"
                  >
                    Restaurer
                  </button>
                </div>
                <div className="p-6 prose-doc overflow-y-auto">
                  <h1>{previewing.title}</h1>
                  <ReactMarkdown remarkPlugins={[remarkGfm]}>
                    {previewing.content ?? ""}
                  </ReactMarkdown>
                </div>
              </>
            ) : (
              <p className="p-6 text-xs text-[var(--muted)]">
                Sélectionne une version pour la prévisualiser.
              </p>
            )}
          </div>
        </div>
      </aside>
    </div>
  );
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("fr-FR", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
