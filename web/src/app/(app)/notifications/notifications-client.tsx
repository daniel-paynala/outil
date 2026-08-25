"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Bell, Check, Trash2, Loader2 } from "lucide-react";
import { clsx } from "clsx";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import {
  useNotifications,
  type NotificationItem,
} from "@/core/notifications/notifications-context";
import Avatar from "@/core/ui/avatar";

type Filter = "all" | "unread";

type Paginated = {
  data: NotificationItem[];
  current_page: number;
  last_page: number;
  total: number;
};

export default function NotificationsClient() {
  const router = useRouter();
  const toast = useToast();
  const { unreadCount, markRead, markAllRead, remove, refresh } =
    useNotifications();

  const [filter, setFilter] = useState<Filter>("all");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<Paginated | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      try {
        const res = await apiFetch(
          `/api/notifications?read=${filter}&page=${page}&per_page=30`,
        );
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const body: Paginated = await res.json();
        if (!cancelled) setData(body);
      } catch (e) {
        if (!cancelled)
          toast.error(
            "Chargement impossible",
            e instanceof Error ? e.message : "Erreur",
          );
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [filter, page, toast]);

  function reload() {
    refresh();
    setPage((p) => p);
    // forcer un refetch local
    setData((d) => d);
  }

  async function handleMarkAll() {
    await markAllRead();
    toast.success("Toutes marquées comme lues");
    reload();
  }

  async function handleClick(n: NotificationItem) {
    if (!n.read_at) {
      await markRead(n.id);
      setData((cur) =>
        cur
          ? {
              ...cur,
              data: cur.data.map((x) =>
                x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x,
              ),
            }
          : cur,
      );
    }
    if (n.link) router.push(n.link);
  }

  async function handleRemove(n: NotificationItem) {
    await remove(n.id);
    setData((cur) =>
      cur
        ? { ...cur, data: cur.data.filter((x) => x.id !== n.id), total: cur.total - 1 }
        : cur,
    );
    toast.success("Notification supprimée");
  }

  const items = data?.data ?? [];
  const total = data?.total ?? 0;

  return (
    <div className="space-y-6">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Boîte de réception
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight flex items-center gap-2">
          <Bell size={20} strokeWidth={1.75} />
          Notifications
        </h1>
        <p className="mt-1 text-sm text-[var(--muted)]">
          Activité dont tu es destinataire — assignations, mises à jour des
          projets dont tu es membre.
        </p>
      </header>

      <div className="flex items-center gap-1 border-b border-[var(--border)]">
        <button
          onClick={() => {
            setFilter("all");
            setPage(1);
          }}
          className={clsx(
            "px-3 py-2 text-sm border-b-2 -mb-px transition-colors",
            filter === "all"
              ? "border-[var(--color-brand-red)] text-[var(--foreground)] font-medium"
              : "border-transparent text-[var(--muted)] hover:text-[var(--foreground)]",
          )}
        >
          Toutes
          {total > 0 && (
            <span className="ml-1.5 text-xs text-[var(--muted)]">{total}</span>
          )}
        </button>
        <button
          onClick={() => {
            setFilter("unread");
            setPage(1);
          }}
          className={clsx(
            "px-3 py-2 text-sm border-b-2 -mb-px transition-colors",
            filter === "unread"
              ? "border-[var(--color-brand-red)] text-[var(--foreground)] font-medium"
              : "border-transparent text-[var(--muted)] hover:text-[var(--foreground)]",
          )}
        >
          Non lues
          {unreadCount > 0 && (
            <span className="ml-1.5 inline-flex items-center justify-center text-[10px] font-bold text-white bg-[var(--color-brand-red)] rounded-full h-4 min-w-[16px] px-1">
              {unreadCount}
            </span>
          )}
        </button>

        <div className="ml-auto">
          {unreadCount > 0 && (
            <button
              onClick={handleMarkAll}
              className="text-xs text-[var(--muted)] hover:text-[var(--foreground)] inline-flex items-center gap-1"
            >
              <Check size={12} /> Tout marquer comme lu
            </button>
          )}
        </div>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-16 text-[var(--muted)]">
          <Loader2 className="w-5 h-5 animate-spin" />
        </div>
      ) : items.length === 0 ? (
        <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] py-16 text-center">
          <Bell
            size={28}
            strokeWidth={1.5}
            className="mx-auto mb-3 text-[var(--muted)]"
          />
          <p className="text-sm text-[var(--muted)]">
            {filter === "unread"
              ? "Tu n'as aucune notification non lue."
              : "Aucune notification pour le moment."}
          </p>
        </div>
      ) : (
        <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)] overflow-hidden">
          {items.map((n) => {
            const isUnread = !n.read_at;
            return (
              <li key={n.id}>
                <div
                  className={clsx(
                    "group flex items-start gap-4 px-5 py-4 cursor-pointer transition-colors",
                    isUnread
                      ? "bg-[var(--color-brand-red)]/5 hover:bg-[var(--color-brand-red)]/10"
                      : "hover:bg-[var(--color-neutral-100)]/50 dark:hover:bg-[var(--color-neutral-800)]/30",
                  )}
                  onClick={() => handleClick(n)}
                >
                  <span
                    className={clsx(
                      "mt-2 size-2 rounded-full shrink-0",
                      isUnread
                        ? "bg-[var(--color-brand-red)]"
                        : "bg-transparent",
                    )}
                  />
                  {n.actor && (
                    <Avatar user={n.actor} size="sm" className="mt-0.5" />
                  )}
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium">{n.title}</p>
                    {n.body && (
                      <p className="text-sm text-[var(--muted)] mt-1">{n.body}</p>
                    )}
                    <div className="flex items-center gap-3 mt-2 flex-wrap">
                      {n.actor && (
                        <span className="text-xs text-[var(--muted)]">
                          par{" "}
                          <span className="text-[var(--foreground)]">
                            {n.actor.name ?? n.actor.email}
                          </span>
                        </span>
                      )}
                      {n.project && (
                        <Link
                          href={`/projects/${n.project.id}`}
                          onClick={(e) => e.stopPropagation()}
                          className="inline-flex items-center gap-1 text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
                        >
                          <span
                            className="size-1.5 rounded-full"
                            style={{ background: n.project.color }}
                          />
                          {n.project.name}
                        </Link>
                      )}
                      <span className="text-xs text-[var(--muted)] tabular-nums">
                        {formatAbsolute(n.created_at)}
                      </span>
                      <code className="text-[10px] text-[var(--muted)] font-mono px-1 py-0.5 rounded bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)]">
                        {n.type}
                      </code>
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation();
                      handleRemove(n);
                    }}
                    className="opacity-0 group-hover:opacity-100 p-1.5 rounded text-[var(--muted)] hover:text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 transition-opacity"
                    title="Supprimer"
                  >
                    <Trash2 size={14} />
                  </button>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {data && data.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <button
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            disabled={page <= 1 || loading}
            className="px-3 py-1.5 rounded-md border border-[var(--border)] disabled:opacity-30 disabled:cursor-not-allowed hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
          >
            ← Précédent
          </button>
          <span className="text-xs text-[var(--muted)]">
            Page {data.current_page} / {data.last_page}
          </span>
          <button
            onClick={() => setPage((p) => Math.min(data.last_page, p + 1))}
            disabled={page >= data.last_page || loading}
            className="px-3 py-1.5 rounded-md border border-[var(--border)] disabled:opacity-30 disabled:cursor-not-allowed hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
          >
            Suivant →
          </button>
        </div>
      )}
    </div>
  );
}

function formatAbsolute(iso: string): string {
  const d = new Date(iso);
  const now = new Date();
  const sameYear = d.getFullYear() === now.getFullYear();
  return d.toLocaleString("fr-FR", {
    day: "numeric",
    month: "short",
    year: sameYear ? undefined : "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
