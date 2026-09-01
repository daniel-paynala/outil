/**
 * La supervision des bases de production, côté dashboard.
 *
 * C'est ici qu'on branche une base et qu'on écrit ses sondes : l'insertion se
 * fait au clavier, devant un écran large. Le mobile, lui, ne fait que recevoir
 * l'alerte et l'acquitter — on n'écrit pas une requête SQL au pouce.
 */

/** Une base surveillée. Le mot de passe ne repart jamais du serveur. */
export type MonitoredDatabase = {
  id: string;
  name: string;
  host: string;
  port: number;
  dbname: string;
  username: string;

  /**
   * Quand la lecture seule a été **constatée** — pas déclarée.
   *
   * `null` veut dire que la base est inerte : elle ne sera pas interrogée. Une
   * base ajoutée avec des identifiants trop puissants doit rester à l'arrêt
   * plutôt que d'être surveillée « en attendant ».
   */
  read_only_verified_at: string | null;

  last_error: string | null;
  probes_count?: number;
  created_at: string | null;
};

/** Une durée d'observation, ses paliers, et l'état du signalement. */
/**
 * Comment la fenêtre découpe le temps.
 *
 * `glissante` — les N dernières heures, à tout instant. Elle attrape une rafale
 * à cheval sur minuit : deux incidents à 23 h et deux à 1 h font quatre.
 *
 * `calendaire` — depuis minuit, heure de Libreville. Elle dit ce que tout le
 * monde entend par « trois time-outs dans la journée », se recoupe avec les
 * rapports, et repart à zéro chaque nuit sans acquittement — mais coupe en deux
 * les rafales nocturnes.
 *
 * La première détecte mieux, la seconde se décide mieux. D'où un choix par
 * fenêtre plutôt qu'un arbitrage imposé.
 */
export type WindowMode = "glissante" | "calendaire";

export type ProbeWindow = {
  id?: string;
  hours: number;
  mode?: WindowMode;

  /** Croissants. Vide = on observe sans alerter. */
  tiers: number[];

  /**
   * Le plus haut palier franchi depuis le dernier acquittement.
   * Au-dessus de zéro, un incident attend un geste.
   */
  highest_tier: number;

  last_value: number | null;
  last_run_at: string | null;
};

export type Probe = {
  id: string;
  database_id: string;
  title: string;

  /** Ce que le nombre compte — « 12 time-outs » plutôt que « 12 ». */
  unit: string;

  query: string;
  enabled: boolean;

  /** Depuis quand on compte. Posé au dernier acquittement. */
  counting_from: string | null;

  windows: ProbeWindow[];
  database?: Pick<
    MonitoredDatabase,
    "id" | "name" | "read_only_verified_at" | "last_error"
  >;
  acknowledger?: {
    id: string;
    name: string | null;
    email: string;
    avatar_url?: string | null;
  } | null;
};

/** Ce que rend `GET /api/monitoring/probes`. */
export type ProbesResponse = {
  probes: Probe[];

  /** Les sondes qui attendent un geste, déjà triées par le serveur. */
  open_incidents: string[];
};

export type MonitoringAlert = {
  id: string;
  probe_id: string;
  window_hours: number;
  tier: number;
  value: number;
  raised_at: string;
  probe?: { id: string; title: string; database_id: string };
};

/**
 * Où en est une sonde, en trois états qui se lisent d'un coup d'œil.
 *
 * Trois et non deux : « ça va » et « ça ne va pas » obligeraient à lire les
 * chiffres pour savoir s'il faut se lever maintenant ou après le café. Le
 * premier palier franchi est un avertissement — c'est le seuil qu'on a placé
 * justement pour voir venir. Au-delà, la chose s'aggrave, et la couleur doit le
 * dire sans qu'on ait à comparer deux nombres.
 */
export type Level = "calme" | "premier" | "aggrave";

/** Du moins grave au plus grave — sert à prendre le pire de deux états. */
const ORDRE: Level[] = ["calme", "premier", "aggrave"];

export function pire(a: Level, b: Level): Level {
  return ORDRE.indexOf(a) >= ORDRE.indexOf(b) ? a : b;
}

/**
 * L'état d'une fenêtre.
 *
 * Comparé au **premier palier de cette fenêtre**, jamais à une valeur en dur :
 * une sonde de time-outs commence à 1, une sonde de soldes insuffisants à 20.
 * « Le premier palier » est la seule formulation qui vaille pour les deux.
 */
export function windowLevel(w: ProbeWindow): Level {
  if (w.highest_tier <= 0) return "calme";
  if (w.tiers.length === 0) return "aggrave";

  return w.highest_tier === w.tiers[0] ? "premier" : "aggrave";
}

/**
 * L'état d'une sonde : celui de sa fenêtre la plus alarmante.
 *
 * Le pire l'emporte. Une sonde calme sur 48 h mais aggravée sur 24 h est une
 * sonde aggravée : c'est la fenêtre courte qui décrit ce qui se passe
 * maintenant, et une pastille verte lui donnerait tort.
 */
export function probeLevel(p: Probe): Level {
  return p.windows.reduce<Level>(
    (max, w) => pire(max, windowLevel(w)),
    "calme",
  );
}

/** La couleur de chaque état, prise dans les jetons du thème. */
export const COULEUR_NIVEAU: Record<Level, string> = {
  calme: "var(--color-success)",
  premier: "var(--color-warning)",
  aggrave: "var(--color-danger)",
};

export const LIBELLE_NIVEAU: Record<Level, string> = {
  calme: "Sous le premier palier",
  premier: "Premier palier franchi",
  aggrave: "Au-delà du premier palier",
};

/**
 * Un incident est ouvert dès qu'une fenêtre porte un palier franchi.
 *
 * Volontairement pas déduit de la valeur courante : celle-ci peut redescendre
 * sans que personne n'ait rien fait — une base qui a cessé de répondre arrête
 * aussi de produire des erreurs — et c'est précisément le cas où l'incident ne
 * doit pas se refermer tout seul.
 */
export function hasIncident(probe: Probe): boolean {
  return probe.windows.some((w) => w.highest_tier > 0);
}

/** Ce qui reste avant le prochain palier, pour voir venir plutôt que subir. */
export function nextTier(window: ProbeWindow): number | null {
  const valeur = window.last_value ?? 0;

  return window.tiers.find((t) => t > valeur) ?? null;
}

/**
 * Le palier le plus haut atteint par une sonde, toutes fenêtres confondues.
 *
 * Sert à ordonner la liste : ce qui a franchi 100 passe avant ce qui a franchi
 * 3, parce qu'on ouvre cet écran dans l'urgence et qu'on lit du haut.
 */
export function severity(probe: Probe): number {
  return probe.windows.reduce((max, w) => Math.max(max, w.highest_tier), 0);
}

/**
 * « il y a 4 min », « 28 août 18:20 » — jamais un horodatage brut.
 *
 * L'instant de référence est passé, jamais lu de l'horloge — voir
 * `useMonitoringClock`, qui explique pourquoi. Un `maintenant` à zéro veut dire
 * « on ne sait pas encore quelle heure il est » : la date complète est alors
 * rendue telle quelle, ce qui est le cas avant l'hydratation.
 */
export function ago(iso: string | null, maintenant: number): string {
  if (!iso) return "jamais";

  const date = new Date(iso);
  const secondes = Math.round((maintenant - date.getTime()) / 1000);

  if (maintenant !== 0) {
    if (secondes < 60) return "à l'instant";
    if (secondes < 3600) return `il y a ${Math.floor(secondes / 60)} min`;
    if (secondes < 86_400) return `il y a ${Math.floor(secondes / 3600)} h`;
  }

  return date.toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "UTC",
  });
}
