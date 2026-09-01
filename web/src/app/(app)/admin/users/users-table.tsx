"use client";

import { useState, useTransition, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Plus, Trash2, MailPlus } from "lucide-react";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import Avatar from "@/core/ui/avatar";

export type AdminUser = {
  id: string;
  email: string;
  name: string | null;
  avatar_url?: string | null;
  role: "member" | "admin";
  created_at: string | null;
  projects_count: number;
  invitation_pending?: boolean;
  last_sign_in_at?: string | null;

  /**
   * Les droits **accordés**, pas les droits effectifs.
   *
   * Un administrateur les a tous par son rôle : les cocher pour lui donnerait à
   * croire qu'on les lui a donnés, puis les décocher ne lui retirerait rien.
   */
  capabilities?: string[];
};

/**
 * Les droits qui s'accordent au cas par cas.
 *
 * La supervision est le premier — elle donne à voir des bases de production
 * entières, ce qui n'est ni « pour tout le monde » ni « réservé aux
 * administrateurs ».
 */
const DROITS: { value: string; label: string; hint: string }[] = [
  {
    value: "monitoring",
    label: "Supervision",
    hint: "Voir l'état des bases et acquitter les incidents.",
  },
  {
    value: "monitoring.admin",
    label: "Administration",
    hint: "Brancher une base et écrire ses sondes. Implique la supervision.",
  },
];

type Draft = { name: string; role: "member" | "admin" };

export default function UsersTable({
  users,
  currentUserId,
}: {
  users: AdminUser[];
  currentUserId: string;
}) {
  const router = useRouter();
  const toast = useToast();
  const [pending, startTransition] = useTransition();
  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
  const [savingId, setSavingId] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [resendingId, setResendingId] = useState<string | null>(null);
  const [showAdd, setShowAdd] = useState(false);
  const [droitsEnCours, setDroitsEnCours] = useState<string | null>(null);

  /**
   * Bascule un droit et enregistre tout de suite.
   *
   * Pas de bouton « Enregistrer » ici, contrairement au nom et au rôle : une
   * porte qu'on croit avoir fermée et qui attend une confirmation reste
   * ouverte, et personne ne relit une ligne d'utilisateur pour vérifier.
   *
   * On envoie la liste complète voulue plutôt qu'une différence — deux onglets
   * ouverts se marcheraient dessus, le second rejouant un retrait décidé sur un
   * état déjà périmé.
   */
  async function basculerDroit(u: AdminUser, droit: string) {
    const actuels = u.capabilities ?? [];
    const voulus = actuels.includes(droit)
      ? actuels.filter((c) => c !== droit)
      : [...actuels, droit];

    setDroitsEnCours(`${u.id}:${droit}`);
    try {
      const res = await apiFetch(`/api/admin/users/${u.id}/capabilities`, {
        method: "PUT",
        body: JSON.stringify({ capabilities: voulus }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }

      const libelle = DROITS.find((d) => d.value === droit)?.label ?? droit;
      toast.success(
        voulus.includes(droit) ? "Droit accordé" : "Droit retiré",
        `${libelle} · ${u.email}`,
      );
      startTransition(() => router.refresh());
    } catch (e) {
      toast.error(
        "Droit inchangé",
        e instanceof Error ? e.message : "Erreur inconnue",
      );
    } finally {
      setDroitsEnCours(null);
    }
  }

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
      toast.success("Utilisateur mis à jour", u.email);
      startTransition(() => router.refresh());
    } catch (e) {
      toast.error("Modification impossible", e instanceof Error ? e.message : "Erreur inconnue");
    } finally {
      setSavingId(null);
    }
  }

  async function resendInvite(u: AdminUser) {
    setResendingId(u.id);
    try {
      const res = await apiFetch(`/api/admin/users/${u.id}/resend-invite`, {
        method: "POST",
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }
      toast.success("Invitation renvoyée", `Un nouvel email a été envoyé à ${u.email}`);
    } catch (e) {
      toast.error("Renvoi impossible", e instanceof Error ? e.message : "Erreur inconnue");
    } finally {
      setResendingId(null);
    }
  }

  async function remove(u: AdminUser) {
    if (!confirm(`Supprimer ${u.email} ? Cette action est définitive.`)) return;
    setDeletingId(u.id);
    try {
      const res = await apiFetch(`/api/admin/users/${u.id}`, { method: "DELETE" });
      if (!res.ok && res.status !== 204) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }
      toast.success("Utilisateur supprimé", u.email);
      startTransition(() => router.refresh());
    } catch (e) {
      toast.error("Suppression impossible", e instanceof Error ? e.message : "Erreur inconnue");
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

      <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)]/50 text-xs uppercase tracking-wider text-[var(--muted)]">
            <tr>
              <th className="text-left px-4 py-2.5 font-medium">Email</th>
              <th className="text-left px-4 py-2.5 font-medium">Nom</th>
              <th className="text-left px-4 py-2.5 font-medium">Rôle</th>
              <th className="text-left px-4 py-2.5 font-medium">Droits</th>
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
                    <div className="flex items-center gap-2.5">
                      <Avatar
                        user={{
                          email: u.email,
                          name: u.name,
                          avatar_url: u.avatar_url,
                        }}
                        size="sm"
                      />
                      <span className="font-mono text-xs truncate">
                        {u.email}
                      </span>
                      {isSelf && (
                        <span className="text-[10px] uppercase tracking-wider text-[var(--muted)] shrink-0">
                          toi
                        </span>
                      )}
                    </div>
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
                  <td className="px-4 py-2.5">
                    {draft.role === "admin" ? (
                      <span className="text-xs text-[var(--muted)]">
                        tous, par le rôle
                      </span>
                    ) : (
                      <div className="flex flex-wrap gap-1.5">
                        {DROITS.map((d) => {
                          const accorde = (u.capabilities ?? []).includes(
                            d.value,
                          );
                          const enCours = droitsEnCours === `${u.id}:${d.value}`;

                          return (
                            <button
                              key={d.value}
                              type="button"
                              onClick={() => basculerDroit(u, d.value)}
                              disabled={enCours || pending}
                              title={d.hint}
                              aria-pressed={accorde}
                              className={`rounded-full border px-2 py-0.5 text-[11px] transition-colors disabled:opacity-40 ${
                                accorde
                                  ? "border-[var(--color-brand-red)] bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]"
                                  : "border-[var(--border)] text-[var(--muted)] hover:text-[var(--foreground)]"
                              }`}
                            >
                              {d.label}
                            </button>
                          );
                        })}
                      </div>
                    )}
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
                        onClick={() => resendInvite(u)}
                        disabled={resendingId === u.id || pending || u.invitation_pending === false}
                        className="p-1.5 rounded-md text-[var(--muted)] hover:text-[var(--color-info)] hover:bg-[var(--color-info)]/10 disabled:opacity-20 disabled:cursor-not-allowed"
                        title={
                          u.invitation_pending === false
                            ? "Compte deja active"
                            : "Renvoyer l'invitation"
                        }
                      >
                        <MailPlus className="w-4 h-4" />
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
          onCreated={(email) => {
            setShowAdd(false);
            toast.success("Invitation envoyée", `Un email a été envoyé à ${email}`);
            startTransition(() => router.refresh());
          }}
          onError={(msg) => toast.error("Invitation impossible", msg)}
        />
      )}
    </div>
  );
}

function AddUserModal({
  onClose,
  onCreated,
  onError,
}: {
  onClose: () => void;
  onCreated: (email: string) => void;
  onError: (msg: string) => void;
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
    const cleanEmail = email.trim();
    try {
      const res = await apiFetch("/api/admin/users", {
        method: "POST",
        body: JSON.stringify({ name: name.trim(), email: cleanEmail, role }),
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
      onCreated(cleanEmail);
    } catch (err) {
      const msg = err instanceof Error ? err.message : "Erreur inconnue";
      setError(msg);
      onError(msg);
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
