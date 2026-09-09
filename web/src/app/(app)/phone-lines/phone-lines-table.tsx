"use client";

import { useState, useTransition, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Plus, Pencil, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";

export type PhoneLine = {
  id: string;
  operator: string;
  number: string;
  registered_name: string;
  purpose: string | null;
  mobile_money: boolean;
  whatsapp: boolean;

  /**
   * La façon dont le compte WhatsApp sert — « Bot WhatsApp » dans le registre
   * d'origine. Le fait d'avoir un compte et l'usage qu'on en fait sont deux
   * informations : les fondre obligeait à lire une case à cocher comme du
   * texte.
   */
  whatsapp_note: string | null;
  notes: string | null;
  creator?: { name: string | null; email: string } | null;
  updater?: { name: string | null; email: string } | null;
};

type Draft = {
  operator: string;
  number: string;
  registered_name: string;
  purpose: string;
  mobile_money: boolean;
  whatsapp: boolean;
  whatsapp_note: string;
  notes: string;
};

const VIDE: Draft = {
  operator: "Airtel",
  number: "",
  registered_name: "",
  purpose: "",
  mobile_money: false,
  whatsapp: false,
  whatsapp_note: "",
  notes: "",
};

/**
 * Les opérateurs du pays. Une liste, pas une contrainte : le champ reste
 * libre, parce qu'un opérateur de plus ne doit pas demander un déploiement.
 */
const OPERATEURS = ["Airtel", "Moov"];

export default function PhoneLinesTable({ lines }: { lines: PhoneLine[] }) {
  const router = useRouter();
  const toast = useToast();
  const [pending, startTransition] = useTransition();
  const [edition, setEdition] = useState<PhoneLine | "nouvelle" | null>(null);
  const [suppressionId, setSuppressionId] = useState<string | null>(null);

  async function supprimer(ligne: PhoneLine) {
    const quoi = `${ligne.operator} ${ligne.number}`;
    if (!confirm(`Retirer ${quoi} du registre ?`)) return;

    setSuppressionId(ligne.id);
    try {
      const res = await apiFetch(`/api/phone-lines/${ligne.id}`, {
        method: "DELETE",
      });
      if (!res.ok) throw new Error(await res.text());

      toast.success("Ligne retirée", `${quoi} ne figure plus au registre.`);
      startTransition(() => router.refresh());
    } catch {
      toast.error("Suppression impossible", quoi);
    } finally {
      setSuppressionId(null);
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <button
          onClick={() => setEdition("nouvelle")}
          className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white hover:opacity-90"
        >
          <Plus className="w-3.5 h-3.5" />
          Ajouter une ligne
        </button>
      </div>

      <div className="overflow-x-auto rounded-xl border border-[var(--border)]">
        <table className="w-full text-sm">
          <thead className="bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)]/50 text-xs uppercase tracking-wider text-[var(--muted)]">
            <tr>
              <th className="text-right px-4 py-2.5 font-medium">N°</th>
              <th className="text-left px-4 py-2.5 font-medium">Opérateur</th>
              <th className="text-left px-4 py-2.5 font-medium">Numéro</th>
              <th className="text-left px-4 py-2.5 font-medium">
                Nom enregistré
              </th>
              <th className="text-left px-4 py-2.5 font-medium">Créée pour</th>
              <th className="text-left px-4 py-2.5 font-medium">
                Mobile Money
              </th>
              <th className="text-left px-4 py-2.5 font-medium">WhatsApp</th>
              <th className="text-right px-4 py-2.5 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--border)]">
            {lines.length === 0 && (
              <tr>
                <td
                  colSpan={8}
                  className="px-4 py-10 text-center text-[var(--muted)]"
                >
                  Aucune ligne au registre.
                </td>
              </tr>
            )}
            {lines.map((ligne, index) => (
              <tr key={ligne.id}>
                <td className="px-4 py-2.5 text-right tabular-nums text-[var(--muted)]">
                  {index + 1}
                </td>
                <td className="px-4 py-2.5">{ligne.operator}</td>
                <td className="px-4 py-2.5 font-mono tabular-nums">
                  {ligne.number}
                </td>
                <td className="px-4 py-2.5">{ligne.registered_name}</td>
                <td className="px-4 py-2.5 text-[var(--muted)]">
                  {ligne.purpose || "—"}
                </td>
                <td className="px-4 py-2.5">
                  <Marque actif={ligne.mobile_money} />
                </td>
                <td className="px-4 py-2.5">
                  <Marque actif={ligne.whatsapp} note={ligne.whatsapp_note} />
                </td>
                <td className="px-4 py-2.5">
                  <div className="flex items-center justify-end gap-2">
                    <button
                      onClick={() => setEdition(ligne)}
                      className="p-1.5 rounded-md text-[var(--muted)] hover:text-[var(--foreground)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
                      title="Modifier"
                    >
                      <Pencil className="w-4 h-4" />
                    </button>
                    <button
                      onClick={() => supprimer(ligne)}
                      disabled={suppressionId === ligne.id || pending}
                      className="p-1.5 rounded-md text-[var(--muted)] hover:text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 disabled:opacity-20 disabled:cursor-not-allowed"
                      title="Retirer du registre"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {edition && (
        <LigneModal
          ligne={edition === "nouvelle" ? null : edition}
          onClose={() => setEdition(null)}
          onSaved={(quoi, creee) => {
            setEdition(null);
            toast.success(
              creee ? "Ligne ajoutée" : "Ligne modifiée",
              `${quoi} est au registre.`,
            );
            startTransition(() => router.refresh());
          }}
          onError={(msg) => toast.error("Enregistrement impossible", msg)}
        />
      )}
    </div>
  );
}

/**
 * Oui ou non, d'un coup d'œil.
 *
 * Le registre d'origine écrivait « Oui » et « Non » en toutes lettres dans des
 * colonnes voisines, et l'œil devait lire chaque cellule pour balayer la
 * colonne. La couleur fait ce travail — le texte reste, pour qui ne distingue
 * pas les deux teintes.
 */
function Marque({ actif, note }: { actif: boolean; note?: string | null }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <span
        className={
          actif
            ? "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-[var(--color-success)]/12 text-[var(--color-success)]"
            : "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-800)] text-[var(--muted)]"
        }
      >
        {actif ? "Oui" : "Non"}
      </span>
      {actif && note && (
        <span className="text-[11px] text-[var(--muted)]">{note}</span>
      )}
    </span>
  );
}

function LigneModal({
  ligne,
  onClose,
  onSaved,
  onError,
}: {
  ligne: PhoneLine | null;
  onClose: () => void;
  onSaved: (quoi: string, creee: boolean) => void;
  onError: (message: string) => void;
}) {
  const [draft, setDraft] = useState<Draft>(
    ligne
      ? {
          operator: ligne.operator,
          number: ligne.number,
          registered_name: ligne.registered_name,
          purpose: ligne.purpose ?? "",
          mobile_money: ligne.mobile_money,
          whatsapp: ligne.whatsapp,
          whatsapp_note: ligne.whatsapp_note ?? "",
          notes: ligne.notes ?? "",
        }
      : VIDE,
  );
  const [envoi, setEnvoi] = useState(false);

  async function soumettre(e: FormEvent) {
    e.preventDefault();
    setEnvoi(true);

    try {
      const res = await apiFetch(
        ligne ? `/api/phone-lines/${ligne.id}` : "/api/phone-lines",
        {
          method: ligne ? "PATCH" : "POST",
          body: JSON.stringify({
            ...draft,
            purpose: draft.purpose.trim() || null,
            whatsapp_note: draft.whatsapp_note.trim() || null,
            notes: draft.notes.trim() || null,
          }),
        },
      );

      if (!res.ok) {
        // 422 : le serveur explique déjà pourquoi — numéro déjà au registre,
        // chiffres attendus. Réécrire le message ici le ferait diverger du
        // sien à la première règle ajoutée.
        const corps = await res.json().catch(() => null);
        const detail =
          corps?.errors?.number?.[0] ??
          corps?.message ??
          `Erreur ${res.status}`;
        throw new Error(detail);
      }

      const enregistree = (await res.json()) as PhoneLine;
      onSaved(
        `${enregistree.operator} ${enregistree.number}`,
        ligne === null,
      );
    } catch (err) {
      onError(err instanceof Error ? err.message : "Réessaie dans un moment.");
    } finally {
      setEnvoi(false);
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onClick={onClose}
    >
      <div
        className="w-full max-w-md rounded-xl border border-[var(--border)] bg-[var(--surface)] shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <form onSubmit={soumettre}>
          <div className="px-5 py-4 border-b border-[var(--border)]">
            <h2 className="text-sm font-semibold">
              {ligne ? "Modifier la ligne" : "Ajouter une ligne"}
            </h2>
          </div>

          <div className="px-5 py-4 space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <Champ label="Opérateur">
                <input
                  list="operateurs"
                  required
                  value={draft.operator}
                  onChange={(e) =>
                    setDraft({ ...draft, operator: e.target.value })
                  }
                  className={SAISIE}
                />
                <datalist id="operateurs">
                  {OPERATEURS.map((o) => (
                    <option key={o} value={o} />
                  ))}
                </datalist>
              </Champ>

              <Champ label="Numéro de puce / SIM">
                <input
                  required
                  inputMode="tel"
                  placeholder="77050946"
                  value={draft.number}
                  onChange={(e) =>
                    setDraft({ ...draft, number: e.target.value })
                  }
                  className={`${SAISIE} font-mono`}
                />
              </Champ>
            </div>

            <Champ
              label="Nom enregistré"
              aide="Le nom au guichet de l'opérateur — pas forcément quelqu'un d'Arche."
            >
              <input
                required
                value={draft.registered_name}
                onChange={(e) =>
                  setDraft({ ...draft, registered_name: e.target.value })
                }
                className={SAISIE}
              />
            </Champ>

            <Champ label="Créée pour">
              <input
                placeholder="Déploiement Assurpay"
                value={draft.purpose}
                onChange={(e) =>
                  setDraft({ ...draft, purpose: e.target.value })
                }
                className={SAISIE}
              />
            </Champ>

            <div className="space-y-2">
              <Case
                checked={draft.mobile_money}
                onChange={(v) => setDraft({ ...draft, mobile_money: v })}
                label="Rattachée à un compte Mobile Money"
              />
              <Case
                checked={draft.whatsapp}
                onChange={(v) =>
                  setDraft({
                    ...draft,
                    whatsapp: v,
                    // Décocher efface la précision : elle décrirait sinon un
                    // compte qui n'existe plus.
                    whatsapp_note: v ? draft.whatsapp_note : "",
                  })
                }
                label="Rattachée à un compte WhatsApp"
              />
              {draft.whatsapp && (
                <input
                  placeholder="Précision — « Bot WhatsApp »"
                  value={draft.whatsapp_note}
                  onChange={(e) =>
                    setDraft({ ...draft, whatsapp_note: e.target.value })
                  }
                  className={`${SAISIE} ml-6 w-[calc(100%-1.5rem)]`}
                />
              )}
            </div>

            <Champ label="Notes">
              <textarea
                rows={2}
                value={draft.notes}
                onChange={(e) => setDraft({ ...draft, notes: e.target.value })}
                className={`${SAISIE} resize-none`}
              />
            </Champ>
          </div>

          <div className="px-5 py-3 border-t border-[var(--border)] flex justify-end gap-2">
            <button
              type="button"
              onClick={onClose}
              className="text-xs px-3 py-1.5 rounded-md text-[var(--muted)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
            >
              Annuler
            </button>
            <button
              type="submit"
              disabled={envoi}
              className="text-xs px-3 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white disabled:opacity-40 hover:opacity-90"
            >
              {envoi ? "..." : "Enregistrer"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

const SAISIE =
  "w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]";

function Champ({
  label,
  aide,
  children,
}: {
  label: string;
  aide?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="block text-xs text-[var(--muted)] mb-1">{label}</span>
      {children}
      {aide && (
        <span className="block text-[11px] text-[var(--muted)] mt-1">
          {aide}
        </span>
      )}
    </label>
  );
}

function Case({
  checked,
  onChange,
  label,
}: {
  checked: boolean;
  onChange: (value: boolean) => void;
  label: string;
}) {
  return (
    <label className="flex items-center gap-2 text-sm cursor-pointer">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="accent-[var(--color-brand-red)]"
      />
      <span>{label}</span>
    </label>
  );
}
