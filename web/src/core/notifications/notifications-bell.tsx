"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Bell, Check, X } from "lucide-react";
import { clsx } from "clsx";
import {
  useNotifications,
  type NotificationItem,
} from "./notifications-context";
import Avatar from "@/core/ui/avatar";

export default function NotificationsBell() {
  const router = useRouter();
  const { unreadCount, recent, markRead, markAllRead, remove } =
    useNotifications();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function onClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    if (open) {
      document.addEventListener("mousedown", onClickOutside);
      return () => document.removeEventListener("mousedown", onClickOutside);
    }
  }, [open]);

  function onItemClick(n: NotificationItem) {
    if (!n.read_at) markRead(n.id);
    setOpen(false);
    if (n.link) router.push(n.link);
  }

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="relative p-2 rounded-md hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)] text-[var(--muted)] hover:text-[var(--foreground)] transition-colors"
        title="Notifications"
      >
        <Bell size={16} strokeWidth={1.75} />
        {unreadCount > 0 && (
          <span className="absolute -top-0.5 -right-0.5 flex items-center justify-center h-4 min-w-[16px] px-1 text-[10px] font-bold text-white bg-[var(--color-brand-red)] rounded-full">
            {unreadCount > 99 ? "99+" : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-96 max-w-[calc(100vw-2rem)] rounded-xl border border-[var(--border)] bg-[var(--surface)] shadow-xl z-50 overflow-hidden">
          <div className="px-4 py-3 border-b border-[var(--border)] flex items-center justify-between">
            <p className="text-sm font-semibold">Notifications</p>
            <div className="flex items-center gap-2">
              {unreadCount > 0 && (
                <button
                  type="button"
                  onClick={() => markAllRead()}
                  className="text-xs text-[var(--muted)] hover:text-[var(--foreground)] inline-flex items-center gap-1"
                  title="Tout marquer comme lu"
                >
                  <Check size={12} /> Tout lu
                </button>
              )}
            </div>
          </div>

          {recent.length === 0 ? (
            <div className="px-4 py-10 text-center text-sm text-[var(--muted)]">
              Aucune notification
            </div>
          ) : (
            <ul className="max-h-[420px] overflow-y-auto divide-y divide-[var(--border)]">
              {recent.slice(0, 10).map((n) => {
                const isUnread = !n.read_at;
                return (
                  <li key={n.id}>
                    <div
                      className={clsx(
                        "group flex items-start gap-3 px-4 py-3 transition-colors cursor-pointer",
                        isUnread
                          ? "bg-[var(--color-brand-red)]/5 hover:bg-[var(--color-brand-red)]/10"
                          : "hover:bg-[var(--color-neutral-100)]/50 dark:hover:bg-[var(--color-neutral-800)]/30",
                      )}
                      onClick={() => onItemClick(n)}
                    >
                      <span
                        className={clsx(
                          "mt-1 size-2 rounded-full shrink-0",
                          isUnread
                            ? "bg-[var(--color-brand-red)]"
                            : "bg-transparent",
                        )}
                      />
                      {n.actor && (
                        <Avatar user={n.actor} size="xs" className="mt-0.5" />
                      )}
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium leading-tight truncate">
                          {n.title}
                        </p>
                        {n.body && (
                          <p className="text-xs text-[var(--muted)] mt-0.5 truncate">
                            {n.body}
                          </p>
                        )}
                        <div className="flex items-center gap-2 mt-1">
                          {n.project && (
                            <span className="inline-flex items-center gap-1 text-[10px] text-[var(--muted)]">
                              <span
                                className="size-1.5 rounded-full"
                                style={{ background: n.project.color }}
                              />
                              {n.project.name}
                            </span>
                          )}
                          <span className="text-[10px] text-[var(--muted)] tabular-nums">
                            {formatRelative(n.created_at)}
                          </span>
                        </div>
                      </div>
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          remove(n.id);
                        }}
                        className="opacity-0 group-hover:opacity-100 p-1 rounded text-[var(--muted)] hover:text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 transition-opacity"
                        title="Supprimer"
                      >
                        <X size={12} />
                      </button>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}

          <div className="border-t border-[var(--border)] px-4 py-2 bg-[var(--color-neutral-50)] dark:bg-[var(--color-neutral-900)]/50">
            <Link
              href="/notifications"
              onClick={() => setOpen(false)}
              className="text-xs text-[var(--color-brand-red)] hover:underline"
            >
              Voir toutes les notifications →
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}

function formatRelative(iso: string): string {
  const now = Date.now();
  const t = new Date(iso).getTime();
  const sec = Math.round((now - t) / 1000);
  if (sec < 60) return "à l'instant";
  if (sec < 3600) return `il y a ${Math.round(sec / 60)}min`;
  if (sec < 86400) return `il y a ${Math.round(sec / 3600)}h`;
  if (sec < 30 * 86400) return `il y a ${Math.round(sec / 86400)}j`;
  return new Date(iso).toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "short",
  });
}
