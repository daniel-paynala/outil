"use client";

import { useState, useEffect, useRef } from "react";
import Link from "next/link";
import {
  Search,
  ListTodo,
  BookOpen,
  ScrollText,
  KeyRound,
  Paperclip,
  Loader2,
  type LucideIcon,
} from "lucide-react";
import { apiFetch } from "@/lib/api/client";
import type { SearchResults } from "@/lib/types";

const DEBOUNCE_MS = 250;

const GROUPS: {
  key: keyof SearchResults;
  label: string;
  icon: LucideIcon;
}[] = [
  { key: "cards", label: "Tâches", icon: ListTodo },
  { key: "docs", label: "Documentation", icon: BookOpen },
  { key: "decisions", label: "Décisions", icon: ScrollText },
  { key: "vault", label: "Coffre", icon: KeyRound },
  { key: "files", label: "Fichiers", icon: Paperclip },
];

export default function SearchClient() {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SearchResults | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Focus on mount
  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  // Debounced fetch
  useEffect(() => {
    const q = query.trim();
    if (q.length < 2) {
      setResults(null);
      setError(null);
      return;
    }

    const ctrl = new AbortController();
    const t = setTimeout(async () => {
      setLoading(true);
      setError(null);
      try {
        const res = await apiFetch(`/api/search?q=${encodeURIComponent(q)}`, {
          signal: ctrl.signal,
        });
        if (!res.ok) {
          setError(`Erreur ${res.status}`);
          setResults(null);
        } else {
          setResults((await res.json()) as SearchResults);
        }
      } catch (e) {
        if ((e as Error).name !== "AbortError") {
          setError(e instanceof Error ? e.message : String(e));
        }
      } finally {
        setLoading(false);
      }
    }, DEBOUNCE_MS);

    return () => {
      clearTimeout(t);
      ctrl.abort();
    };
  }, [query]);

  const totalCount = results
    ? GROUPS.reduce((n, g) => n + (results[g.key]?.length ?? 0), 0)
    : 0;

  return (
    <div className="space-y-6 max-w-3xl">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Recherche unifiée
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight flex items-center gap-2">
          <Search size={22} strokeWidth={1.75} />
          Recherche
        </h1>
        <p className="mt-2 text-sm text-[var(--muted)]">
          Tous les projets dont tu es membre — tâches, documentation, décisions,
          entrées du coffre, fichiers.
        </p>
      </header>

      <div className="relative">
        <Search
          size={16}
          strokeWidth={2}
          className="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)] pointer-events-none"
        />
        <input
          ref={inputRef}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Rechercher…"
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] pl-9 pr-10 py-2.5 text-sm"
        />
        {loading && (
          <Loader2
            size={14}
            className="absolute right-3 top-1/2 -translate-y-1/2 animate-spin text-[var(--muted)]"
          />
        )}
      </div>

      {error && (
        <p className="text-sm text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded px-3 py-2">
          {error}
        </p>
      )}

      {query.trim().length >= 2 && results && !loading && totalCount === 0 && (
        <p className="text-sm text-[var(--muted)] text-center py-10">
          Aucun résultat pour <span className="font-medium">{query}</span>.
        </p>
      )}

      {results && totalCount > 0 && (
        <div className="space-y-8">
          {GROUPS.map(({ key, label, icon: Icon }) => {
            const list = results[key];
            if (!list || list.length === 0) return null;
            return (
              <section key={key}>
                <h2 className="text-xs font-medium uppercase tracking-wider text-[var(--muted)] mb-3 flex items-center gap-1.5">
                  <Icon size={12} strokeWidth={2} />
                  {label} ({list.length})
                </h2>
                <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
                  {list.map((r) => (
                    <li key={r.id}>
                      <Link
                        href={r.href}
                        className="block px-4 py-3 hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50"
                      >
                        <div className="flex items-start gap-3">
                          <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium">
                              <Highlight
                                text={r.title}
                                query={query}
                              />
                            </p>
                            {r.snippet && (
                              <p className="mt-1 text-xs text-[var(--muted)] line-clamp-2">
                                <Highlight text={r.snippet} query={query} />
                              </p>
                            )}
                          </div>
                          {r.project && (
                            <span className="inline-flex items-center gap-1.5 text-[11px] text-[var(--muted)] shrink-0">
                              <span
                                className="size-2 rounded-full"
                                style={{ background: r.project.color }}
                              />
                              {r.project.name}
                            </span>
                          )}
                        </div>
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            );
          })}
        </div>
      )}

      {query.trim().length < 2 && !results && (
        <p className="text-xs text-[var(--muted)]">
          Tape au moins 2 caractères.
        </p>
      )}
    </div>
  );
}

function Highlight({ text, query }: { text: string; query: string }) {
  const q = query.trim();
  if (!q) return <>{text}</>;
  const parts = text.split(new RegExp(`(${escapeRegex(q)})`, "ig"));
  return (
    <>
      {parts.map((p, i) =>
        p.toLowerCase() === q.toLowerCase() ? (
          <mark
            key={i}
            className="bg-[var(--color-brand-yellow)]/25 text-[var(--foreground)] rounded px-0.5"
          >
            {p}
          </mark>
        ) : (
          <span key={i}>{p}</span>
        ),
      )}
    </>
  );
}

function escapeRegex(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}
