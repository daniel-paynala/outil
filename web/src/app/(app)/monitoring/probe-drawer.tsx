"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Play, Plus, Trash2 } from "lucide-react";

import { apiFetch } from "@/lib/api/client";
import { ignoresHours } from "@/lib/monitoring/types";
import { useToast } from "@/core/toast/toast-context";
import type {
  Direction,
  MonitoredDatabase,
  Personne,
  Probe,
  WindowMode,
} from "@/lib/monitoring/types";

import Drawer from "./drawer";
import { INFOBULLE_MODE } from "./modes";

/** Une fenêtre en cours de saisie — les paliers restent du texte tant qu'on tape. */
type WindowDraft = {
  hours: number;
  mode: WindowMode;
  direction: Direction;

  /**
   * Saisi tel quel : « 3, 10, 20 ».
   *
   * Garder du texte plutôt qu'un tableau de nombres laisse écrire la virgule
   * avant le chiffre suivant. Un champ qui réordonne à chaque frappe se fait
   * abandonner à la deuxième valeur.
   */
  tiers: string;
};

const FENETRES_PAR_DEFAUT: WindowDraft[] = [
  {
    hours: 24,
    mode: "glissante",
    direction: "croissant",
    tiers: "3, 10, 20, 40, 60, 100",
  },
  {
    hours: 48,
    mode: "glissante",
    direction: "croissant",
    tiers: "10, 40, 100",
  },
];

const EXEMPLE = `select count(*) as valeur
from journal_requetes
where statut = 'timeout'
  and cree_le >= :depuis`;

/**
 * Écrire une sonde.
 *
 * ## Le contrat de la requête
 *
 * Une colonne nommée `valeur`, un paramètre `:depuis`. Deux règles seulement,
 * mais aucune des deux n'est devinable — elles sont donc écrites dans l'écran,
 * pas dans une documentation qu'on lira après coup.
 *
 * ## Pourquoi le bouton « Essayer »
 *
 * Une sonde fausse est le pire défaut possible : elle s'installe, ne signale
 * jamais rien, et c'est à elle qu'on fait confiance le jour où quelque chose ne
 * va pas. L'essai l'exécute pour de vrai, avec les mêmes garde-fous qu'en
 * production, et rend le nombre. Voir « 0 » là où on attendait des centaines
 * est ce qui rattrape une faute de nom de table.
 */
export default function ProbeDrawer({
  probe,
  databases,
  onClose,
  onSaved,
}: {
  probe: Probe | null;
  databases: MonitoredDatabase[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const router = useRouter();
  const toast = useToast();

  const utilisables = databases.filter((b) => b.read_only_verified_at !== null);

  const [databaseId, setDatabaseId] = useState(
    probe?.database_id ?? utilisables[0]?.id ?? "",
  );
  const [titre, setTitre] = useState(probe?.title ?? "");
  const [unite, setUnite] = useState(probe?.unit ?? "événements");
  const [requete, setRequete] = useState(probe?.query ?? EXEMPLE);
  const [fenetres, setFenetres] = useState<WindowDraft[]>(
    probe?.windows.length
      ? probe.windows.map((f) => ({
          hours: f.hours,
          mode: f.mode ?? "glissante",
          direction: f.direction ?? "croissant",
          tiers: f.tiers.join(", "),
        }))
      : FENETRES_PAR_DEFAUT,
  );

  // `null` tant qu'on n'y a pas touché : le serveur laisse alors la liste
  // intacte. Modifier des paliers ne doit pas rouvrir une sonde par omission.
  const [viewers, setViewers] = useState<Personne[] | null>(
    probe?.viewers?.length ? probe.viewers : null,
  );
  const [annuaire, setAnnuaire] = useState<Personne[]>([]);

  const [essai, setEssai] = useState<
    { ok: true; value: number } | { ok: false; error: string } | null
  >(null);
  const [enEssai, setEnEssai] = useState(false);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  // L'annuaire est chargé une fois à l'ouverture : l'équipe tient sur un
  // écran, et un champ de recherche à distance ferait payer un aller-retour
  // vers Francfort à chaque lettre pour choisir parmi cinq personnes.
  useEffect(() => {
    let vivant = true;

    apiFetch("/api/users")
      .then((r) => (r.ok ? r.json() : []))
      .then((gens: Personne[]) => vivant && setAnnuaire(gens))
      .catch(() => {});

    return () => {
      vivant = false;
    };
  }, []);

  /** « 3, 10, 20 » → [3, 10, 20], dédoublonné et croissant. */
  function paliers(texte: string): number[] {
    const nombres = texte
      .split(/[,;\s]+/)
      .map((m) => Number.parseInt(m, 10))
      .filter((n) => Number.isFinite(n) && n > 0);

    return [...new Set(nombres)].sort((a, b) => a - b);
  }

  async function essayer() {
    setEnEssai(true);
    setEssai(null);
    try {
      const res = await apiFetch("/api/monitoring/probes/try", {
        method: "POST",
        body: JSON.stringify({
          database_id: databaseId,
          query: requete,
          hours: fenetres[0]?.hours ?? 24,
        }),
      });
      const corps = await res.json().catch(() => ({}));

      setEssai(
        res.ok && corps.ok
          ? { ok: true, value: corps.value }
          : {
              ok: false,
              error:
                corps.error ??
                corps.detail ??
                `La requête a échoué (${res.status}).`,
            },
      );
    } catch (e) {
      setEssai({
        ok: false,
        error: e instanceof Error ? e.message : "Le serveur n'a pas répondu.",
      });
    } finally {
      setEnEssai(false);
    }
  }

  async function soumettre(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setEnvoi(true);
    setErreur(null);

    try {
      const res = await apiFetch(
        probe ? `/api/monitoring/probes/${probe.id}` : "/api/monitoring/probes",
        {
          method: probe ? "PATCH" : "POST",
          body: JSON.stringify({
            database_id: databaseId,
            title: titre.trim(),
            unit: unite.trim() || "événements",
            query: requete,
            ...(viewers === null ? {} : { viewers: viewers.map((v) => v.id) }),
            windows: fenetres.map((f) => ({
              hours: f.hours,
              mode: f.mode,
              direction: f.direction,
              tiers: paliers(f.tiers),
            })),
          }),
        },
      );

      if (!res.ok) {
        const corps = await res.json().catch(() => ({}));
        throw new Error(
          corps.message ??
            corps.error ??
            `Enregistrement refusé (${res.status}).`,
        );
      }

      toast.success(probe ? "Sonde modifiée" : "Sonde créée", titre);
      onSaved();
    } catch (e) {
      setErreur(e instanceof Error ? e.message : "Enregistrement impossible.");
    } finally {
      setEnvoi(false);
    }
  }

  async function supprimer() {
    if (!probe) return;
    if (!confirm(`Supprimer la sonde « ${probe.title} » ?`)) return;

    try {
      const res = await apiFetch(`/api/monitoring/probes/${probe.id}`, {
        method: "DELETE",
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      toast.success("Sonde supprimée", probe.title);
      router.refresh();
      onSaved();
    } catch (e) {
      setErreur(e instanceof Error ? e.message : "Suppression impossible.");
    }
  }

  const modifieDepuisEssai = probe && requete !== probe.query && essai === null;

  return (
    <Drawer
      large
      title={probe ? "Modifier la sonde" : "Nouvelle sonde"}
      onClose={onClose}
    >
      <form onSubmit={soumettre} className="flex min-h-0 flex-1 flex-col">
        <div className="flex-1 space-y-5 overflow-y-auto px-6 py-5">
          <label className="block">
            <span className="mb-1.5 block text-xs font-medium">Base</span>
            <select
              value={databaseId}
              onChange={(e) => {
                setDatabaseId(e.target.value);
                // La requête n'a pas le même sens d'une base à l'autre :
                // l'essai précédent ne prouve plus rien.
                setEssai(null);
              }}
              required
              className={inputClass}
            >
              {utilisables.length === 0 && (
                <option value="">Aucune base en lecture seule constatée</option>
              )}
              {utilisables.map((base) => (
                <option key={base.id} value={base.id}>
                  {base.name}
                </option>
              ))}
            </select>
          </label>

          <div className="flex gap-3">
            <label className="block flex-1">
              <span className="mb-1.5 block text-xs font-medium">Titre</span>
              <input
                value={titre}
                onChange={(e) => setTitre(e.target.value)}
                required
                maxLength={120}
                placeholder="Time-outs de paiement"
                className={inputClass}
              />
            </label>
            <label className="block w-44">
              <span className="mb-1.5 block text-xs font-medium">Unité</span>
              <input
                value={unite}
                onChange={(e) => setUnite(e.target.value)}
                maxLength={40}
                placeholder="time-outs"
                className={inputClass}
              />
              <span className="mt-1 block text-xs text-[var(--muted)]">
                Ce que le nombre compte.
              </span>
            </label>
          </div>

          <div>
            <div className="mb-1.5 flex items-center gap-3">
              <span className="text-xs font-medium">Requête</span>
              <button
                type="button"
                onClick={essayer}
                disabled={enEssai || !databaseId || !requete.trim()}
                className="inline-flex items-center gap-1.5 rounded-md border border-[var(--border)] px-2.5 py-1 text-xs hover:bg-[var(--surface)] disabled:opacity-40"
              >
                <Play size={11} />
                {enEssai ? "Exécution…" : "Essayer"}
              </button>
            </div>

            <textarea
              value={requete}
              onChange={(e) => {
                setRequete(e.target.value);
                setEssai(null);
              }}
              required
              maxLength={4000}
              rows={8}
              spellCheck={false}
              className={`${inputClass} font-mono text-xs leading-relaxed`}
            />

            <p className="mt-1.5 text-xs text-[var(--muted)]">
              Une colonne nommée{" "}
              <code className="rounded bg-[var(--surface)] px-1 py-0.5 font-mono">
                valeur
              </code>{" "}
              et un paramètre{" "}
              <code className="rounded bg-[var(--surface)] px-1 py-0.5 font-mono">
                :depuis
              </code>
              , qu&apos;Arche remplace par le début de la fenêtre — ou par le
              dernier acquittement s&apos;il est plus récent.
            </p>

            {essai && (
              <p
                className={`mt-2 rounded-md px-3 py-2 text-xs ${
                  essai.ok
                    ? "border border-[var(--color-success)]/40 bg-[var(--color-success)]/5 text-[var(--color-success)]"
                    : "border border-[var(--color-danger)]/40 bg-[var(--color-danger)]/5 text-[var(--color-danger)]"
                }`}
              >
                {essai.ok ? (
                  <>
                    <strong className="tabular-nums">{essai.value}</strong>{" "}
                    {unite || "événements"} sur les {fenetres[0]?.hours ?? 24}{" "}
                    dernières heures.
                  </>
                ) : (
                  essai.error
                )}
              </p>
            )}

            {modifieDepuisEssai && (
              <p className="mt-2 text-xs text-[var(--color-warning)]">
                La requête a changé : essaie-la avant d&apos;enregistrer.
              </p>
            )}
          </div>

          <div>
            <div className="mb-1.5 flex items-center gap-3">
              <span className="text-xs font-medium">Fenêtres et paliers</span>
              {fenetres.length < 4 && (
                <button
                  type="button"
                  onClick={() =>
                    setFenetres((f) => [
                      ...f,
                      {
                        hours: 24,
                        mode: "glissante",
                        direction: "croissant",
                        tiers: "",
                      },
                    ])
                  }
                  className="inline-flex items-center gap-1 text-xs text-[var(--color-brand-red)] hover:underline"
                >
                  <Plus size={11} />
                  ajouter
                </button>
              )}
            </div>

            <div className="space-y-2">
              {/*
                Les trois champs sont nommés une seule fois, au-dessus de la
                liste. Répéter les libellés à chaque ligne tripleraient la
                hauteur d'un bloc qu'on relit rarement ; les omettre laissait
                trois cases nues dont on ne devinait pas le rôle.
              */}
              <div className="flex items-center gap-2 text-[11px] uppercase tracking-wider text-[var(--muted)]">
                <span className="w-20 shrink-0">Durée</span>
                <span className="w-32 shrink-0">Découpage</span>
                <span className="w-28 shrink-0">Alerte si</span>
                <span className="flex-1">Seuils</span>
                {fenetres.length > 1 && <span className="w-8 shrink-0" />}
              </div>

              {fenetres.map((fenetre, i) => (
                <div key={i} className="flex flex-wrap items-start gap-2">
                  <div className="flex w-20 shrink-0 items-center gap-1.5">
                    <input
                      type="number"
                      min={1}
                      max={720}
                      value={fenetre.hours}
                      aria-label="Durée de la fenêtre, en heures"
                      onChange={(e) =>
                        setFenetres((f) =>
                          f.map((w, j) => {
                            if (j !== i) return w;
                            const hours = Number(e.target.value);

                            // Une fenêtre calendaire ne se découpe qu'en
                            // journées entières : « six heures depuis minuit »
                            // changerait de longueur au fil de la journée. On
                            // retombe en glissante plutôt que de laisser le
                            // serveur refuser à l'enregistrement.
                            return {
                              ...w,
                              hours,
                              mode: hours % 24 === 0 ? w.mode : "glissante",
                            };
                          }),
                        )
                      }
                      disabled={ignoresHours(fenetre.mode)}
                      title={
                        ignoresHours(fenetre.mode)
                          ? "Sans effet : la période est fixée par le découpage"
                          : undefined
                      }
                      className={`${inputClass} tabular-nums disabled:opacity-40`}
                    />
                    <span className="text-xs text-[var(--muted)]">h</span>
                  </div>

                  <div className="w-32 shrink-0">
                    <select
                      value={fenetre.mode}
                      aria-label="Découpage de la fenêtre"
                      onChange={(e) =>
                        setFenetres((f) =>
                          f.map((w, j) =>
                            j === i
                              ? { ...w, mode: e.target.value as WindowMode }
                              : w,
                          ),
                        )
                      }
                      title={INFOBULLE_MODE[fenetre.mode]}
                      className={inputClass}
                    >
                      <option value="glissante">glissante</option>
                      <option
                        value="calendaire"
                        disabled={fenetre.hours % 24 !== 0}
                      >
                        calendaire
                      </option>
                      <option value="mensuelle">ce mois-ci</option>
                      <option value="annuelle">cette année</option>
                      <option value="totale">depuis toujours</option>
                    </select>
                  </div>

                  <div className="w-28 shrink-0">
                    <select
                      value={fenetre.direction}
                      aria-label="Sens de dégradation"
                      onChange={(e) =>
                        setFenetres((f) =>
                          f.map((w, j) =>
                            j === i
                              ? { ...w, direction: e.target.value as Direction }
                              : w,
                          ),
                        )
                      }
                      title={
                        fenetre.direction === "croissant"
                          ? "Le danger est en haut : un compte d'erreurs qui grimpe"
                          : "Le danger est en bas : une mesure de santé qui s'effondre"
                      }
                      className={inputClass}
                    >
                      <option value="croissant">ça monte</option>
                      <option value="decroissant">ça descend</option>
                    </select>
                  </div>

                  <div className="min-w-[12rem] flex-1">
                    <input
                      value={fenetre.tiers}
                      aria-label="Seuils de notification"
                      onChange={(e) =>
                        setFenetres((f) =>
                          f.map((w, j) =>
                            j === i ? { ...w, tiers: e.target.value } : w,
                          ),
                        )
                      }
                      placeholder="3, 10, 20, 40, 60, 100"
                      className={`${inputClass} font-mono tabular-nums`}
                    />
                    {fenetre.tiers.trim() !== "" &&
                      paliers(fenetre.tiers).length === 0 && (
                        <span className="mt-1 block text-xs text-[var(--color-warning)]">
                          Aucun palier lisible — des nombres séparés par des
                          virgules.
                        </span>
                      )}
                  </div>

                  {fenetres.length > 1 && (
                    <button
                      type="button"
                      onClick={() =>
                        setFenetres((f) => f.filter((_, j) => j !== i))
                      }
                      aria-label={`Retirer la fenêtre de ${fenetre.hours} h`}
                      className="w-8 shrink-0 rounded-md border border-[var(--border)] p-2 text-[var(--muted)] hover:text-[var(--color-danger)]"
                    >
                      <Trash2 size={12} />
                    </button>
                  )}
                </div>
              ))}
            </div>

            <p className="mt-1.5 text-xs text-[var(--muted)]">
              Une notification par palier franchi, une seule fois. Le comptage
              ne repart qu&apos;au moment où quelqu&apos;un déclare
              l&apos;incident traité — une fenêtre vide de paliers observe sans
              jamais alerter.
            </p>
          </div>

          <div>
            <div className="mb-1.5 flex items-center gap-3">
              <span className="text-xs font-medium">Qui peut la voir</span>
              {viewers !== null && (
                <button
                  type="button"
                  onClick={() => setViewers(null)}
                  className="text-xs text-[var(--color-brand-red)] hover:underline"
                >
                  rouvrir à tous
                </button>
              )}
            </div>

            {viewers === null ? (
              <p className="text-xs text-[var(--muted)]">
                Toute l&apos;équipe qui a le droit de superviser.{" "}
                <button
                  type="button"
                  onClick={() => setViewers([])}
                  className="text-[var(--color-brand-red)] hover:underline"
                >
                  Restreindre
                </button>
              </p>
            ) : (
              <>
                <div className="flex flex-wrap gap-1.5">
                  {annuaire.map((gens) => {
                    const choisi = viewers.some((v) => v.id === gens.id);

                    return (
                      <button
                        key={gens.id}
                        type="button"
                        aria-pressed={choisi}
                        onClick={() =>
                          setViewers((v) =>
                            choisi
                              ? (v ?? []).filter((x) => x.id !== gens.id)
                              : [...(v ?? []), gens],
                          )
                        }
                        className={`rounded-full border px-2.5 py-1 text-xs transition-colors ${
                          choisi
                            ? "border-[var(--color-brand-red)] bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]"
                            : "border-[var(--border)] text-[var(--muted)] hover:text-[var(--foreground)]"
                        }`}
                      >
                        {gens.name ?? gens.email}
                      </button>
                    );
                  })}
                </div>

                <p className="mt-1.5 text-xs text-[var(--muted)]">
                  {viewers.length === 0
                    ? "Personne de sélectionné : la sonde reste visible de tous. Une liste vide ne cache rien."
                    : "Seules ces personnes la voient, et seules elles reçoivent ses alertes — une notification arrive sur un écran verrouillé, là où on ne contrôle plus rien."}{" "}
                  Les administrateurs de la supervision la voient de toute façon
                  : ils peuvent en modifier la requête et l&apos;exécuter.
                </p>
              </>
            )}
          </div>

          {erreur && (
            <p className="rounded-md border border-[var(--color-danger)]/40 bg-[var(--color-danger)]/5 px-3 py-2.5 text-xs text-[var(--color-danger)]">
              {erreur}
            </p>
          )}
        </div>

        <footer className="flex items-center gap-2 border-t border-[var(--border)] px-6 py-4">
          {probe && (
            <button
              type="button"
              onClick={supprimer}
              className="rounded-md px-3 py-1.5 text-sm text-[var(--muted)] hover:text-[var(--color-danger)]"
            >
              Supprimer
            </button>
          )}
          <div className="flex-1" />
          <button
            type="button"
            onClick={onClose}
            className="rounded-md px-3 py-1.5 text-sm text-[var(--muted)] hover:text-[var(--foreground)]"
          >
            Annuler
          </button>
          <button
            type="submit"
            disabled={envoi || !databaseId}
            className="rounded-md bg-[var(--color-brand-red)] px-3.5 py-1.5 text-sm font-medium text-white hover:bg-[var(--color-brand-red-600)] disabled:opacity-50"
          >
            {envoi ? "Enregistrement…" : probe ? "Enregistrer" : "Créer"}
          </button>
        </footer>
      </form>
    </Drawer>
  );
}

const inputClass =
  "w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm outline-none focus:border-[var(--color-brand-red)]";
