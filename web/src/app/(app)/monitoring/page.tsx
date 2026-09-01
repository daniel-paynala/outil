import { notFound } from "next/navigation";

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
 * ## Pourquoi 404 et non 403
 *
 * L'API répond 404 à qui n'a pas le droit — la supervision n'existe pas pour
 * lui. On garde la même réponse : un 403 dirait qu'Arche surveille des bases de
 * production, ce qui est déjà une information.
 */
export default async function MonitoringPage() {
  const [probesRes, databasesRes, alertsRes, meRes] = await Promise.all([
    apiFetch("/api/monitoring/probes"),
    apiFetch("/api/monitoring/databases"),
    apiFetch("/api/monitoring/alerts"),
    apiFetch("/api/me"),
  ]);

  if (probesRes.status === 404 || probesRes.status === 403) notFound();
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
    />
  );
}
