"use client";

import Link from "next/link";
import Image from "next/image";
import { usePathname, useRouter } from "next/navigation";
import { Activity,
  LayoutDashboard,
  FolderKanban,
  ListChecks,
  Archive,
  Search,
  Bell,
  LogOut,
  Shield,
  ScrollText,
  type LucideIcon,
} from "lucide-react";
import { createClient } from "@/lib/supabase/client";
import { clsx } from "clsx";
import Avatar from "@/core/ui/avatar";

type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  disabled?: boolean;
};

const NAV: NavItem[] = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
  { href: "/projects", label: "Projets", icon: FolderKanban },
  { href: "/my-tasks", label: "Mes tâches", icon: ListChecks },
  { href: "/archive", label: "Archive", icon: Archive },
  { href: "/search", label: "Recherche", icon: Search },
  { href: "/notifications", label: "Notifications", icon: Bell },
];

export default function Sidebar({
  email,
  isAdmin = false,
  capabilities = [],
  avatarUrl = null,
  name = null,
}: {
  email: string;
  isAdmin?: boolean;
  /** Droits accordés au cas par cas — voir `user_capabilities` côté API. */
  capabilities?: string[];
  avatarUrl?: string | null;
  name?: string | null;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const supabase = createClient();
  // La supervision donne à voir des bases de production : elle n'apparaît que
  // pour qui en a le droit. Le reste de l'outil reste visible de tous.
  const peutSuperviser = capabilities.includes("monitoring");

  const nav: NavItem[] = [
    ...NAV,
    ...(peutSuperviser
      ? [{ href: "/monitoring", label: "Supervision", icon: Activity }]
      : []),
    ...(isAdmin
      ? [
          { href: "/admin/users", label: "Utilisateurs", icon: Shield },
          { href: "/admin/audit", label: "Journal d'audit", icon: ScrollText },
        ]
      : []),
  ];

  async function handleLogout() {
    await supabase.auth.signOut();
    router.push("/login");
    router.refresh();
  }

  return (
    <aside className="fixed left-0 top-0 z-30 w-60 border-r border-[var(--border)] bg-[var(--surface)] flex flex-col h-screen overflow-y-auto">
      <div className="px-5 py-5 flex items-center gap-2.5 border-b border-[var(--border)]">
        <Image
          src="/paynala-icon.png"
          alt="Paynala"
          width={28}
          height={28}
          priority
        />
        <div className="flex flex-col leading-tight">
          <span className="text-sm font-semibold tracking-tight">Arche</span>
          <span className="text-[11px] text-[var(--muted)]">by Paynala</span>
        </div>
      </div>

      <nav className="flex-1 px-2 py-3 space-y-0.5">
        {nav.map(({ href, label, icon: Icon, disabled }) => {
          const active = pathname === href || pathname.startsWith(href + "/");
          if (disabled) {
            return (
              <div
                key={href}
                className="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm text-[var(--muted)] opacity-60 cursor-not-allowed"
                title="Bientôt"
              >
                <Icon size={16} strokeWidth={1.75} />
                <span>{label}</span>
                <span className="ml-auto text-[10px] uppercase tracking-wider">
                  soon
                </span>
              </div>
            );
          }
          return (
            <Link
              key={href}
              href={href}
              className={clsx(
                "flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors",
                active
                  ? "bg-[var(--color-neutral-900)] text-[var(--color-neutral-0)] dark:bg-[var(--color-neutral-0)] dark:text-[var(--color-neutral-900)]"
                  : "text-[var(--foreground)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]",
              )}
            >
              <Icon size={16} strokeWidth={1.75} />
              <span>{label}</span>
            </Link>
          );
        })}
      </nav>

      <div className="border-t border-[var(--border)] p-3">
        <div className="flex items-center gap-2.5 px-2 py-2 rounded-md">
          <Link
            href="/account"
            title="Mon compte"
            className="hover:opacity-90"
          >
            <Avatar user={{ email, name, avatar_url: avatarUrl }} size="md" />
          </Link>
          <Link
            href="/account"
            className="flex-1 min-w-0 hover:underline"
            title="Mon compte"
          >
            <p className="text-xs truncate">{email}</p>
          </Link>
          <button
            onClick={handleLogout}
            title="Se déconnecter"
            className="p-1.5 rounded-md hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)] text-[var(--muted)]"
          >
            <LogOut size={14} strokeWidth={1.75} />
          </button>
        </div>
      </div>
    </aside>
  );
}
