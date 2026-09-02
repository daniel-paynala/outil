import type { WindowMode } from "@/lib/monitoring/types";

/**
 * Les cinq découpages du temps, et ce qu'ils signifient.
 *
 * Rassemblés ici plutôt que répétés dans le tableau et dans l'éditeur : la
 * phrase qui explique un mode doit être la même aux deux endroits, sinon
 * l'une des deux finira par décrire un comportement qui a changé.
 */
export const LIBELLE_MODE: Record<WindowMode, string> = {
  glissante: "glissante",
  calendaire: "calendaire",
  mensuelle: "ce mois-ci",
  annuelle: "cette année",
  totale: "depuis toujours",
};

export const INFOBULLE_MODE: Record<WindowMode, string> = {
  glissante: "Les N dernières heures, à tout instant",
  calendaire: "Depuis minuit, heure de Libreville",
  mensuelle: "Depuis le 1er du mois, heure de Libreville",
  annuelle: "Depuis le 1er janvier, heure de Libreville",
  totale: "Depuis toujours — un cumul, que l'acquittement ne remet pas à zéro",
};
