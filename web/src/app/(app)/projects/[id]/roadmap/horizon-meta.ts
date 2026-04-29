import type { RoadmapHorizon } from "@/lib/types";

export const HORIZONS: {
  key: RoadmapHorizon;
  label: string;
  description: string;
}[] = [
  { key: "now", label: "Now", description: "En cours d'exécution" },
  { key: "next", label: "Next", description: "Prochain — engagé" },
  { key: "later", label: "Later", description: "Envisagé, pas encore prioritaire" },
  { key: "done", label: "Done", description: "Livré" },
];

export const HORIZON_LABEL: Record<RoadmapHorizon, string> = {
  now: "Now",
  next: "Next",
  later: "Later",
  done: "Done",
};
