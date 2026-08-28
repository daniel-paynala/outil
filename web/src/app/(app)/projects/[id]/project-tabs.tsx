"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { clsx } from "clsx";
import type { LucideIcon } from "lucide-react";
import {
  LayoutGrid,
  ListTodo,
  Paperclip,
  BookOpen,
  GitBranch,
  KeyRound,
  Timer,
  ScrollText,
  Activity,
  Map,
  Users,
  Settings,
  GanttChartSquare,
} from "lucide-react";

type Tab = {
  key: string;
  label: string;
  icon: LucideIcon;
  group?: "main" | "knowledge" | "team";
  disabled?: boolean;
};

const TABS: Tab[] = [
  // Main
  { key: "", label: "Aperçu", icon: LayoutGrid, group: "main" },
  { key: "tasks", label: "Tâches", icon: ListTodo, group: "main" },
  { key: "timeline", label: "Timeline", icon: GanttChartSquare, group: "main" },
  { key: "roadmap", label: "Roadmap", icon: Map, group: "main" },
  { key: "time", label: "Temps", icon: Timer, group: "main" },
  // Knowledge
  { key: "documents", label: "Documents", icon: Paperclip, group: "knowledge" },
  { key: "docs", label: "Documentation", icon: BookOpen, group: "knowledge" },
  { key: "adr", label: "Décisions", icon: ScrollText, group: "knowledge" },
  { key: "vault", label: "Coffre", icon: KeyRound, group: "knowledge" },
  { key: "github", label: "GitHub", icon: GitBranch, group: "knowledge" },
  // Team
  { key: "members", label: "Membres", icon: Users, group: "team" },
  { key: "activity", label: "Journal", icon: Activity, group: "team" },
  { key: "settings", label: "Paramètres", icon: Settings, group: "team" },
];

const GROUP_LABELS: Record<NonNullable<Tab["group"]>, string> = {
  main: "Pilotage",
  knowledge: "Connaissance",
  team: "Équipe",
};

export default function ProjectTabs({ projectId }: { projectId: string }) {
  const pathname = usePathname();
  const base = `/projects/${projectId}`;

  // Group tabs by their group key, preserving insertion order
  const grouped = TABS.reduce<Record<string, Tab[]>>((acc, tab) => {
    const g = tab.group ?? "main";
    (acc[g] ??= []).push(tab);
    return acc;
  }, {});

  return (
    <nav className="space-y-4">
      {(Object.keys(grouped) as Array<keyof typeof GROUP_LABELS>).map(
        (group) => (
          <div key={group}>
            <p className="px-3 mb-1 text-[10px] font-semibold uppercase tracking-wider text-[var(--muted)]">
              {GROUP_LABELS[group]}
            </p>
            <ul className="space-y-0.5">
              {grouped[group].map(({ key, label, icon: Icon, disabled }) => {
                const href = key ? `${base}/${key}` : base;
                // Active si match exact OU si on est dans une sous-route (href + "/")
                // Sans le "/", "/time" matcherait aussi "/timeline".
                const active = key
                  ? pathname === href || pathname.startsWith(href + "/")
                  : pathname === base;

                if (disabled) {
                  return (
                    <li key={key || "overview"}>
                      <span
                        className="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm text-[var(--muted)] opacity-60 cursor-not-allowed"
                        title="Bientôt"
                      >
                        <Icon size={15} strokeWidth={1.75} />
                        <span>{label}</span>
                        <span className="ml-auto text-[9px] uppercase tracking-wider">
                          soon
                        </span>
                      </span>
                    </li>
                  );
                }

                return (
                  <li key={key || "overview"}>
                    <Link
                      href={href}
                      className={clsx(
                        "flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors",
                        active
                          ? "bg-[var(--color-neutral-900)] text-[var(--color-neutral-0)] dark:bg-[var(--color-neutral-0)] dark:text-[var(--color-neutral-900)]"
                          : "text-[var(--foreground)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]",
                      )}
                    >
                      <Icon size={15} strokeWidth={1.75} />
                      <span>{label}</span>
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        ),
      )}
    </nav>
  );
}
