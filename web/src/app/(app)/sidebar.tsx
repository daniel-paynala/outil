"use client";

import Link from "next/link";
import Image from "next/image";
import { usePathname, useRouter } from "next/navigation";
import {
  LayoutDashboard,
  FolderKanban,
  ListChecks,
  Search,
  Code2,
  CalendarClock,
  Bell,
  LogOut,
  Shield,
  type LucideIcon,
} from "lucide-react";
import { createClient } from "@/lib/supabase/client";
import { clsx } from "clsx";

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
  { href: "/search", label: "Recherche", icon: Search },
  { href: "/snippets", label: "Snippets", icon: Code2, disabled: true },
  { href: "/agenda", label: "Agenda", icon: CalendarClock, disabled: true },
  { href: "/notifications", label: "Notifications", icon: Bell, disabled: true },
];

export default function Sidebar({
  email,
  isAdmin = false,
}: {
  email: string;
  isAdmin?: boolean;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const supabase = createClient();
  const nav: NavItem[] = isAdmin
    ? [...NAV, { href: "/admin/users", label: "Admin", icon: Shield }]
    : NAV;

  async function handleLogout() {
    await supabase.auth.signOut();
    router.push("/login");
    router.refresh();
  }

  return (
    <aside className="w-60 shrink-0 border-r border-[var(--border)] bg-[var(--surface)] flex flex-col sticky top-0 h-screen">
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
          <div className="size-7 rounded-full bg-[var(--color-brand-red)] text-white flex items-center justify-center text-xs font-medium">
            {email.charAt(0).toUpperCase()}
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-xs truncate">{email}</p>
          </div>
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
