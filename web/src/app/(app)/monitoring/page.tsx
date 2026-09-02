import { apiFetch } from "@/lib/api/server";
import type {
  MonitoredDatabase,
  MonitoringAlert,
  ProbesResponse,
} from "@/lib/monitoring/types";

import MonitoringBoard from "./monitoring-board";

export const dynamic = "force-dynamic";

type MeResponse = { capabilities?: string[] };

/**
 * L'écran de supervision.
 *
 * ## Pourquoi tout est chargé ici
 *
 * Bases, sondes et historique arrivent en trois appels parallèles, avant le
 * premier pixel. On ouvre cet écran parce qu'un téléphone a sonné : afficher un
 * squelette puis remplir section par section ferait lire un état incomplet au
 * moment précis où on a besoin de l'état complet.
 *
 * ## Ouverte à toute l'équipe
 *
 * La supervision a d'abord été le premier écran à droit d'accès. À l'usage,
 * l'arbitrage a changé : savoir si les paiements passent concerne les cinq
 * personnes. La confidentialité s'exerce sonde par sonde — une sonde
 * restreinte n'arrive pas dans la réponse, ici comme dans les notifications.
 *
 * Ce qui reste réservé n'est pas l'état mais l'accès : la liste des bases porte
 * leur hôte et leur compte de connexion, et l'API la refuse sans le droit. Elle
 * revient alors vide, et l'écran n'affiche simplement pas cette section.
 */
export default async function MonitoringPage() {
  const [probesRes, databasesRes, alertsRes, meRes] = await Promise.all([
    apiFetch("/api/monitoring/probes"),
    apiFetch("/api/monitoring/databases"),
    apiFetch("/api/monitoring/alerts"),
    apiFetch("/api/me"),
  ]);

  if (!probesRes.ok) {
    throw new Error(`Supervision indisponible (${probesRes.status})`);
  }

  const probes: ProbesResponse = await probesRes.json();
  const databases: MonitoredDatabase[] = databasesRes.ok
    ? await databasesRes.json()
    : [];
  const alerts: MonitoringAlert[] = alertsRes.ok ? await alertsRes.json() : [];
  const me: MeResponse = meRes.ok ? await meRes.json() : {};

  return (
    <MonitoringBoard
      probes={probes.probes}
      openIncidents={probes.open_incidents}
      databases={databases}
      alerts={alerts}
      canAdmin={(me.capabilities ?? []).includes("monitoring.admin")}
      voitLesBases={(me.capabilities ?? []).includes("monitoring")}
    />
  );
}
