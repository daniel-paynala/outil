"use client";

import {
  createContext,
  useContext,
  useMemo,
  useState,
  useTransition,
} from "react";
import { useRouter } from "next/navigation";
import {
  AlertTriangle,
  Check,
  Database as DatabaseIcon,
  Lock,
  Pencil,
  Plus,
  RefreshCw,
  ShieldCheck,
  Trash2,
} from "lucide-react";

import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import { useMonitoringClock } from "@/lib/monitoring/clock";
import {
  ago as depuis,
  COULEUR_NIVEAU,
  LIBELLE_NIVEAU,
  probeLevel,
  windowLevel,
  type Level,
  hasIncident,
  nextTier,
  periodLabel,
  seuilLabel,
  severity,
  type MonitoredDatabase,
  type MonitoringAlert,
  type Probe,
  type ProbeWindow,
} from "@/lib/monitoring/types";

import { INFOBULLE_MODE } from "./modes";
import DatabaseDrawer from "./database-drawer";
import SqlConsole from "./sql-console";
import ProbeDrawer from "./probe-drawer";

/**
 * L'heure de l'écran, avancée toutes les 30 s.
 *
 * Ambiante plutôt que passée de composant en composant : « quelle heure
 * est-il » n'appartient à aucune section, et les quatre qui l'utilisent sont à
 * trois niveaux d'imbrication les unes des autres.
 */
const Horloge = createContext(0);

/** L'heure courante de l'écran, à passer à `depuis`. */
function useHorloge(): number {
  return useContext(Horloge);
}

/**
 * La pastille d'état : verte, orange ou rouge.
 *
 * Un disque plein et rien d'autre. Une icône par état demanderait de
 * reconnaître trois dessins ; la couleur seule se lit sans être regardée, et
 * c'est ce qu'on attend d'un tableau qu'on balaie du regard.
 *
 * Le `title` porte le sens en toutes lettres : la couleur seule exclurait les
 * daltoniens, qui sont une personne sur douze.
 */
function Pastille({ level, size = 9 }: { level: Level; size?: number }) {
  return (
    <span
      role="img"
      aria-label={LIBELLE_NIVEAU[level]}
      title={LIBELLE_NIVEAU[level]}
      style={{
        background: COULEUR_NIVEAU[level],
        height: size,
        width: size,
      }}
      className="inline-block shrink-0 rounded-full ring-1 ring-[var(--background)]"
    />
  );
}

export default function MonitoringBoard({
  probes,
  openIncidents,
  databases,
  alerts,
  canAdmin,
}: {
  probes: Probe[];
  openIncidents: string[];
  databases: MonitoredDatabase[];
  alerts: MonitoringAlert[];
  canAdmin: boolean;
}) {
  const maintenant = useMonitoringClock();
  const router = useRouter();
  const toast = useToast();
  const [, startTransition] = useTransition();

  const [acquittement, setAcquittement] = useState<string | null>(null);
  const [sondeOuverte, setSondeOuverte] = useState<Probe | "nouvelle" | null>(
    null,
  );
  // `"nouvelle"` pour brancher, une base pour la modifier, `null` fermé.
  const [baseOuverte, setBaseOuverte] = useState<
    MonitoredDatabase | "nouvelle" | null
  >(null);

  /**
   * Ce qui attend un geste d'abord, du plus grave au moins grave.
   *
   * On n'ouvre pas cet écran par curiosité : on l'ouvre parce qu'une alerte a
   * sonné, et la première chose lue doit être ce qui l'a déclenchée.
   */
  const incidents = useMemo(
    () =>
      probes
        .filter((p) => openIncidents.includes(p.id) || hasIncident(p))
        .sort((a, b) => severity(b) - severity(a)),
    [probes, openIncidents],
  );

  const calmes = useMemo(
    () => probes.filter((p) => !incidents.includes(p)),
    [probes, incidents],
  );

  async function acquitter(sonde: Probe) {
    setAcquittement(sonde.id);
    try {
      const res = await apiFetch(
        `/api/monitoring/probes/${sonde.id}/acknowledge`,
        { method: "POST" },
      );
      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      toast.success(
        "Incident acquitté",
        `Le comptage de « ${sonde.title} » repart de maintenant.`,
      );
      startTransition(() => router.refresh());
    } catch (e) {
      toast.error(
        "Acquittement impossible",
        e instanceof Error ? e.message : "Réessaie dans un instant.",
      );
    } finally {
      setAcquittement(null);
    }
  }

  return (
    <Horloge.Provider value={maintenant}>
      <div className="space-y-8">
        <header className="flex flex-wrap items-end gap-4">
          <div className="flex-1 min-w-64">
            <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
              Supervision
            </p>
            <h1 className="mt-1 text-2xl font-semibold tracking-tight">
              Bases de production
            </h1>
            <p className="mt-2 text-sm text-[var(--muted)]">
              {incidents.length === 0
                ? `${probes.length} sonde${probes.length > 1 ? "s" : ""} en veille sur ${databases.length} base${databases.length > 1 ? "s" : ""}. Rien à signaler.`
                : `${incidents.length} incident${incidents.length > 1 ? "s" : ""} en attente d'acquittement.`}
            </p>
          </div>

          {canAdmin && (
            <div className="flex gap-2">
              <button
                onClick={() => setBaseOuverte("nouvelle")}
                className="inline-flex items-center gap-1.5 rounded-md border border-[var(--border)] px-3 py-1.5 text-sm hover:bg-[var(--surface)]"
              >
                <DatabaseIcon size={14} />
                Brancher une base
              </button>
              <button
                onClick={() => setSondeOuverte("nouvelle")}
                disabled={databases.length === 0}
                className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] px-3 py-1.5 text-sm font-medium text-white hover:bg-[var(--color-brand-red-600)] disabled:opacity-40"
              >
                <Plus size={14} />
                Nouvelle sonde
              </button>
            </div>
          )}
        </header>

        {incidents.length > 0 && (
          <section className="space-y-3">
            <h2 className="flex items-center gap-2 text-sm font-medium">
              <AlertTriangle size={15} className="text-[var(--color-danger)]" />
              En attente d&apos;acquittement
            </h2>
            {incidents.map((sonde) => (
              <ProbeCard
                key={sonde.id}
                probe={sonde}
                canAdmin={canAdmin}
                acknowledging={acquittement === sonde.id}
                onAcknowledge={() => acquitter(sonde)}
                onEdit={() => setSondeOuverte(sonde)}
              />
            ))}
          </section>
        )}

        {calmes.length > 0 && (
          <section className="space-y-3">
            <h2 className="text-sm font-medium text-[var(--muted)]">
              Sous surveillance
            </h2>
            {calmes.map((sonde) => (
              <ProbeCard
                key={sonde.id}
                probe={sonde}
                canAdmin={canAdmin}
                acknowledging={false}
                onEdit={() => setSondeOuverte(sonde)}
              />
            ))}
          </section>
        )}

        {probes.length === 0 && (
          <p className="rounded-lg border border-dashed border-[var(--border)] px-6 py-10 text-center text-sm text-[var(--muted)]">
            {databases.length === 0
              ? "Aucune base branchée. Commence par en ajouter une — Arche vérifiera que l'accès est bien en lecture seule avant de l'enregistrer."
              : "Aucune sonde. Une base branchée sans sonde n'est pas surveillée : elle est simplement joignable."}
          </p>
        )}

        <DatabaseList
          databases={databases}
          canAdmin={canAdmin}
          onAdd={() => setBaseOuverte("nouvelle")}
          onEdit={(base) => setBaseOuverte(base)}
        />

        {canAdmin && <SqlConsole databases={databases} />}

        <AlertHistory alerts={alerts} />

        {sondeOuverte && (
          <ProbeDrawer
            probe={sondeOuverte === "nouvelle" ? null : sondeOuverte}
            databases={databases}
            onClose={() => setSondeOuverte(null)}
            onSaved={() => {
              setSondeOuverte(null);
              startTransition(() => router.refresh());
            }}
          />
        )}

        {baseOuverte && (
          <DatabaseDrawer
            database={baseOuverte === "nouvelle" ? null : baseOuverte}
            onClose={() => setBaseOuverte(null)}
            onSaved={() => {
              setBaseOuverte(null);
              startTransition(() => router.refresh());
            }}
          />
        )}
      </div>
    </Horloge.Provider>
  );
}

/**
 * Une sonde et l'état de chacune de ses fenêtres.
 *
 * La bordure gauche porte la gravité : rouge quand un palier est franchi, muette
 * sinon. C'est ce qui permet de balayer une liste de vingt sondes sans lire un
 * seul chiffre.
 */
function ProbeCard({
  probe,
  canAdmin,
  acknowledging,
  onAcknowledge,
  onEdit,
}: {
  probe: Probe;
  canAdmin: boolean;
  acknowledging: boolean;
  onAcknowledge?: () => void;
  onEdit: () => void;
}) {
  const maintenant = useHorloge();
  const ouvert = hasIncident(probe);
  const niveau = probeLevel(probe);
  const baseInerte = probe.database?.read_only_verified_at == null;

  return (
    <article
      style={
        niveau === "calme"
          ? undefined
          : {
              borderColor: `color-mix(in srgb, ${COULEUR_NIVEAU[niveau]} 40%, transparent)`,
              borderLeftColor: COULEUR_NIVEAU[niveau],
            }
      }
      className={`rounded-lg border bg-[var(--background)] ${
        niveau === "calme" ? "border-[var(--border)]" : "border-l-[3px]"
      }`}
    >
      <div className="flex flex-wrap items-start gap-3 px-4 py-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <Pastille level={niveau} size={10} />
            <h3 className="truncate text-sm font-medium">{probe.title}</h3>
            {!probe.enabled && (
              <span className="rounded bg-[var(--surface)] px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-[var(--muted)]">
                en pause
              </span>
            )}
            {(probe.viewers?.length ?? 0) > 0 && (
              <span
                title={`Restreinte à ${probe.viewers
                  ?.map((v) => v.name ?? v.email)
                  .join(", ")}`}
                className="inline-flex items-center gap-1 rounded bg-[var(--surface)] px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-[var(--muted)]"
              >
                <Lock size={9} />
                {probe.viewers?.length}
              </span>
            )}
          </div>
          <p className="mt-0.5 truncate text-xs text-[var(--muted)]">
            {probe.database?.name ?? "base inconnue"}
            {probe.counting_from &&
              ` · compté depuis ${depuis(probe.counting_from, maintenant)}`}
          </p>
        </div>

        <div className="flex items-center gap-2">
          {ouvert && onAcknowledge && (
            <button
              onClick={onAcknowledge}
              disabled={acknowledging}
              className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-danger)] px-3 py-1.5 text-xs font-medium text-white hover:opacity-90 disabled:opacity-50"
            >
              <Check size={13} />
              {acknowledging ? "Acquittement…" : "C'est traité"}
            </button>
          )}
          {canAdmin && (
            <button
              onClick={onEdit}
              aria-label={`Modifier ${probe.title}`}
              className="rounded-md border border-[var(--border)] p-1.5 text-[var(--muted)] hover:text-[var(--foreground)]"
            >
              <Pencil size={13} />
            </button>
          )}
        </div>
      </div>

      {baseInerte && (
        <p className="border-t border-[var(--border)] px-4 py-2 text-xs text-[var(--color-warning)]">
          La lecture seule n&apos;est plus constatée sur cette base : la sonde
          n&apos;est pas exécutée.
        </p>
      )}

      {probe.last_error && (
        <p className="border-t border-[var(--border)] px-4 py-2 text-xs text-[var(--color-warning)]">
          {probe.last_error}
        </p>
      )}

      <div className="grid gap-px border-t border-[var(--border)] bg-[var(--border)] sm:grid-cols-2 lg:grid-cols-3">
        {probe.windows.map((fenetre, i) => (
          <WindowCell
            key={fenetre.id ?? i}
            window={fenetre}
            unit={probe.unit}
          />
        ))}
      </div>

      {ouvert && probe.acknowledger && (
        <p className="border-t border-[var(--border)] px-4 py-2 text-xs text-[var(--muted)]">
          Dernier acquittement par{" "}
          {probe.acknowledger.name ?? probe.acknowledger.email}.
        </p>
      )}
    </article>
  );
}

/**
 * Une fenêtre : sa durée, sa valeur, et la distance au prochain palier.
 *
 * Le prochain palier compte autant que la valeur courante. « 7 time-outs » ne
 * dit rien seul ; « 7, prochain palier 10 » dit qu'on est à trois de plus d'une
 * alerte, et c'est cette phrase-là qui fait décrocher un téléphone avant qu'il
 * ne sonne.
 */
function WindowCell({
  window: fenetre,
  unit,
  jalon = false,
}: {
  window: ProbeWindow;
  unit: string;
  jalon?: boolean;
}) {
  const maintenant = useHorloge();
  const valeur = fenetre.last_value;
  const suivant = nextTier(fenetre);
  const franchi = fenetre.severest_tier > 0;
  const niveau = windowLevel(fenetre, jalon);
  const seuil = seuilLabel(fenetre, jalon);

  return (
    <div className="bg-[var(--background)] px-4 py-3">
      <p
        className="flex items-center gap-2 text-[11px] uppercase tracking-wider text-[var(--muted)]"
        title={INFOBULLE_MODE[fenetre.mode ?? "glissante"]}
      >
        <Pastille level={niveau} />
        {periodLabel(fenetre)}
      </p>
      <p className="mt-1 flex items-baseline gap-1.5">
        <span
          style={franchi ? { color: COULEUR_NIVEAU[niveau] } : undefined}
          className="text-xl font-semibold tabular-nums"
        >
          {valeur === null ? "—" : valeur.toLocaleString("fr-FR")}
        </span>
        <span className="text-xs text-[var(--muted)]">{unit}</span>
      </p>
      {fenetre.last_detail && Object.keys(fenetre.last_detail).length > 0 && (
        /*
          Une ligne par colonne rendue, le nom à gauche et le montant aligné à
          droite. Des nombres qu'on compare se lisent en colonne, jamais en
          phrase : quatre portefeuilles bout à bout obligent à chercher où
          finit l'un et où commence l'autre.
        */
        <dl className="mt-1.5 space-y-0.5 text-xs text-[var(--muted)]">
          {Object.entries(fenetre.last_detail).map(([nom, v]) => (
            <div
              key={nom}
              className="flex items-baseline justify-between gap-3"
            >
              <dt className="truncate">{nom}</dt>
              <dd className="shrink-0 font-medium tabular-nums text-[var(--foreground)]">
                {typeof v === "number" ? v.toLocaleString("fr-FR") : v}
              </dd>
            </div>
          ))}
        </dl>
      )}

      <p className="mt-1 text-xs text-[var(--muted)]">
        {franchi
          ? `${seuil} ${fenetre.severest_tier.toLocaleString("fr-FR")} ${jalon ? "atteint" : "franchi"}`
          : suivant !== null
            ? `prochain ${seuil} ${suivant.toLocaleString("fr-FR")}`
            : fenetre.tiers.length === 0
              ? "observée, sans palier"
              : "au-delà du dernier palier"}
        {" · "}
        {depuis(fenetre.last_run_at, maintenant)}
      </p>
    </div>
  );
}

/** Les bases branchées, et l'état de la vérification de lecture seule. */
function DatabaseList({
  databases,
  canAdmin,
  onAdd,
  onEdit,
}: {
  databases: MonitoredDatabase[];
  canAdmin: boolean;
  onAdd: () => void;
  onEdit: (base: MonitoredDatabase) => void;
}) {
  const maintenant = useHorloge();
  const router = useRouter();
  const toast = useToast();
  const [enCours, setEnCours] = useState<string | null>(null);

  async function verifier(base: MonitoredDatabase) {
    setEnCours(base.id);
    try {
      const res = await apiFetch(
        `/api/monitoring/databases/${base.id}/verify`,
        { method: "POST" },
      );
      const corps: MonitoredDatabase = await res.json();

      if (corps.read_only_verified_at) {
        toast.success("Lecture seule confirmée", base.name);
      } else {
        toast.error(
          "Base écartée",
          corps.last_error ?? "L'écriture n'a pas été refusée.",
        );
      }
      router.refresh();
    } catch (e) {
      toast.error(
        "Vérification impossible",
        e instanceof Error ? e.message : "Réessaie dans un instant.",
      );
    } finally {
      setEnCours(null);
    }
  }

  async function retirer(base: MonitoredDatabase) {
    if (
      !confirm(
        `Retirer « ${base.name} » ? Ses ${base.probes_count ?? 0} sonde(s) ` +
          `disparaissent avec elle, ainsi que leur comptage. Pour seulement ` +
          `la renommer ou changer ses identifiants, utilise le crayon.`,
      )
    ) {
      return;
    }

    setEnCours(base.id);
    try {
      const res = await apiFetch(`/api/monitoring/databases/${base.id}`, {
        method: "DELETE",
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      toast.success("Base retirée", base.name);
      router.refresh();
    } catch (e) {
      toast.error(
        "Suppression impossible",
        e instanceof Error ? e.message : "Réessaie dans un instant.",
      );
    } finally {
      setEnCours(null);
    }
  }

  return (
    <section className="space-y-3">
      <div className="flex items-center gap-3">
        <h2 className="text-sm font-medium text-[var(--muted)]">
          Bases branchées
        </h2>
        {canAdmin && (
          <button
            onClick={onAdd}
            className="text-xs text-[var(--color-brand-red)] hover:underline"
          >
            + ajouter
          </button>
        )}
      </div>

      {databases.length === 0 ? (
        <p className="text-sm text-[var(--muted)]">Aucune base branchée.</p>
      ) : (
        <ul className="divide-y divide-[var(--border)] rounded-lg border border-[var(--border)]">
          {databases.map((base) => (
            <li
              key={base.id}
              className="flex flex-wrap items-center gap-3 px-4 py-3"
            >
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <span className="truncate text-sm font-medium">
                    {base.name}
                  </span>
                  {base.read_only_verified_at ? (
                    <span
                      title={`Lecture seule constatée ${depuis(base.read_only_verified_at, maintenant)}`}
                      className="inline-flex items-center gap-1 rounded bg-[var(--color-success)]/10 px-1.5 py-0.5 text-[10px] font-medium text-[var(--color-success)]"
                    >
                      <ShieldCheck size={11} />
                      lecture seule
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1 rounded bg-[var(--color-warning)]/10 px-1.5 py-0.5 text-[10px] font-medium text-[var(--color-warning)]">
                      <AlertTriangle size={11} />
                      inerte
                    </span>
                  )}
                </div>
                <p className="mt-0.5 truncate font-mono text-xs text-[var(--muted)]">
                  {base.username}@{base.host}:{base.port}/{base.dbname}
                  {" · "}
                  {base.probes_count ?? 0} sonde
                  {(base.probes_count ?? 0) > 1 ? "s" : ""}
                </p>
                {base.last_error && (
                  <p className="mt-1 text-xs text-[var(--color-danger)]">
                    {base.last_error}
                  </p>
                )}
              </div>

              {canAdmin && (
                <div className="flex items-center gap-1.5">
                  <button
                    onClick={() => verifier(base)}
                    disabled={enCours === base.id}
                    className="inline-flex items-center gap-1.5 rounded-md border border-[var(--border)] px-2.5 py-1.5 text-xs hover:bg-[var(--surface)] disabled:opacity-50"
                  >
                    <RefreshCw
                      size={12}
                      className={enCours === base.id ? "animate-spin" : ""}
                    />
                    Revérifier
                  </button>
                  <button
                    onClick={() => onEdit(base)}
                    aria-label={`Modifier ${base.name}`}
                    title="Renommer, ou changer les identifiants"
                    className="rounded-md border border-[var(--border)] p-1.5 text-[var(--muted)] hover:text-[var(--foreground)]"
                  >
                    <Pencil size={12} />
                  </button>
                  <button
                    onClick={() => retirer(base)}
                    disabled={enCours === base.id}
                    aria-label={`Retirer ${base.name}`}
                    className="rounded-md border border-[var(--border)] p-1.5 text-[var(--muted)] hover:text-[var(--color-danger)] disabled:opacity-50"
                  >
                    <Trash2 size={12} />
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/**
 * Les franchissements passés.
 *
 * Utile après coup, pas pendant : un incident se lit en haut de page. Ce
 * tableau sert à répondre à « est-ce que ça recommence ? », question qu'on se
 * pose une fois le calme revenu.
 */
function AlertHistory({ alerts }: { alerts: MonitoringAlert[] }) {
  const maintenant = useHorloge();

  if (alerts.length === 0) return null;

  return (
    <section className="space-y-3">
      <h2 className="text-sm font-medium text-[var(--muted)]">
        Franchissements passés
      </h2>
      <div className="overflow-x-auto rounded-lg border border-[var(--border)]">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-[var(--border)] bg-[var(--surface)] text-left text-xs uppercase tracking-wider text-[var(--muted)]">
              <th className="px-4 py-2 font-medium">Sonde</th>
              <th className="px-4 py-2 font-medium">Fenêtre</th>
              <th className="px-4 py-2 text-right font-medium">Palier</th>
              <th className="px-4 py-2 text-right font-medium">Valeur</th>
              <th className="px-4 py-2 font-medium">Quand</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-[var(--border)]">
            {alerts.map((alerte) => (
              <tr key={alerte.id}>
                <td className="px-4 py-2">
                  {alerte.probe?.title ?? "sonde supprimée"}
                </td>
                <td className="px-4 py-2 text-[var(--muted)]">
                  {alerte.window_hours} h
                </td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {alerte.tier}
                </td>
                <td className="px-4 py-2 text-right tabular-nums">
                  {alerte.value}
                </td>
                <td className="px-4 py-2 text-[var(--muted)]">
                  {depuis(alerte.raised_at, maintenant)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
