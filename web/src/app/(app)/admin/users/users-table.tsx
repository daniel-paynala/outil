"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
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
  const [error, setError] = useState<string | null>(null);

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

  return (
    <div className="space-y-3">
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
                  <td className="px-4 py-2.5 text-right">
                    <button
                      onClick={() => save(u)}
                      disabled={!dirty || savingId === u.id || pending}
                      className="text-xs px-3 py-1 rounded-md bg-[var(--color-brand-red)] text-white disabled:opacity-30 disabled:cursor-not-allowed hover:opacity-90"
                    >
                      {savingId === u.id ? "..." : "Enregistrer"}
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
