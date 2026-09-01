"use client";

import { useState, type FormEvent } from "react";
import { ShieldCheck } from "lucide-react";

import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import type { MonitoredDatabase } from "@/lib/monitoring/types";

import Drawer from "./drawer";

/**
 * Brancher une base, ou en modifier une.
 *
 * ## Ce que le formulaire n'a pas à dire
 *
 * Il ne demande pas de cocher « accès en lecture seule ». Une case à cocher ne
 * constate rien : le serveur tente réellement d'écrire dans la base et refuse
 * de l'enregistrer si l'écriture passe. Le formulaire annonce donc ce qui va se
 * produire, plutôt que de demander une promesse.
 *
 * ## Le mot de passe, à la modification
 *
 * Il part une fois et ne revient jamais : le champ est donc vide, et le laisser
 * vide garde celui déjà enregistré. Le pré-remplir de faux points ferait croire
 * qu'on peut le relire — et un champ qu'on croit relisible finit par être
 * effacé pour « vérifier ce qu'il contient ».
 *
 * ## Pourquoi renommer ne rejoue pas la vérification
 *
 * Un libellé est une étiquette. Le faire dépendre d'une connexion réussie
 * rendrait le renommage impossible quand la base est justement injoignable —
 * c'est-à-dire au moment où on veut le plus écrire « (en panne) » à côté de son
 * nom.
 */
export default function DatabaseDrawer({
  database,
  onClose,
  onSaved,
}: {
  /** La base à modifier, ou `null` pour en brancher une nouvelle. */
  database?: MonitoredDatabase | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const toast = useToast();
  const [envoi, setEnvoi] = useState(false);
  const [refus, setRefus] = useState<string | null>(null);

  async function soumettre(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setEnvoi(true);
    setRefus(null);

    const champs = new FormData(e.currentTarget);
    const motDePasse = String(champs.get("password") ?? "");
    const corps: Record<string, unknown> = {
      name: String(champs.get("name") ?? "").trim(),
      host: String(champs.get("host") ?? "").trim(),
      port: Number(champs.get("port") ?? 5432),
      dbname: String(champs.get("dbname") ?? "").trim(),
      username: String(champs.get("username") ?? "").trim(),
    };

    // Vide à la modification veut dire « garde celui d'avant ». L'envoyer
    // quand même ferait rejouer la vérification pour rien, et échouerait sur
    // une chaîne vide.
    if (motDePasse !== "" || !database) corps.password = motDePasse;

    try {
      const res = await apiFetch(
        database
          ? `/api/monitoring/databases/${database.id}`
          : "/api/monitoring/databases",
        {
          method: database ? "PATCH" : "POST",
          body: JSON.stringify(corps),
        },
      );

      if (!res.ok) {
        const erreur = await res.json().catch(() => ({}));
        // Le détail vient du serveur et dit précisément ce qui a échoué :
        // hôte injoignable, identifiants refusés, ou écriture acceptée. Le
        // remplacer par « erreur » ferait recommencer à l'aveugle.
        setRefus(
          erreur.detail ??
            erreur.error ??
            `La base n'a pas été acceptée (HTTP ${res.status}).`,
        );
        return;
      }

      toast.success(
        database ? "Base modifiée" : "Base branchée",
        `${corps.name}`,
      );
      onSaved();
    } catch (e) {
      setRefus(e instanceof Error ? e.message : "Le serveur n'a pas répondu.");
    } finally {
      setEnvoi(false);
    }
  }

  const changeLaConnexion = database !== null && database !== undefined;

  return (
    <Drawer
      title={database ? `Modifier « ${database.name} »` : "Brancher une base"}
      onClose={onClose}
    >
      <form onSubmit={soumettre} className="flex min-h-0 flex-1 flex-col">
        <div className="flex-1 space-y-5 overflow-y-auto px-6 py-5">
          <p className="flex gap-2.5 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2.5 text-xs text-[var(--muted)]">
            <ShieldCheck
              size={15}
              className="mt-px shrink-0 text-[var(--color-success)]"
            />
            <span>
              {changeLaConnexion ? (
                <>
                  Changer le nom seul n&apos;interroge pas la base. Toucher à
                  l&apos;hôte, au port, à la base, à l&apos;utilisateur ou au
                  mot de passe fait{" "}
                  <strong className="font-medium text-[var(--foreground)]">
                    rejouer la vérification
                  </strong>{" "}
                  : Arche essaie d&apos;écrire, et refuse la modification
                  entière si l&apos;écriture passe. Les sondes, elles, ne
                  bougent pas.
                </>
              ) : (
                <>
                  Avant d&apos;enregistrer quoi que ce soit, Arche essaie{" "}
                  <strong className="font-medium text-[var(--foreground)]">
                    d&apos;écrire
                  </strong>{" "}
                  dans cette base. Si l&apos;écriture passe, les identifiants
                  sont trop puissants : la base est refusée et rien n&apos;est
                  conservé, mot de passe compris.
                </>
              )}
            </span>
          </p>

          <Field label="Nom" hint="Ce qui s'affichera dans les alertes.">
            <input
              name="name"
              required
              maxLength={80}
              autoFocus
              defaultValue={database?.name ?? ""}
              placeholder="Facturation — production"
              className={inputClass}
            />
          </Field>

          <div className="flex gap-3">
            <div className="flex-1">
              <Field label="Hôte">
                <input
                  name="host"
                  required
                  maxLength={255}
                  defaultValue={database?.host ?? ""}
                  placeholder="db.exemple.ga"
                  className={`${inputClass} font-mono`}
                />
              </Field>
            </div>
            <div className="w-28">
              <Field label="Port">
                <input
                  name="port"
                  type="number"
                  min={1}
                  max={65535}
                  defaultValue={database?.port ?? 5432}
                  className={`${inputClass} font-mono tabular-nums`}
                />
              </Field>
            </div>
          </div>

          <Field label="Base">
            <input
              name="dbname"
              required
              maxLength={120}
              defaultValue={database?.dbname ?? ""}
              placeholder="facturation"
              className={`${inputClass} font-mono`}
            />
          </Field>

          <div className="flex gap-3">
            <div className="flex-1">
              <Field label="Utilisateur">
                <input
                  name="username"
                  required
                  maxLength={120}
                  autoComplete="off"
                  defaultValue={database?.username ?? ""}
                  placeholder="arche_lecture"
                  className={`${inputClass} font-mono`}
                />
              </Field>
            </div>
            <div className="flex-1">
              <Field label="Mot de passe" hint="Écrit une fois, jamais relu.">
                <input
                  name="password"
                  type="password"
                  required
                  maxLength={255}
                  autoComplete="new-password"
                  className={`${inputClass} font-mono`}
                />
              </Field>
            </div>
          </div>

          {refus && (
            <p className="rounded-md border border-[var(--color-danger)]/40 bg-[var(--color-danger)]/5 px-3 py-2.5 text-xs text-[var(--color-danger)]">
              {refus}
            </p>
          )}
        </div>

        <footer className="flex items-center justify-end gap-2 border-t border-[var(--border)] px-6 py-4">
          <button
            type="button"
            onClick={onClose}
            className="rounded-md px-3 py-1.5 text-sm text-[var(--muted)] hover:text-[var(--foreground)]"
          >
            Annuler
          </button>
          <button
            type="submit"
            disabled={envoi}
            className="rounded-md bg-[var(--color-brand-red)] px-3.5 py-1.5 text-sm font-medium text-white hover:bg-[var(--color-brand-red-600)] disabled:opacity-50"
          >
            {envoi
              ? "Vérification…"
              : database
                ? "Enregistrer"
                : "Vérifier et brancher"}
          </button>
        </footer>
      </form>
    </Drawer>
  );
}

const inputClass =
  "w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm outline-none focus:border-[var(--color-brand-red)]";

function Field({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-xs font-medium">{label}</span>
      {children}
      {hint && (
        <span className="mt-1 block text-xs text-[var(--muted)]">{hint}</span>
      )}
    </label>
  );
}
