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
export type WindowMode =
  "glissante" | "calendaire" | "mensuelle" | "annuelle" | "totale";

/**
 * Les modes dont la période est fixée par le mode lui-même.
 *
 * Pour eux, `hours` ne décrit pas la période observée : il devient
 * l'intervalle de rechargement. Le champ dit toujours la même chose sous deux
 * formes — le nombre dont le mode a besoin.
 */
export function isPeriod(mode: WindowMode | undefined): boolean {
  return mode === "mensuelle" || mode === "annuelle" || mode === "totale";
}

/**
 * La période, telle qu'elle se dit.
 *
 * « sur 24 h » ne veut rien dire d'une fenêtre mensuelle, et « sur 720 h »
 * encore moins : un mois n'a pas une durée fixe.
 */
export function periodLabel(w: ProbeWindow): string {
  switch (w.mode) {
    case "totale":
      return "depuis toujours";
    case "annuelle":
      return "cette année";
    case "mensuelle":
      return "ce mois-ci";
    case "calendaire":
      return w.hours <= 24
        ? "aujourd'hui"
        : `sur ${Math.floor(w.hours / 24)} jours`;
    default:
      return `${w.hours} h glissantes`;
  }
}

/**
 * Dans quel sens la fenêtre se dégrade.
 *
 * `croissant` — le danger est en haut : un compte d'erreurs qui grimpe. C'est
 * le cas de presque toutes les sondes, et le défaut.
 *
 * `decroissant` — le danger est en bas : une mesure de santé qui s'effondre.
 * Une sonde de paiements réussis réglée en croissant préviendrait quand la
 * journée est bonne, et se tairait quand la production tombe à zéro.
 *
 * « Le plus grave » s'inverse avec le sens : en croissant c'est le palier le
 * plus haut franchi, en décroissant le plus bas — tomber sous 20 est pire que
 * tomber sous 100.
 */
export type Direction = "croissant" | "decroissant";

export type ProbeWindow = {
  id?: string;
  hours: number;
  mode?: WindowMode;
  direction?: Direction;

  /** Croissants. Vide = on observe sans alerter. */
  tiers: number[];

  /**
   * Le palier le plus **grave** signalé depuis le dernier acquittement : le
   * plus haut en croissant, le plus bas en décroissant. Zéro veut dire aucun,
   * ce qu'aucun palier réel ne peut valoir.
   */
  severest_tier: number;

  last_value: number | null;

  /**
   * Les colonnes que la requête rend en plus de `valeur`.
   *
   * Une sonde ne peut alerter que sur un seul nombre — c'est ce qui garde un
   * palier interprétable. Mais un total sans sa décomposition obligerait à
   * créer une sonde par portefeuille, et à en payer quatre fois le coût.
   * Affiché sous le chiffre, jamais utilisé pour décider.
   */
  last_detail?: Record<string, number | string> | null;

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

  /** Plafond côté Postgres, en millisecondes. */
  timeout_ms?: number;

  /** Cadence d'exécution, en minutes. */
  interval_minutes?: number;

  /** Le dernier échec de cette sonde — le sien, pas celui de sa base. */
  last_error?: string | null;

  /**
   * Franchir un palier est-il une bonne nouvelle ?
   *
   * `incident` — il faut agir. `jalon` — on voulait le savoir.
   *
   * Le sens de dégradation ne suffisait pas à le dire : une sonde d'erreurs
   * croissante et une sonde de chiffre d'affaires croissante montent toutes
   * les deux, mais l'une empire pendant que l'autre prospère.
   */
  nature?: "incident" | "jalon";

  /** Depuis quand on compte. Posé au dernier acquittement. */
  counting_from: string | null;

  windows: ProbeWindow[];
  database?: Pick<
    MonitoredDatabase,
    "id" | "name" | "read_only_verified_at" | "last_error"
  >;
  acknowledger?: Personne | null;

  /**
   * Les personnes à qui la sonde est restreinte.
   *
   * **Vide veut dire « tout le monde »**, pas « personne ». La restriction est
   * une exception qu'on pose, jamais un réglage qu'on oublie.
   */
  viewers?: Personne[];
};

export type Personne = {
  id: string;
  name: string | null;
  email: string;
  avatar_url?: string | null;
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
export type Level = "calme" | "premier" | "aggrave" | "jalon";

/** Du moins grave au plus grave — sert à prendre le pire de deux états. */
const ORDRE: Level[] = ["calme", "jalon", "premier", "aggrave"];

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
export function windowLevel(w: ProbeWindow, jalon = false): Level {
  if (jalon) return w.severest_tier > 0 ? "jalon" : "calme";

  if (w.severest_tier <= 0) return "calme";
  if (w.tiers.length === 0) return "aggrave";

  return w.severest_tier === firstTier(w) ? "premier" : "aggrave";
}

/**
 * Le palier le moins grave — celui qu'on franchit en premier.
 *
 * En croissant c'est le plus petit, en décroissant le plus grand. C'est lui qui
 * distingue l'orange du rouge : le prendre toujours en bas rendrait une sonde
 * décroissante rouge dès son premier franchissement, et l'orange ne servirait
 * jamais.
 */
function firstTier(w: ProbeWindow): number | undefined {
  if (w.tiers.length === 0) return undefined;

  return w.direction === "decroissant"
    ? w.tiers[w.tiers.length - 1]
    : w.tiers[0];
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
  // Bleu et non orange : un jalon franchi est une nouvelle, pas un
  // avertissement. Peindre un milliard collecté en orange fait lire
  // « c'est pas assez ? » — c'est arrivé.
  jalon: "var(--color-info)",
  premier: "var(--color-warning)",
  aggrave: "var(--color-danger)",
};

export const LIBELLE_NIVEAU: Record<Level, string> = {
  calme: "Sous le premier palier",
  jalon: "Jalon franchi",
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
  // Un jalon franchi n'attend rien de personne : il ne peut pas ouvrir un
  // incident, et n'a rien à acquitter. Le jalon suivant se signalera de
  // lui-même, puisqu'il est plus haut.
  if (probe.nature === "jalon") return false;

  return probe.windows.some((w) => w.severest_tier > 0);
}

/**
 * Le prochain seuil à franchir, pour voir venir plutôt que subir.
 *
 * En décroissant, « le prochain » est le plus haut palier strictement sous la
 * valeur courante : à 45, avec 20/50/100, le suivant est 20.
 */
export function nextTier(window: ProbeWindow): number | null {
  const valeur = window.last_value ?? 0;

  if (window.direction === "decroissant") {
    const dessous = window.tiers.filter((t) => t < valeur);

    return dessous.length ? Math.max(...dessous) : null;
  }

  return window.tiers.find((t) => t > valeur) ?? null;
}

/** « palier » ou « plancher » — le mot change avec le sens. */
export function seuilLabel(w: ProbeWindow, jalon = false): string {
  if (jalon) return "jalon";

  return w.direction === "decroissant" ? "plancher" : "palier";
}

/**
 * Le palier le plus haut atteint par une sonde, toutes fenêtres confondues.
 *
 * Sert à ordonner la liste : ce qui a franchi 100 passe avant ce qui a franchi
 * 3, parce qu'on ouvre cet écran dans l'urgence et qu'on lit du haut.
 */
export function severity(probe: Probe): number {
  return probe.windows.reduce((max, w) => Math.max(max, w.severest_tier), 0);
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
