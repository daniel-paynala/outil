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
} from "lucide-react";

type Tab = {
  key: string;
  label: string;
  icon: LucideIcon;
  disabled?: boolean;
};

const TABS: Tab[] = [
  { key: "", label: "Aperçu", icon: LayoutGrid },
  { key: "tasks", label: "Tâches", icon: ListTodo },
  { key: "documents", label: "Documents", icon: Paperclip },
  { key: "docs", label: "Documentation", icon: BookOpen },
  { key: "github", label: "GitHub", icon: GitBranch, disabled: true },
  { key: "vault", label: "Coffre", icon: KeyRound, disabled: true },
  { key: "time", label: "Temps", icon: Timer, disabled: true },
  { key: "adr", label: "Décisions", icon: ScrollText, disabled: true },
];

export default function ProjectTabs({ projectId }: { projectId: string }) {
  const pathname = usePathname();
  const base = `/projects/${projectId}`;

  return (
    <nav className="border-b border-[var(--border)]">
      <ul className="flex items-center gap-1 overflow-x-auto">
        {TABS.map(({ key, label, icon: Icon, disabled }) => {
          const href = key ? `${base}/${key}` : base;
          const active = key
            ? pathname.startsWith(href)
            : pathname === base;

          if (disabled) {
            return (
              <li key={key || "overview"}>
                <span
                  className="inline-flex items-center gap-1.5 px-3 py-2.5 text-sm text-[var(--muted)] opacity-60 cursor-not-allowed"
                  title="Bientôt"
                >
                  <Icon size={15} strokeWidth={1.75} />
                  {label}
                </span>
              </li>
            );
          }

          return (
            <li key={key || "overview"}>
              <Link
                href={href}
                className={clsx(
                  "inline-flex items-center gap-1.5 px-3 py-2.5 text-sm transition-colors border-b-2 -mb-px",
                  active
                    ? "border-[var(--color-brand-red)] text-[var(--foreground)] font-medium"
                    : "border-transparent text-[var(--muted)] hover:text-[var(--foreground)]",
                )}
              >
                <Icon size={15} strokeWidth={1.75} />
                {label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
