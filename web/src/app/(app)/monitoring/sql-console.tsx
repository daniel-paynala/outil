"use client";

import { useState, type FormEvent } from "react";
import { ChevronDown, ChevronRight, ShieldCheck, Terminal } from "lucide-react";

import { apiFetch } from "@/lib/api/client";
import type { MonitoredDatabase } from "@/lib/monitoring/types";

type Resultat = {
  colonnes: string[];
  lignes: Record<string, unknown>[];
  total: number;
  tronque: boolean;
  duree_ms: number;
};

const EXEMPLE = `select indexname, indexdef
from pg_indexes
where tablename = 'airtel_logs';`;

/**
 * Une console SQL en lecture seule sur une base surveillée.
 *
 * ## Pourquoi elle existe
 *
 * Arche détient déjà les identifiants, chiffrés, et une connexion dont la
 * lecture seule a été constatée. Sans cette console, la moindre question —
 * « y a-t-il un index sur cette colonne ? » — obligeait à ouvrir DBeaver et
 * donc à ressortir des identifiants de production du coffre où ils sont
 * justement rangés.
 *
 * ## Pourquoi elle n'ouvre aucune porte
 *
 * Qui administre la supervision écrit déjà les requêtes des sondes et les
 * exécute par « Essayer ». La console ne donne pas un accès de plus : elle
 * affiche des lignes au lieu d'un seul nombre.
 *
 * ## Ce qui protège
 *
 * Pas le texte de la requête — chercher des mots interdits serait une
 * passoire. La transaction est ouverte `read only` côté serveur, et Postgres
 * refuse toute écriture quels que soient les droits du compte. Tout est annulé
 * à la fin, et chaque exécution laisse une trace dans le journal d'audit.
 */
export default function SqlConsole({
  databases,
}: {
  databases: MonitoredDatabase[];
}) {
  const utilisables = databases.filter((b) => b.read_only_verified_at !== null);

  const [ouverte, setOuverte] = useState(false);
  const [databaseId, setDatabaseId] = useState(utilisables[0]?.id ?? "");
  const [sql, setSql] = useState(EXEMPLE);
  const [delai, setDelai] = useState(15000);
  const [enCours, setEnCours] = useState(false);
  const [resultat, setResultat] = useState<Resultat | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);

  async function executer(e: FormEvent) {
    e.preventDefault();
    setEnCours(true);
    setErreur(null);
    setResultat(null);

    try {
      const res = await apiFetch(
        `/api/monitoring/databases/${databaseId}/query`,
        {
          method: "POST",
          body: JSON.stringify({ sql, timeout_ms: delai }),
        },
      );
      const corps = await res.json().catch(() => ({}));

      if (!res.ok || !corps.ok) {
        setErreur(corps.error ?? corps.detail ?? `Échec (HTTP ${res.status}).`);
        return;
      }

      setResultat(corps as Resultat);
    } catch (e) {
      setErreur(e instanceof Error ? e.message : "Le serveur n'a pas répondu.");
    } finally {
      setEnCours(false);
    }
  }

  if (utilisables.length === 0) return null;

  return (
    <section className="rounded-lg border border-[var(--border)]">
      <button
        type="button"
        onClick={() => setOuverte((o) => !o)}
        aria-expanded={ouverte}
        className="flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-medium"
      >
        {ouverte ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
        <Terminal size={14} className="text-[var(--muted)]" />
        Console SQL
        <span className="font-normal text-[var(--muted)]">
          — lecture seule, sur une base surveillée
        </span>
      </button>

      {ouverte && (
        <form
          onSubmit={executer}
          className="space-y-3 border-t border-[var(--border)] px-4 py-4"
        >
          <p className="flex gap-2.5 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2.5 text-xs text-[var(--muted)]">
            <ShieldCheck
              size={15}
              className="mt-px shrink-0 text-[var(--color-success)]"
            />
            <span>
              La transaction est ouverte{" "}
              <strong className="font-medium text-[var(--foreground)]">
                read only
              </strong>{" "}
              : Postgres refuse toute écriture, quels que soient les droits du
              compte, et tout est annulé à la fin. Rien n&apos;est filtré dans
              le texte — chercher des mots interdits serait une passoire. Chaque
              exécution laisse une trace dans le journal d&apos;audit.
            </span>
          </p>

          <div className="flex flex-wrap gap-2">
            <select
              value={databaseId}
              onChange={(e) => setDatabaseId(e.target.value)}
              aria-label="Base à interroger"
              className={`${champ} w-56`}
            >
              {utilisables.map((base) => (
                <option key={base.id} value={base.id}>
                  {base.name}
                </option>
              ))}
            </select>

            <select
              value={delai}
              onChange={(e) => setDelai(Number(e.target.value))}
              aria-label="Délai maximum"
              className={`${champ} w-36`}
            >
              <option value={8000}>8 secondes</option>
              <option value={15000}>15 secondes</option>
              <option value={30000}>30 secondes</option>
              <option value={60000}>60 secondes</option>
            </select>

            <div className="flex-1" />

            <button
              type="submit"
              disabled={enCours || !sql.trim()}
              className="rounded-md bg-[var(--color-brand-red)] px-3.5 py-1.5 text-sm font-medium text-white hover:bg-[var(--color-brand-red-600)] disabled:opacity-50"
            >
              {enCours ? "Exécution…" : "Exécuter"}
            </button>
          </div>

          <textarea
            value={sql}
            onChange={(e) => setSql(e.target.value)}
            rows={6}
            spellCheck={false}
            aria-label="Requête SQL"
            className={`${champ} w-full font-mono text-xs leading-relaxed`}
          />

          {erreur && (
            <p className="rounded-md border border-[var(--color-danger)]/40 bg-[var(--color-danger)]/5 px-3 py-2.5 font-mono text-xs text-[var(--color-danger)]">
              {erreur}
            </p>
          )}

          {resultat && (
            <div className="space-y-2">
              <p className="text-xs text-[var(--muted)]">
                {resultat.total} ligne{resultat.total > 1 ? "s" : ""} en{" "}
                {resultat.duree_ms} ms
                {resultat.tronque &&
                  ` · ${resultat.lignes.length} affichées, le reste est coupé`}
              </p>

              {resultat.lignes.length > 0 && (
                <div className="overflow-x-auto rounded-md border border-[var(--border)]">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="border-b border-[var(--border)] bg-[var(--surface)] text-left uppercase tracking-wider text-[var(--muted)]">
                        {resultat.colonnes.map((c) => (
                          <th key={c} className="px-3 py-2 font-medium">
                            {c}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-[var(--border)]">
                      {resultat.lignes.map((ligne, i) => (
                        <tr key={i}>
                          {resultat.colonnes.map((c) => (
                            <td
                              key={c}
                              className="whitespace-pre-wrap px-3 py-1.5 font-mono"
                            >
                              {formater(ligne[c])}
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </form>
      )}
    </section>
  );
}

const champ =
  "rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm outline-none focus:border-[var(--color-brand-red)]";

/** `null` doit se voir comme `null`, pas comme une cellule vide. */
function formater(v: unknown): string {
  if (v === null || v === undefined) return "∅";
  if (typeof v === "object") return JSON.stringify(v);

  return String(v);
}
