"use client";

import { useState, useMemo } from "react";
import Link from "next/link";
import { useParams, useRouter, usePathname } from "next/navigation";
import {
  ChevronRight,
  ChevronDown,
  FileText,
  Plus,
  Loader2,
} from "lucide-react";
import { clsx } from "clsx";
import type { DocPageSummary } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";

export default function DocTree({
  projectId,
  initialPages,
}: {
  projectId: string;
  initialPages: DocPageSummary[];
}) {
  const [pages, setPages] = useState<DocPageSummary[]>(initialPages);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [creating, setCreating] = useState<string | null | false>(false); // parent_id or null for root
  const [title, setTitle] = useState("");
  const [loading, setLoading] = useState(false);
  const router = useRouter();
  const toast = useToast();

  const byParent = useMemo(() => {
    const map = new Map<string | null, DocPageSummary[]>();
    for (const p of pages) {
      const key = p.parent_id;
      const list = map.get(key) ?? [];
      list.push(p);
      map.set(key, list);
    }
    return map;
  }, [pages]);

  function toggle(id: string) {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  async function createPage(parentId: string | null) {
    if (!title.trim()) return;
    setLoading(true);
    try {
      const res = await apiFetch(`/api/projects/${projectId}/docs`, {
        method: "POST",
        body: JSON.stringify({ title: title.trim(), parent_id: parentId }),
      });
      setLoading(false);
      if (!res.ok) {
        toast.error(
          "Création impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      const created = (await res.json()) as DocPageSummary;
      toast.success("Créée", `Page « ${created.title} »`);
      setPages((prev) => [...prev, created]);
      if (parentId) {
        setExpanded((prev) => new Set(prev).add(parentId));
      }
      setTitle("");
      setCreating(false);
      router.push(`/projects/${projectId}/docs/${created.id}`);
      router.refresh();
    } catch (err) {
      setLoading(false);
      toast.error(
        "Création impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  const roots = byParent.get(null) ?? [];

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between mb-2">
        <h2 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)]">
          Pages
        </h2>
        <button
          onClick={() => {
            setCreating(null);
            setTitle("");
          }}
          title="Nouvelle page (racine)"
          className="p-1 rounded text-[var(--muted)] hover:text-[var(--foreground)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
        >
          <Plus size={14} />
        </button>
      </div>

      {creating === null && (
        <InlineCreateInput
          title={title}
          setTitle={setTitle}
          onSubmit={() => createPage(null)}
          onCancel={() => setCreating(false)}
          loading={loading}
        />
      )}

      <ul className="space-y-0.5">
        {roots.length === 0 && creating !== null && (
          <li className="text-xs text-[var(--muted)] py-2">
            Aucune page. Crée-en une.
          </li>
        )}
        {roots.map((p) => (
          <TreeNode
            key={p.id}
            page={p}
            byParent={byParent}
            expanded={expanded}
            toggle={toggle}
            projectId={projectId}
            creating={creating}
            setCreating={setCreating}
            title={title}
            setTitle={setTitle}
            onCreate={createPage}
            loading={loading}
            depth={0}
          />
        ))}
      </ul>
    </div>
  );
}

function TreeNode({
  page,
  byParent,
  expanded,
  toggle,
  projectId,
  creating,
  setCreating,
  title,
  setTitle,
  onCreate,
  loading,
  depth,
}: {
  page: DocPageSummary;
  byParent: Map<string | null, DocPageSummary[]>;
  expanded: Set<string>;
  toggle: (id: string) => void;
  projectId: string;
  creating: string | null | false;
  setCreating: (v: string | null | false) => void;
  title: string;
  setTitle: (v: string) => void;
  onCreate: (parentId: string | null) => void;
  loading: boolean;
  depth: number;
}) {
  const pathname = usePathname();
  const params = useParams();
  const activeId = params.pageId as string | undefined;
  const children = byParent.get(page.id) ?? [];
  const hasChildren = children.length > 0;
  const isOpen = expanded.has(page.id);
  const active = activeId === page.id;

  return (
    <li>
      <div
        className={clsx(
          "flex items-center gap-1 rounded-md group",
          active
            ? "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)]"
            : "hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]",
        )}
        style={{ paddingLeft: `${depth * 12}px` }}
      >
        <button
          onClick={() => hasChildren && toggle(page.id)}
          className={clsx(
            "p-0.5 text-[var(--muted)]",
            !hasChildren && "opacity-0 cursor-default",
          )}
          aria-label={isOpen ? "Replier" : "Déplier"}
        >
          {isOpen ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
        </button>
        <Link
          href={`/projects/${projectId}/docs/${page.id}`}
          className="flex-1 flex items-center gap-1.5 py-1 text-sm truncate"
        >
          <FileText size={13} strokeWidth={1.75} className="text-[var(--muted)]" />
          <span className="truncate">{page.title}</span>
        </Link>
        <button
          onClick={() => {
            setCreating(page.id);
            setTitle("");
          }}
          className="opacity-0 group-hover:opacity-100 p-0.5 mr-1 text-[var(--muted)] hover:text-[var(--foreground)]"
          title="Ajouter une sous-page"
        >
          <Plus size={11} />
        </button>
      </div>

      {creating === page.id && (
        <div style={{ paddingLeft: `${(depth + 1) * 12}px` }}>
          <InlineCreateInput
            title={title}
            setTitle={setTitle}
            onSubmit={() => onCreate(page.id)}
            onCancel={() => setCreating(false)}
            loading={loading}
          />
        </div>
      )}

      {isOpen && hasChildren && (
        <ul className="space-y-0.5">
          {children.map((c) => (
            <TreeNode
              key={c.id}
              page={c}
              byParent={byParent}
              expanded={expanded}
              toggle={toggle}
              projectId={projectId}
              creating={creating}
              setCreating={setCreating}
              title={title}
              setTitle={setTitle}
              onCreate={onCreate}
              loading={loading}
              depth={depth + 1}
            />
          ))}
        </ul>
      )}
    </li>
  );
}

function InlineCreateInput({
  title,
  setTitle,
  onSubmit,
  onCancel,
  loading,
}: {
  title: string;
  setTitle: (v: string) => void;
  onSubmit: () => void;
  onCancel: () => void;
  loading: boolean;
}) {
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit();
      }}
      className="flex items-center gap-1 py-1"
    >
      <input
        autoFocus
        value={title}
        onChange={(e) => setTitle(e.target.value)}
        onKeyDown={(e) => e.key === "Escape" && onCancel()}
        placeholder="Titre…"
        className="flex-1 rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-xs"
      />
      <button
        type="submit"
        disabled={loading || !title.trim()}
        className="p-1 rounded text-[var(--color-brand-red)] disabled:opacity-40"
      >
        {loading ? <Loader2 size={11} className="animate-spin" /> : <Plus size={11} />}
      </button>
    </form>
  );
}
