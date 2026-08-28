"use client";

import { useState } from "react";
import {
  Database,
  Key,
  Terminal,
  Settings2,
  Lock,
  ShieldCheck,
  Plus,
  type LucideIcon,
} from "lucide-react";
import { clsx } from "clsx";
import type { VaultCategory, VaultEntry } from "@/lib/types";
import NewEntryForm from "./new-entry-form";
import EntryDrawer from "./entry-drawer";

export const CATEGORIES: {
  key: VaultCategory;
  label: string;
  icon: LucideIcon;
}[] = [
  { key: "database", label: "Base de données", icon: Database },
  { key: "api", label: "API", icon: Key },
  { key: "ssh", label: "SSH", icon: Terminal },
  { key: "env", label: ".env", icon: Settings2 },
  { key: "password", label: "Mot de passe", icon: Lock },
  { key: "other", label: "Autre", icon: ShieldCheck },
];

export default function VaultClient({
  projectId,
  currentUserId,
  projectOwnerId,
  initialEntries,
}: {
  projectId: string;
  currentUserId: string;
  projectOwnerId: string;
  initialEntries: VaultEntry[];
}) {
  const [entries, setEntries] = useState<VaultEntry[]>(initialEntries);
  const [creating, setCreating] = useState(false);
  const [openId, setOpenId] = useState<string | null>(null);

  return (
    <div className="space-y-6">
      <header className="flex items-start justify-between gap-4">
        <div>
          <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
            Accès et secrets
          </p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight flex items-center gap-2">
            <ShieldCheck size={22} strokeWidth={1.75} />
            Coffre
          </h1>
          <p className="mt-2 text-sm text-[var(--muted)]">
            Chiffré côté serveur (AES-256). Chaque lecture du secret est tracée.
          </p>
        </div>
        <button
          onClick={() => setCreating(true)}
          className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1.5 text-sm font-medium"
        >
          <Plus size={14} />
          Nouvelle entrée
        </button>
      </header>

      {entries.length === 0 ? (
        <div className="border border-dashed border-[var(--border)] rounded-lg py-16 text-center">
          <Lock size={22} strokeWidth={1.5} className="mx-auto text-[var(--muted)]" />
          <p className="mt-3 text-sm text-[var(--muted)]">
            Aucun secret enregistré pour l'instant.
          </p>
        </div>
      ) : (
        <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
          {entries.map((e) => {
            const cat = CATEGORIES.find((c) => c.key === e.category);
            const Icon = cat?.icon ?? ShieldCheck;
            const expired =
              e.expires_at && new Date(e.expires_at).getTime() < Date.now();
            return (
              <li key={e.id}>
                <button
                  onClick={() => setOpenId(e.id)}
                  className="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/50"
                >
                  <span className="size-9 rounded-md bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] flex items-center justify-center shrink-0">
                    <Icon size={15} strokeWidth={1.75} />
                  </span>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <p className="text-sm font-medium truncate">{e.name}</p>
                      {e.visibility === "restricted" && (
                        <span
                          title={`Accès restreint à ${
                            (e.allowed_users?.length ?? 0) + 1
                          } personne${
                            (e.allowed_users?.length ?? 0) + 1 > 1 ? "s" : ""
                          }`}
                          className="inline-flex shrink-0 text-[var(--muted)]"
                          aria-label="Accès restreint"
                        >
                          <Lock size={12} strokeWidth={2} />
                        </span>
                      )}
                      {expired && (
                        <span className="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 bg-[var(--color-danger)]/10 text-[var(--color-danger)]">
                          Expiré
                        </span>
                      )}
                    </div>
                    <p className="mt-0.5 text-xs text-[var(--muted)] truncate">
                      {cat?.label}
                      {e.username && <> · {e.username}</>}
                      {e.url && <> · {domainOf(e.url)}</>}
                    </p>
                  </div>
                  <span className="text-xs text-[var(--muted)] shrink-0">
                    Modifié{" "}
                    {new Date(e.updated_at).toLocaleDateString("fr-FR", {
                      day: "numeric",
                      month: "short",
                    })}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}

      {creating && (
        <NewEntryForm
          projectId={projectId}
          currentUserId={currentUserId}
          onClose={() => setCreating(false)}
          onCreated={(entry) => {
            setEntries((prev) => [entry, ...prev]);
            setCreating(false);
            setOpenId(entry.id);
          }}
        />
      )}

      {openId && (
        <EntryDrawer
          key={openId}
          entryId={openId}
          projectId={projectId}
          currentUserId={currentUserId}
          projectOwnerId={projectOwnerId}
          onClose={() => setOpenId(null)}
          onUpdated={(entry) =>
            setEntries((prev) =>
              prev.map((e) => (e.id === entry.id ? entry : e)),
            )
          }
          onDeleted={() => {
            setEntries((prev) => prev.filter((e) => e.id !== openId));
            setOpenId(null);
          }}
        />
      )}
    </div>
  );
}

function domainOf(url: string): string {
  try {
    return new URL(url).hostname;
  } catch {
    return url;
  }
}

export function categoryMeta(key: VaultCategory) {
  return CATEGORIES.find((c) => c.key === key) ?? CATEGORIES[CATEGORIES.length - 1];
}
