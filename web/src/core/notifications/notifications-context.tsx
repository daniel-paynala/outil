"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { createClient } from "@/lib/supabase/client";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";

export type NotificationItem = {
  id: string;
  user_id: string;
  project_id: string | null;
  actor_id: string | null;
  type: string;
  title: string;
  body: string | null;
  link: string | null;
  metadata: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
  actor?: {
    id: string;
    email: string;
    name: string | null;
    avatar_url?: string | null;
  } | null;
  project?: {
    id: string;
    name: string;
    slug: string;
    color: string;
  } | null;
};

type Ctx = {
  unreadCount: number;
  recent: NotificationItem[];
  loading: boolean;
  refresh: () => Promise<void>;
  markRead: (id: string) => Promise<void>;
  markAllRead: () => Promise<void>;
  remove: (id: string) => Promise<void>;
};

const NotificationsContext = createContext<Ctx | null>(null);

export function NotificationsProvider({ children }: { children: ReactNode }) {
  const toast = useToast();
  const [unreadCount, setUnreadCount] = useState(0);
  const [recent, setRecent] = useState<NotificationItem[]>([]);
  const [loading, setLoading] = useState(true);
  const userIdRef = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    try {
      const [list, count] = await Promise.all([
        apiFetch("/api/notifications?per_page=20").then((r) =>
          r.ok ? r.json() : { data: [] },
        ),
        apiFetch("/api/notifications/unread-count").then((r) =>
          r.ok ? r.json() : { count: 0 },
        ),
      ]);
      setRecent(list.data ?? []);
      setUnreadCount(count.count ?? 0);
    } catch {
      // silent
    } finally {
      setLoading(false);
    }
  }, []);

  // Initial fetch + Supabase realtime subscription
  useEffect(() => {
    const supabase = createClient();
    let channel: ReturnType<typeof supabase.channel> | null = null;
    let cancelled = false;

    async function setup() {
      const {
        data: { user },
      } = await supabase.auth.getUser();
      if (cancelled) return;
      if (!user) {
        setLoading(false);
        return;
      }
      userIdRef.current = user.id;
      await refresh();

      channel = supabase
        .channel(`notifications:${user.id}`)
        .on(
          "postgres_changes",
          {
            event: "INSERT",
            schema: "public",
            table: "notifications",
            filter: `user_id=eq.${user.id}`,
          },
          (payload) => {
            const notif = payload.new as NotificationItem;
            setRecent((cur) => [notif, ...cur].slice(0, 20));
            setUnreadCount((c) => c + 1);
            toast.info(notif.title, notif.body ?? undefined);
          },
        )
        .subscribe();
    }
    setup();

    return () => {
      cancelled = true;
      if (channel) supabase.removeChannel(channel);
    };
  }, [refresh, toast]);

  const markRead = useCallback(async (id: string) => {
    setRecent((cur) =>
      cur.map((n) =>
        n.id === id && !n.read_at ? { ...n, read_at: new Date().toISOString() } : n,
      ),
    );
    setUnreadCount((c) => Math.max(0, c - 1));
    try {
      await apiFetch(`/api/notifications/${id}/read`, { method: "POST" });
    } catch {
      /* ignore */
    }
  }, []);

  const markAllRead = useCallback(async () => {
    setRecent((cur) =>
      cur.map((n) => (n.read_at ? n : { ...n, read_at: new Date().toISOString() })),
    );
    setUnreadCount(0);
    try {
      await apiFetch("/api/notifications/read-all", { method: "POST" });
    } catch {
      /* ignore */
    }
  }, []);

  const remove = useCallback(
    async (id: string) => {
      const before = recent.find((n) => n.id === id);
      setRecent((cur) => cur.filter((n) => n.id !== id));
      if (before && !before.read_at) {
        setUnreadCount((c) => Math.max(0, c - 1));
      }
      try {
        await apiFetch(`/api/notifications/${id}`, { method: "DELETE" });
      } catch {
        /* ignore */
      }
    },
    [recent],
  );

  return (
    <NotificationsContext.Provider
      value={{ unreadCount, recent, loading, refresh, markRead, markAllRead, remove }}
    >
      {children}
    </NotificationsContext.Provider>
  );
}

export function useNotifications(): Ctx {
  const ctx = useContext(NotificationsContext);
  if (!ctx)
    throw new Error("useNotifications must be used within NotificationsProvider");
  return ctx;
}
