"use client";

import { useState, useTransition, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Plus, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api/client";

export type AdminUser = {
  id: string;
  email: string;
  name: string | null;
  role: "member" | "admin";
  created_at: string | null;
  projects_count: number;
};

type Draft = { name: string; role: "member" | "admin" };

export default function UsersTable({
  users,
  currentUserId,
}: {
  users: AdminUser[];
  currentUserId: string;
}) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
  const [savingId, setSavingId] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [showAdd, setShowAdd] = useState(false);

  function getDraft(u: AdminUser): Draft {
    return drafts[u.id] ?? { name: u.name ?? "", role: u.role };
  }

  function updateDraft(u: AdminUser, patch: Partial<Draft>) {
    setDrafts((d) => {
      const current = d[u.id] ?? { name: u.name ?? "", role: u.role };
      return { ...d, [u.id]: { ...current, ...patch } };
    });
  }

  function isDirty(u: AdminUser): boolean {
    const d = drafts[u.id];
    if (!d) return false;
    return d.name !== (u.name ?? "") || d.role !== u.role;
  }

  async function save(u: AdminUser) {
    setError(null);
    setSavingId(u.id);
    const d = getDraft(u);
    try {
      const res = await apiFetch(`/api/admin/users/${u.id}`, {
        method: "PATCH",
        body: JSON.stringify({ name: d.name || null, role: d.role }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }
      setDrafts((cur) => {
        const next = { ...cur };
        delete next[u.id];
        return next;
      });
      startTransition(() => router.refresh());
    } catch (e) {
      setError(e instanceof Error ? e.message : "Erreur inconnue");
    } finally {
      setSavingId(null);
    }
  }

  async function remove(u: AdminUser) {
    if (!confirm(`Supprimer ${u.email} ? Cette action est définitive.`)) return;
    setError(null);
    setDeletingId(u.id);
    try {
      const res = await apiFetch(`/api/admin/users/${u.id}`, { method: "DELETE" });
      if (!res.ok && res.status !== 204) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }
      startTransition(() => router.refresh());
    } catch (e) {
      setError(e instanceof Error ? e.message : "Erreur inconnue");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <button
          onClick={() => setShowAdd(true)}
          className="inline-flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white hover:opacity-90"
        >
          <Plus className="w-4 h-4" />
          Ajouter
        </button>
      </div>

      {error && (
        <div className="rounded-md border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 px-3 py-2 text-xs text-[var(--color-danger)]">
          {error}
        </div>
      )}

      <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)]/50 text-xs uppercase tracking-wider text-[var(--muted)]">
            <tr>
              <th className="text-left px-4 py-2.5 font-medium">Email</th>
              <th className="text-left px-4 py-2.5 font-medium">Nom</th>
              <th className="text-left px-4 py-2.5 font-medium">Rôle</th>
              <th className="text-left px-4 py-2.5 font-medium">Projets</th>
              <th className="text-right px-4 py-2.5 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--border)]">
            {users.map((u) => {
              const draft = getDraft(u);
              const dirty = isDirty(u);
              const isSelf = u.id === currentUserId;
              return (
                <tr key={u.id}>
                  <td className="px-4 py-2.5">
                    <span className="font-mono text-xs">{u.email}</span>
                    {isSelf && (
                      <span className="ml-2 text-[10px] uppercase tracking-wider text-[var(--muted)]">
                        toi
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-2.5">
                    <input
                      type="text"
                      value={draft.name}
                      onChange={(e) => updateDraft(u, { name: e.target.value })}
                      placeholder="—"
                      className="w-full bg-transparent rounded border border-[var(--border)] px-2 py-1 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
                    />
                  </td>
                  <td className="px-4 py-2.5">
                    <select
                      value={draft.role}
                      onChange={(e) =>
                        updateDraft(u, {
                          role: e.target.value as "member" | "admin",
                        })
                      }
                      disabled={isSelf && u.role === "admin"}
                      className="bg-transparent rounded border border-[var(--border)] px-2 py-1 text-sm focus:outline-none focus:border-[var(--color-neutral-400)] disabled:opacity-50"
                      title={
                        isSelf && u.role === "admin"
                          ? "Tu ne peux pas te rétrograder toi-même"
                          : undefined
                      }
                    >
                      <option value="member">member</option>
                      <option value="admin">admin</option>
                    </select>
                  </td>
                  <td className="px-4 py-2.5 tabular-nums text-[var(--muted)]">
                    {u.projects_count}
                  </td>
                  <td className="px-4 py-2.5">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        onClick={() => save(u)}
                        disabled={!dirty || savingId === u.id || pending}
                        className="text-xs px-3 py-1 rounded-md bg-[var(--color-brand-red)] text-white disabled:opacity-30 disabled:cursor-not-allowed hover:opacity-90"
                      >
                        {savingId === u.id ? "..." : "Enregistrer"}
                      </button>
                      <button
                        onClick={() => remove(u)}
                        disabled={isSelf || deletingId === u.id || pending}
                        className="p-1.5 rounded-md text-[var(--muted)] hover:text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 disabled:opacity-20 disabled:cursor-not-allowed"
                        title={isSelf ? "Tu ne peux pas te supprimer toi-même" : "Supprimer"}
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {showAdd && (
        <AddUserModal
          onClose={() => setShowAdd(false)}
          onCreated={() => {
            setShowAdd(false);
            startTransition(() => router.refresh());
          }}
        />
      )}
    </div>
  );
}

function AddUserModal({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: () => void;
}) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<"member" | "admin">("member");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const res = await apiFetch("/api/admin/users", {
        method: "POST",
        body: JSON.stringify({ name: name.trim(), email: email.trim(), role }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(
          body.error ??
            (body.errors
              ? Object.values(body.errors as Record<string, string[]>)
                  .flat()
                  .join(" · ")
              : `HTTP ${res.status}`),
        );
      }
      onCreated();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Erreur inconnue");
    } finally {
      setSubmitting(false);
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
              Inviter un utilisateur
            </h2>
            <p className="mt-1 text-xs text-[var(--muted)]">
              Un email d&apos;invitation sera envoyé pour activer le compte.
            </p>
          </div>

          <div className="px-5 py-4 space-y-3">
            <div>
              <label className="block text-xs font-medium text-[var(--muted)] mb-1">
                Prénom
              </label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
                autoFocus
                maxLength={120}
                placeholder="Jean"
                className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-[var(--muted)] mb-1">
                Email
              </label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                placeholder="jean@exemple.com"
                className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm font-mono focus:outline-none focus:border-[var(--color-neutral-400)]"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-[var(--muted)] mb-1">
                Statut
              </label>
              <select
                value={role}
                onChange={(e) => setRole(e.target.value as "member" | "admin")}
                className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
              >
                <option value="member">member</option>
                <option value="admin">admin</option>
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
              disabled={submitting || !name.trim() || !email.trim()}
              className="text-sm px-3 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white disabled:opacity-30 disabled:cursor-not-allowed hover:opacity-90"
            >
              {submitting ? "Envoi..." : "Envoyer l'invitation"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
