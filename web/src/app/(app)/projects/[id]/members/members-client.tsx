"use client";

import { useEffect, useRef, useState, useTransition, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Crown, Trash2, UserPlus, Search, Loader2 } from "lucide-react";
import { clsx } from "clsx";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import Avatar from "@/core/ui/avatar";

export type ProjectMember = {
  id: string;
  user_id: string;
  email: string | null;
  name: string | null;
  avatar_url?: string | null;
  role: "owner" | "member";
  added_at: string | null;
};

type SearchResult = {
  id: string;
  email: string;
  name: string | null;
  avatar_url?: string | null;
};

type Props = {
  projectId: string;
  initialMembers: ProjectMember[];
  currentUserId: string;
  isOwner: boolean;
  isPlatformAdmin: boolean;
};

export default function MembersClient({
  projectId,
  initialMembers,
  currentUserId,
  isOwner,
}: Props) {
  const router = useRouter();
  const toast = useToast();
  const [, startTransition] = useTransition();

  const [members, setMembers] = useState<ProjectMember[]>(initialMembers);
  const [savingId, setSavingId] = useState<string | null>(null);
  const [removingId, setRemovingId] = useState<string | null>(null);
  const [showAdd, setShowAdd] = useState(false);

  async function changeRole(m: ProjectMember, role: "owner" | "member") {
    if (m.role === role) return;
    setSavingId(m.user_id);
    try {
      const res = await apiFetch(`/api/projects/${projectId}/members/${m.user_id}`, {
        method: "PATCH",
        body: JSON.stringify({ role }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }
      setMembers((cur) =>
        cur.map((x) => (x.user_id === m.user_id ? { ...x, role } : x)),
      );
      toast.success("Rôle mis à jour", `${m.email ?? "Membre"} → ${role}`);
      startTransition(() => router.refresh());
    } catch (e) {
      toast.error(
        "Modification impossible",
        e instanceof Error ? e.message : "Erreur inconnue",
      );
    } finally {
      setSavingId(null);
    }
  }

  async function remove(m: ProjectMember) {
    if (!confirm(`Retirer ${m.email ?? "ce membre"} du projet ?`)) return;
    setRemovingId(m.user_id);
    try {
      const res = await apiFetch(`/api/projects/${projectId}/members/${m.user_id}`, {
        method: "DELETE",
      });
      if (!res.ok && res.status !== 204) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }
      setMembers((cur) => cur.filter((x) => x.user_id !== m.user_id));
      toast.success("Membre retiré", m.email ?? "");
      startTransition(() => router.refresh());
    } catch (e) {
      toast.error(
        "Suppression impossible",
        e instanceof Error ? e.message : "Erreur inconnue",
      );
    } finally {
      setRemovingId(null);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-sm font-medium">Membres du projet</h2>
          <p className="text-xs text-[var(--muted)] mt-0.5">
            {members.length} membre{members.length > 1 ? "s" : ""} ·{" "}
            {members.filter((m) => m.role === "owner").length} owner
            {members.filter((m) => m.role === "owner").length > 1 ? "s" : ""}
          </p>
        </div>
        {isOwner && (
          <button
            onClick={() => setShowAdd(true)}
            className="inline-flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white hover:opacity-90"
          >
            <UserPlus className="w-4 h-4" />
            Ajouter
          </button>
        )}
      </div>

      <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)]/50 text-xs uppercase tracking-wider text-[var(--muted)]">
            <tr>
              <th className="text-left px-4 py-2.5 font-medium">Membre</th>
              <th className="text-left px-4 py-2.5 font-medium">Rôle</th>
              <th className="text-left px-4 py-2.5 font-medium">Ajouté le</th>
              <th className="text-right px-4 py-2.5 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--border)]">
            {members.map((m) => {
              const isSelf = m.user_id === currentUserId;
              return (
                <tr key={m.user_id}>
                  <td className="px-4 py-2.5">
                    <div className="flex items-center gap-2.5">
                      <Avatar
                        user={{
                          email: m.email,
                          name: m.name,
                          avatar_url: m.avatar_url,
                        }}
                        size="sm"
                      />
                      <div className="flex flex-col min-w-0">
                        <span className="font-medium truncate">
                          {m.name ?? m.email ?? "Inconnu"}
                          {isSelf && (
                            <span className="ml-2 text-[10px] uppercase tracking-wider text-[var(--muted)]">
                              toi
                            </span>
                          )}
                        </span>
                        {m.name && (
                          <span className="text-xs font-mono text-[var(--muted)] truncate">
                            {m.email}
                          </span>
                        )}
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-2.5">
                    {isOwner ? (
                      <select
                        value={m.role}
                        disabled={savingId === m.user_id}
                        onChange={(e) =>
                          changeRole(m, e.target.value as "owner" | "member")
                        }
                        className="bg-transparent rounded border border-[var(--border)] px-2 py-1 text-sm focus:outline-none focus:border-[var(--color-neutral-400)] disabled:opacity-50"
                      >
                        <option value="member">member</option>
                        <option value="owner">owner</option>
                      </select>
                    ) : (
                      <span
                        className={clsx(
                          "inline-flex items-center gap-1 text-xs rounded px-1.5 py-0.5",
                          m.role === "owner"
                            ? "bg-[var(--color-brand-yellow)]/15 text-[var(--color-brand-yellow-600)]"
                            : "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--muted)]",
                        )}
                      >
                        {m.role === "owner" && <Crown className="w-3 h-3" />}
                        {m.role}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-xs text-[var(--muted)] tabular-nums">
                    {m.added_at
                      ? new Date(m.added_at).toLocaleDateString("fr-FR", {
                          day: "numeric",
                          month: "short",
                          year: "numeric",
                        })
                      : "—"}
                  </td>
                  <td className="px-4 py-2.5">
                    <div className="flex items-center justify-end gap-2">
                      {isOwner && (
                        <button
                          onClick={() => remove(m)}
                          disabled={removingId === m.user_id}
                          className="p-1.5 rounded-md text-[var(--muted)] hover:text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 disabled:opacity-20 disabled:cursor-not-allowed"
                          title="Retirer du projet"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {!isOwner && (
        <p className="text-xs text-[var(--muted)] italic">
          Seul un owner du projet peut gérer les membres.
        </p>
      )}

      {showAdd && (
        <AddMemberModal
          projectId={projectId}
          onClose={() => setShowAdd(false)}
          onAdded={(newMembers) => {
            setMembers((cur) => [...cur, ...newMembers]);
            setShowAdd(false);
            if (newMembers.length === 1) {
              toast.success(
                "Membre ajouté",
                newMembers[0].email ?? "Membre ajouté au projet",
              );
            } else {
              toast.success(
                `${newMembers.length} membres ajoutés`,
                newMembers.map((m) => m.email ?? "?").join(", "),
              );
            }
            startTransition(() => router.refresh());
          }}
        />
      )}
    </div>
  );
}

function AddMemberModal({
  projectId,
  onClose,
  onAdded,
}: {
  projectId: string;
  onClose: () => void;
  onAdded: (members: ProjectMember[]) => void;
}) {
  const toast = useToast();
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SearchResult[]>([]);
  const [searching, setSearching] = useState(false);
  const [selected, setSelected] = useState<SearchResult[]>([]);
  const [role, setRole] = useState<"owner" | "member">("member");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const selectedIds = new Set(selected.map((s) => s.id));

  useEffect(() => {
    if (query.trim().length < 2) {
      setResults([]);
      return;
    }
    if (debounceRef.current) clearTimeout(debounceRef.current);
    setSearching(true);
    debounceRef.current = setTimeout(async () => {
      try {
        const res = await apiFetch(
          `/api/users/search?q=${encodeURIComponent(query.trim())}&exclude_project=${projectId}`,
        );
        if (res.ok) {
          setResults(await res.json());
        }
      } finally {
        setSearching(false);
      }
    }, 250);
  }, [query, projectId]);

  function addToSelection(u: SearchResult) {
    if (selectedIds.has(u.id)) return;
    setSelected((cur) => [...cur, u]);
    setQuery("");
    setResults([]);
  }

  function removeFromSelection(id: string) {
    setSelected((cur) => cur.filter((s) => s.id !== id));
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (selected.length === 0) return;
    setError(null);
    setSubmitting(true);

    // Appels parallèles — un POST par utilisateur. Promise.allSettled pour
    // que les succès passent même si un échoue.
    const results = await Promise.allSettled(
      selected.map(async (u) => {
        const res = await apiFetch(`/api/projects/${projectId}/members`, {
          method: "POST",
          body: JSON.stringify({ user_id: u.id, role }),
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(body.error ?? `HTTP ${res.status}`);
        return body as ProjectMember;
      }),
    );

    const added: ProjectMember[] = [];
    const failed: string[] = [];
    results.forEach((r, i) => {
      if (r.status === "fulfilled") {
        added.push(r.value);
      } else {
        failed.push(
          `${selected[i].email}: ${r.reason instanceof Error ? r.reason.message : String(r.reason)}`,
        );
      }
    });

    setSubmitting(false);

    if (added.length > 0) {
      onAdded(added);
    }
    if (failed.length > 0) {
      const msg = failed.join(" · ");
      setError(msg);
      toast.error(
        added.length > 0
          ? `${failed.length}/${selected.length} ajout(s) ont échoué`
          : "Ajout impossible",
        msg,
      );
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
      onClick={onClose}
    >
      <div
        className="w-full max-w-md rounded-xl border border-[var(--border)] bg-[var(--surface)] shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <form onSubmit={submit}>
          <div className="px-5 pt-5 pb-2">
            <h2 className="text-lg font-semibold tracking-tight">
              Ajouter des membres
            </h2>
            <p className="mt-1 text-xs text-[var(--muted)]">
              Recherche un ou plusieurs utilisateurs par email ou nom.
            </p>
          </div>

          <div className="px-5 py-4 space-y-3">
            {/* Selected chips */}
            {selected.length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {selected.map((u) => (
                  <span
                    key={u.id}
                    className="inline-flex items-center gap-1.5 rounded-full bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)] pl-2 pr-1 py-0.5 text-xs"
                  >
                    {u.name ?? u.email}
                    <button
                      type="button"
                      onClick={() => removeFromSelection(u.id)}
                      className="rounded-full hover:bg-[var(--color-brand-red)]/20 p-0.5"
                      aria-label={`Retirer ${u.email}`}
                    >
                      <svg
                        className="size-3"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={2}
                      >
                        <path d="M6 6l12 12M6 18L18 6" />
                      </svg>
                    </button>
                  </span>
                ))}
              </div>
            )}

            <div className="space-y-2">
              <div className="relative">
                <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" />
                <input
                  type="text"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  autoFocus
                  placeholder={
                    selected.length === 0
                      ? "Email ou nom…"
                      : "Ajouter encore quelqu'un…"
                  }
                  className="w-full bg-transparent rounded-md border border-[var(--border)] pl-9 pr-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
                />
                {searching && (
                  <Loader2 className="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-[var(--muted)] animate-spin" />
                )}
              </div>

              {results.length > 0 && (
                <ul className="rounded-md border border-[var(--border)] bg-[var(--background)] divide-y divide-[var(--border)] max-h-56 overflow-auto">
                  {results
                    .filter((u) => !selectedIds.has(u.id))
                    .map((u) => (
                      <li key={u.id}>
                        <button
                          type="button"
                          onClick={() => addToSelection(u)}
                          className="w-full text-left px-3 py-2 hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50 flex items-center gap-2.5"
                        >
                          <Avatar
                            user={{
                              email: u.email,
                              name: u.name,
                              avatar_url: u.avatar_url,
                            }}
                            size="sm"
                          />
                          <div className="min-w-0 flex-1">
                            <div className="text-sm font-medium truncate">
                              {u.name ?? u.email}
                            </div>
                            {u.name && (
                              <div className="text-xs font-mono text-[var(--muted)] truncate">
                                {u.email}
                              </div>
                            )}
                          </div>
                        </button>
                      </li>
                    ))}
                </ul>
              )}

              {!searching && query.trim().length >= 2 && results.length === 0 && (
                <p className="text-xs text-[var(--muted)] px-1">
                  Aucun utilisateur trouvé. Demande à un admin d&apos;inviter
                  cette personne sur la plateforme d&apos;abord.
                </p>
              )}
            </div>

            <div>
              <label className="block text-xs font-medium text-[var(--muted)] mb-1">
                Rôle dans le projet
                {selected.length > 1 && (
                  <span className="ml-1 text-[10px] text-[var(--muted)]">
                    (s&apos;applique à tous les membres ajoutés)
                  </span>
                )}
              </label>
              <select
                value={role}
                onChange={(e) => setRole(e.target.value as "owner" | "member")}
                className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
              >
                <option value="member">member — contribue au projet</option>
                <option value="owner">owner — gère le projet et ses membres</option>
              </select>
            </div>

            {error && (
              <div className="rounded-md border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 px-3 py-2 text-xs text-[var(--color-danger)]">
                {error}
              </div>
            )}
          </div>

          <div className="flex items-center justify-end gap-2 px-5 py-3 border-t border-[var(--border)]">
            <button
              type="button"
              onClick={onClose}
              disabled={submitting}
              className="text-sm px-3 py-1.5 rounded-md text-[var(--muted)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50"
            >
              Annuler
            </button>
            <button
              type="submit"
              disabled={submitting || selected.length === 0}
              className="text-sm px-3 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white disabled:opacity-30 disabled:cursor-not-allowed hover:opacity-90"
            >
              {submitting
                ? "Ajout…"
                : selected.length > 1
                  ? `Ajouter (${selected.length})`
                  : "Ajouter"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
