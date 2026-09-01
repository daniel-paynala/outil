"use client";

import { useSyncExternalStore } from "react";

/**
 * L'instant de référence pour tous les temps relatifs de l'écran.
 *
 * ## Pourquoi une horloge, et pas `Date.now()` au fil du rendu
 *
 * Cet écran reste ouvert. Un « il y a 4 min » figé pendant vingt minutes ne se
 * contente pas de vieillir : sur un tableau de supervision, il ment — il dit
 * que la sonde vient de tourner alors qu'elle s'est peut-être arrêtée. Le temps
 * doit donc avancer tout seul, et avancer partout en même temps.
 *
 * ## Pourquoi zéro avant l'hydratation
 *
 * Le premier rendu a lieu deux fois : sur le serveur, puis dans le navigateur
 * pour l'hydratation. Les deux doivent produire exactement le même texte, ce
 * qu'aucune lecture d'horloge ne garantit — deux `Date.now()` séparés par la
 * latence de Libreville peuvent tomber de part et d'autre d'une minute.
 *
 * D'où la valeur zéro, rendue des deux côtés : elle vaut « je ne sais pas
 * encore quelle heure il est », et `ago` affiche alors la date complète. La
 * page avant hydratation dit donc « 1 sept. 14:32 » plutôt que rien, ce qui est
 * plus informatif, pas moins.
 */
export function useMonitoringClock(): number {
  return useSyncExternalStore(abonner, () => instant, ZERO);
}

/** Millisecondes epoch, ou 0 tant que personne n'a encore regardé l'heure. */
let instant = 0;

const abonnes = new Set<() => void>();
let battement: ReturnType<typeof setInterval> | null = null;

const ZERO = () => 0;

function avancer(): void {
  instant = Date.now();
  for (const prevenir of abonnes) prevenir();
}

/**
 * Un seul intervalle pour tout l'écran, quel que soit le nombre de lecteurs.
 *
 * Une horloge par composant ferait battre trente minuteries décalées : les
 * lignes du tableau passeraient à « il y a 5 min » les unes après les autres,
 * ce qui se lit comme un rafraîchissement en cours plutôt que comme le temps
 * qui passe.
 */
function abonner(surChangement: () => void): () => void {
  abonnes.add(surChangement);

  if (battement === null) {
    avancer();
    battement = setInterval(avancer, 30_000);
  }

  return () => {
    abonnes.delete(surChangement);

    if (abonnes.size === 0 && battement !== null) {
      clearInterval(battement);
      battement = null;
    }
  };
}
