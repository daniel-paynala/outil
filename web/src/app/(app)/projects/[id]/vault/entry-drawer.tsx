"use client";

import { useState, useEffect, useCallback } from "react";
import {
  X,
  Eye,
  EyeOff,
  Copy,
  Check,
  Trash2,
  Loader2,
  History,
  ExternalLink,
  Pencil,
  ShieldCheck,
  Globe2,
  Lock,
} from "lucide-react";
import { clsx } from "clsx";
import type {
  VaultAccessLog,
  VaultCategory,
  VaultEntry,
} from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import Avatar from "@/core/ui/avatar";
import { CATEGORIES, categoryMeta } from "./vault-client";
import MembersPicker, { type PickableMember } from "./members-picker";

const CLIPBOARD_CLEAR_SEC = 30;

export default function EntryDrawer({
  entryId,
  projectId,
  currentUserId,
  projectOwnerId,
  onClose,
  onUpdated,
  onDeleted,
}: {
  entryId: string;
  projectId: string;
  currentUserId: string;
  projectOwnerId: string;
  onClose: () => void;
  onUpdated: (e: VaultEntry) => void;
  onDeleted: () => void;
}) {
  const toast = useToast();
  const [entry, setEntry] = useState<VaultEntry | null>(null);
  const [revealed, setRevealed] = useState<string | null>(null);
  const [revealing, setRevealing] = useState(false);
  const [copiedAt, setCopiedAt] = useState<number | null>(null);
  const [clipboardSecsLeft, setClipboardSecsLeft] = useState<number | null>(null);
  const [showLog, setShowLog] = useState(false);
  const [editing, setEditing] = useState(false);
  const [members, setMembers] = useState<PickableMember[] | null>(null);

  const load = useCallback(async () => {
    const res = await apiFetch(`/api/vault/${entryId}`);
    if (res.ok) setEntry((await res.json()) as VaultEntry);
  }, [entryId]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const res = await apiFetch(`/api/projects/${projectId}/members`);
      if (!res.ok) {
        if (!cancelled) setMembers([]);
        return;
      }
      const list = (await res.json()) as PickableMember[];
      if (!cancelled) setMembers(list);
    })();
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  useEffect(() => {
    function onEsc(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onEsc);
    return () => window.removeEventListener("keydown", onEsc);
  }, [onClose]);

  // Clipboard countdown + clear
  useEffect(() => {
    if (!copiedAt) return;
    const id = setInterval(() => {
      const elapsed = Math.floor((Date.now() - copiedAt) / 1000);
      const left = CLIPBOARD_CLEAR_SEC - elapsed;
      if (left <= 0) {
        navigator.clipboard.writeText("").catch(() => undefined);
        setCopiedAt(null);
        setClipboardSecsLeft(null);
        clearInterval(id);
      } else {
        setClipboardSecsLeft(left);
      }
    }, 500);
    return () => clearInterval(id);
  }, [copiedAt]);

  async function handleReveal() {
    setRevealing(true);
    const res = await apiFetch(`/api/vault/${entryId}/reveal`);
    setRevealing(false);
    if (res.ok) {
      const { secret } = (await res.json()) as { secret: string };
      setRevealed(secret);
    }
  }

  async function handleCopy() {
    try {
      let val = revealed;
      if (!val) {
        const res = await apiFetch(`/api/vault/${entryId}/reveal`);
        if (!res.ok) {
          toast.error(
            "Copie impossible",
            (await res.text()) || `Erreur ${res.status}`,
          );
          return;
        }
        val = ((await res.json()) as { secret: string }).secret;
      }
      await navigator.clipboard.writeText(val);
      setCopiedAt(Date.now());
      setClipboardSecsLeft(CLIPBOARD_CLEAR_SEC);
      toast.info("Copié", "Le secret est dans le presse-papier");
    } catch (err) {
      toast.error(
        "Copie impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  async function handleDelete() {
    if (!entry) return;
    if (
      !confirm(
        `Supprimer l'entrée "${entry.name}" ? Le secret chiffré sera définitivement perdu.`,
      )
    )
      return;
    const name = entry.name;
    try {
      const res = await apiFetch(`/api/vault/${entryId}`, { method: "DELETE" });
      if (res.ok) {
        toast.success("Supprimée", name);
        onDeleted();
      } else {
        toast.error(
          "Suppression impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
      }
    } catch (err) {
      toast.error(
        "Suppression impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  async function handleSave(
    patch: Partial<VaultEntry> & {
      secret?: string;
      allowed_user_ids?: string[];
    },
  ) {
    try {
      const res = await apiFetch(`/api/vault/${entryId}`, {
        method: "PATCH",
        body: JSON.stringify(patch),
      });
      if (!res.ok) {
        toast.error(
          "Enregistrement impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      const updated = (await res.json()) as VaultEntry;
      setEntry(updated);
      onUpdated(updated);
      setEditing(false);
      toast.success("Enregistré", updated.name);
    } catch (err) {
      toast.error(
        "Enregistrement impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  if (!entry) {
    return (
      <Shell onClose={onClose}>
        <div className="flex items-center justify-center h-40 text-[var(--muted)]">
          <Loader2 size={20} className="animate-spin" />
        </div>
      </Shell>
    );
  }

  const cat = categoryMeta(entry.category);
  const Icon = cat.icon;
  const expired =
    entry.expires_at && new Date(entry.expires_at).getTime() < Date.now();
  const canEditAccess =
    currentUserId === entry.created_by || currentUserId === projectOwnerId;
  const visibility = entry.visibility ?? "all";

  return (
    <Shell onClose={onClose}>
      <header className="px-6 py-4 border-b border-[var(--border)] flex items-start gap-3">
        <span className="size-9 rounded-md bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] flex items-center justify-center shrink-0">
          <Icon size={15} strokeWidth={1.75} />
        </span>
        <div className="flex-1 min-w-0">
          <h3 className="text-lg font-semibold tracking-tight truncate">
            {entry.name}
          </h3>
          <p className="text-xs text-[var(--muted)]">{cat.label}</p>
        </div>
        <div className="flex items-center gap-1 shrink-0">
          <button
            onClick={() => setEditing((v) => !v)}
            className={clsx(
              "p-1.5 rounded",
              editing
                ? "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)]"
                : "text-[var(--muted)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]",
            )}
            title="Éditer"
          >
            <Pencil size={14} />
          </button>
          <button
            onClick={() => setShowLog(true)}
            className="p-1.5 rounded text-[var(--muted)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
            title="Historique des accès"
          >
            <History size={14} />
          </button>
          <button
            onClick={handleDelete}
            className="p-1.5 rounded text-[var(--muted)] hover:text-[var(--color-danger)]"
            title="Supprimer"
          >
            <Trash2 size={14} />
          </button>
          <button
            onClick={onClose}
            className="p-1.5 rounded hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
          >
            <X size={15} />
          </button>
        </div>
      </header>

      <div className="flex-1 overflow-y-auto px-6 py-5 space-y-5">
        {expired && (
          <div className="text-xs rounded-md border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 text-[var(--color-danger)] px-3 py-2 flex items-center gap-2">
            <ShieldCheck size={12} />
            Cette entrée est expirée depuis le{" "}
            {new Date(entry.expires_at!).toLocaleDateString("fr-FR")}.
          </div>
        )}

        {editing ? (
          <EditForm
            entry={entry}
            members={members}
            canEditAccess={canEditAccess}
            onCancel={() => setEditing(false)}
            onSave={handleSave}
          />
        ) : (
          <>
            {entry.username && (
              <Row label="Identifiant" value={entry.username} copyable />
            )}

            <div className="space-y-1.5">
              <p className="text-xs font-medium text-[var(--muted)]">Secret</p>
              <div className="flex items-center gap-2">
                <code className="flex-1 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2 text-sm font-mono overflow-x-auto whitespace-pre-wrap break-all">
                  {revealed ?? "••••••••••••••••"}
                </code>
                <button
                  onClick={handleReveal}
                  disabled={revealing}
                  className="p-2 rounded-md border border-[var(--border)] hover:border-[var(--color-neutral-400)] text-[var(--muted)] hover:text-[var(--foreground)]"
                  title={revealed ? "Masquer" : "Révéler"}
                >
                  {revealing ? (
                    <Loader2 size={13} className="animate-spin" />
                  ) : revealed ? (
                    <EyeOff size={13} />
                  ) : (
                    <Eye size={13} />
                  )}
                </button>
                <button
                  onClick={async () => {
                    if (revealed) setRevealed(null);
                    await handleCopy();
                  }}
                  className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-2 text-xs font-medium"
                >
                  {clipboardSecsLeft !== null ? (
                    <>
                      <Check size={12} />
                      {clipboardSecsLeft}s
                    </>
                  ) : (
                    <>
                      <Copy size={12} />
                      Copier
                    </>
                  )}
                </button>
              </div>
              {clipboardSecsLeft !== null && (
                <p className="text-[11px] text-[var(--muted)]">
                  Auto-effacement du presse-papier dans {clipboardSecsLeft}s.
                </p>
              )}
            </div>

            {entry.url && (
              <div className="space-y-1.5">
                <p className="text-xs font-medium text-[var(--muted)]">URL</p>
                <a
                  href={entry.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1.5 text-sm text-[var(--color-brand-red)] hover:underline"
                >
                  {entry.url}
                  <ExternalLink size={11} />
                </a>
              </div>
            )}

            {entry.notes && (
              <div className="space-y-1.5">
                <p className="text-xs font-medium text-[var(--muted)]">Notes</p>
                <p className="text-sm whitespace-pre-wrap">{entry.notes}</p>
              </div>
            )}

            {entry.expires_at && (
              <Row
                label="Expire le"
                value={new Date(entry.expires_at).toLocaleDateString("fr-FR", {
                  day: "numeric",
                  month: "long",
                  year: "numeric",
                })}
              />
            )}

            <div className="space-y-1.5">
              <p className="text-xs font-medium text-[var(--muted)]">Accès</p>
              {visibility === "all" ? (
                <p className="inline-flex items-center gap-1.5 text-sm">
                  <Globe2 size={13} className="text-[var(--muted)]" />
                  Tout le monde du projet
                </p>
              ) : (
                <div className="space-y-2">
                  <p className="inline-flex items-center gap-1.5 text-sm">
                    <Lock size={13} className="text-[var(--muted)]" />
                    Restreint à{" "}
                    {(entry.allowed_users?.length ?? 0) + 1} personne
                    {(entry.allowed_users?.length ?? 0) + 1 > 1 ? "s" : ""}
                  </p>
                  <div className="flex flex-wrap gap-1.5">
                    {entry.creator && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] pl-1 pr-2 py-0.5 text-xs">
                        <Avatar user={entry.creator} size="xs" />
                        <span className="truncate max-w-[160px]">
                          {entry.creator.email}
                        </span>
                        <span className="text-[10px] text-[var(--muted)]">
                          créateur
                        </span>
                      </span>
                    )}
                    {(entry.allowed_users ?? []).map((u) => (
                      <span
                        key={u.id}
                        className="inline-flex items-center gap-1 rounded-full bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] pl-1 pr-2 py-0.5 text-xs"
                      >
                        <Avatar user={u} size="xs" />
                        <span className="truncate max-w-[160px]">{u.email}</span>
                      </span>
                    ))}
                  </div>
                </div>
              )}
            </div>

            <hr className="border-[var(--border)]" />

            <div className="text-xs text-[var(--muted)] space-y-1">
              <p>
                Créée par {entry.creator?.email ?? "—"} le{" "}
                {new Date(entry.created_at).toLocaleDateString("fr-FR")}
              </p>
              <p>
                Modifiée par {entry.updater?.email ?? "—"} le{" "}
                {new Date(entry.updated_at).toLocaleDateString("fr-FR")}
              </p>
            </div>
          </>
        )}
      </div>

      {showLog && (
        <AccessLogDrawer
          entryId={entryId}
          onClose={() => setShowLog(false)}
        />
      )}
    </Shell>
  );
}

function Row({
  label,
  value,
  copyable,
}: {
  label: string;
  value: string;
  copyable?: boolean;
}) {
  const toast = useToast();
  const [copied, setCopied] = useState(false);
  async function copy() {
    await navigator.clipboard.writeText(value);
    setCopied(true);
    toast.info("Copié", `${label} dans le presse-papier`);
    setTimeout(() => setCopied(false), 1500);
  }
  return (
    <div className="space-y-1.5">
      <p className="text-xs font-medium text-[var(--muted)]">{label}</p>
      <div className="flex items-center gap-2">
        <p className="text-sm flex-1 break-all">{value}</p>
        {copyable && (
          <button
            onClick={copy}
            className="p-1.5 rounded text-[var(--muted)] hover:text-[var(--foreground)]"
            title="Copier"
          >
            {copied ? <Check size={13} /> : <Copy size={13} />}
          </button>
        )}
      </div>
    </div>
  );
}

function EditForm({
  entry,
  members,
  canEditAccess,
  onCancel,
  onSave,
}: {
  entry: VaultEntry;
  members: PickableMember[] | null;
  canEditAccess: boolean;
  onCancel: () => void;
  onSave: (
    patch: Partial<VaultEntry> & {
      secret?: string;
      allowed_user_ids?: string[];
    },
  ) => Promise<void>;
}) {
  const [name, setName] = useState(entry.name);
  const [category, setCategory] = useState<VaultCategory>(entry.category);
  const [username, setUsername] = useState(entry.username ?? "");
  const [secret, setSecret] = useState("");
  const [url, setUrl] = useState(entry.url ?? "");
  const [notes, setNotes] = useState(entry.notes ?? "");
  const [expiresAt, setExpiresAt] = useState(
    entry.expires_at ? entry.expires_at.slice(0, 10) : "",
  );
  const [visibility, setVisibility] = useState<"all" | "restricted">(
    entry.visibility ?? "all",
  );
  const [allowedUserIds, setAllowedUserIds] = useState<string[]>(
    (entry.allowed_users ?? []).map((u) => u.id),
  );
  const [saving, setSaving] = useState(false);

  // Le créateur ne doit pas apparaître dans la liste sélectionnable.
  const pickableMembers = (members ?? []).filter(
    (m) => m.user_id !== entry.created_by,
  );

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    const patch: Partial<VaultEntry> & {
      secret?: string;
      allowed_user_ids?: string[];
    } = {
      name,
      category,
      username: username || null,
      url: url || null,
      notes: notes || null,
      expires_at: expiresAt || null,
    };
    if (secret) patch.secret = secret;
    if (canEditAccess) {
      patch.visibility = visibility;
      patch.allowed_user_ids =
        visibility === "restricted" ? allowedUserIds : [];
    }
    await onSave(patch);
    setSaving(false);
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      <div className="space-y-1.5">
        <label className="text-xs font-medium">Nom</label>
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
        />
      </div>
      <div className="space-y-1.5">
        <label className="text-xs font-medium">Catégorie</label>
        <select
          value={category}
          onChange={(e) => setCategory(e.target.value as VaultCategory)}
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
        >
          {CATEGORIES.map((c) => (
            <option key={c.key} value={c.key}>
              {c.label}
            </option>
          ))}
        </select>
      </div>
      <div className="space-y-1.5">
        <label className="text-xs font-medium">Identifiant</label>
        <input
          value={username}
          onChange={(e) => setUsername(e.target.value)}
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
        />
      </div>
      <div className="space-y-1.5">
        <label className="text-xs font-medium">
          Nouveau secret{" "}
          <span className="text-[var(--muted)]">(laisse vide pour garder l'actuel)</span>
        </label>
        <textarea
          value={secret}
          onChange={(e) => setSecret(e.target.value)}
          rows={3}
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm font-mono"
        />
      </div>
      <div className="space-y-1.5">
        <label className="text-xs font-medium">URL</label>
        <input
          type="url"
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
        />
      </div>
      <div className="space-y-1.5">
        <label className="text-xs font-medium">Notes</label>
        <textarea
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          rows={3}
          className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm resize-y"
        />
      </div>
      <div className="space-y-1.5">
        <label className="text-xs font-medium">Expire le</label>
        <input
          type="date"
          value={expiresAt}
          onChange={(e) => setExpiresAt(e.target.value)}
          className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
        />
      </div>

      <fieldset className="space-y-2">
        <legend className="text-xs font-medium">Accès</legend>
        {canEditAccess ? (
          <>
            <label className="flex items-start gap-2 cursor-pointer rounded-md border border-[var(--border)] px-3 py-2 hover:border-[var(--color-neutral-400)]">
              <input
                type="radio"
                name="visibility"
                value="all"
                checked={visibility === "all"}
                onChange={() => setVisibility("all")}
                className="mt-0.5 accent-[var(--color-brand-red)]"
              />
              <span className="flex-1">
                <span className="flex items-center gap-1.5 text-sm font-medium">
                  <Globe2 size={13} />
                  Tout le monde du projet
                </span>
              </span>
            </label>
            <label className="flex items-start gap-2 cursor-pointer rounded-md border border-[var(--border)] px-3 py-2 hover:border-[var(--color-neutral-400)]">
              <input
                type="radio"
                name="visibility"
                value="restricted"
                checked={visibility === "restricted"}
                onChange={() => setVisibility("restricted")}
                className="mt-0.5 accent-[var(--color-brand-red)]"
              />
              <span className="flex-1">
                <span className="flex items-center gap-1.5 text-sm font-medium">
                  <Lock size={13} />
                  Seulement certaines personnes
                </span>
              </span>
            </label>

            {visibility === "restricted" && (
              <div className="pt-1">
                {members === null ? (
                  <div className="flex items-center gap-2 text-xs text-[var(--muted)] px-1 py-2">
                    <Loader2 size={12} className="animate-spin" />
                    Chargement des membres…
                  </div>
                ) : (
                  <MembersPicker
                    members={pickableMembers}
                    selected={allowedUserIds}
                    onChange={setAllowedUserIds}
                  />
                )}
              </div>
            )}
          </>
        ) : (
          <p className="inline-flex items-center gap-1.5 text-sm text-[var(--muted)]">
            {visibility === "all" ? (
              <>
                <Globe2 size={13} />
                Tout le monde du projet
              </>
            ) : (
              <>
                <Lock size={13} />
                Restreint à{" "}
                {(entry.allowed_users?.length ?? 0) + 1} personne
                {(entry.allowed_users?.length ?? 0) + 1 > 1 ? "s" : ""}
              </>
            )}
          </p>
        )}
      </fieldset>

      <div className="flex gap-2 pt-2">
        <button
          type="submit"
          disabled={saving || !name.trim()}
          className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1.5 text-sm font-medium disabled:opacity-50"
        >
          {saving && <Loader2 size={13} className="animate-spin" />}
          Enregistrer
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="text-sm text-[var(--muted)] hover:text-[var(--foreground)] px-3"
        >
          Annuler
        </button>
      </div>
    </form>
  );
}

function AccessLogDrawer({
  entryId,
  onClose,
}: {
  entryId: string;
  onClose: () => void;
}) {
  const [logs, setLogs] = useState<VaultAccessLog[] | null>(null);

  useEffect(() => {
    (async () => {
      const res = await apiFetch(`/api/vault/${entryId}/log`);
      if (res.ok) setLogs((await res.json()) as VaultAccessLog[]);
    })();
  }, [entryId]);

  useEffect(() => {
    function onEsc(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onEsc);
    return () => window.removeEventListener("keydown", onEsc);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-50 flex">
      <div className="flex-1 bg-black/20" onClick={onClose} />
      <aside className="w-full max-w-lg bg-[var(--background)] border-l border-[var(--border)] shadow-2xl flex flex-col">
        <header className="px-6 py-4 border-b border-[var(--border)] flex items-center">
          <h3 className="text-sm font-medium flex-1">Historique des accès</h3>
          <button
            onClick={onClose}
            className="text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
          >
            Fermer
          </button>
        </header>
        <div className="flex-1 overflow-y-auto">
          {logs === null && (
            <p className="p-6 text-xs text-[var(--muted)]">Chargement…</p>
          )}
          {logs !== null && logs.length === 0 && (
            <p className="p-6 text-xs text-[var(--muted)]">Aucun accès.</p>
          )}
          {logs && logs.length > 0 && (
            <ul className="divide-y divide-[var(--border)]">
              {logs.map((l) => (
                <li key={l.id} className="px-6 py-3 text-sm">
                  <div className="flex items-center gap-2">
                    <ActionPill action={l.action} />
                    <span className="text-xs text-[var(--muted)]">
                      {new Date(l.created_at).toLocaleString("fr-FR")}
                    </span>
                  </div>
                  <p className="mt-1 text-xs text-[var(--muted)] truncate">
                    {l.user?.email ?? "—"}
                    {l.ip && <> · {l.ip}</>}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </div>
      </aside>
    </div>
  );
}

function ActionPill({ action }: { action: string }) {
  const c =
    action === "revealed"
      ? "bg-[var(--color-brand-red)]/10 text-[var(--color-brand-red)]"
      : action === "deleted"
        ? "bg-[var(--color-danger)]/10 text-[var(--color-danger)]"
        : action === "created"
          ? "bg-[var(--color-success)]/10 text-[var(--color-success)]"
          : "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] text-[var(--foreground)]";
  return (
    <span
      className={clsx(
        "text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 font-medium",
        c,
      )}
    >
      {action}
    </span>
  );
}

function Shell({
  children,
  onClose,
}: {
  children: React.ReactNode;
  onClose: () => void;
}) {
  return (
    <div className="fixed inset-0 z-40 flex">
      <div className="flex-1 bg-black/20" onClick={onClose} />
      <aside className="w-full max-w-xl bg-[var(--background)] border-l border-[var(--border)] flex flex-col shadow-2xl">
        {children}
      </aside>
    </div>
  );
}
