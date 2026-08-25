"use client";

import { useState, useMemo, useCallback } from "react";
import { useRouter, useSearchParams, usePathname } from "next/navigation";
import {
  LayoutGrid,
  GitBranch,
  Package,
  List,
  CalendarDays,
  Network,
  Plus,
  Map,
} from "lucide-react";
import { clsx } from "clsx";
import type {
  ProjectMember,
  Release,
  RoadmapItem,
  RoadmapView,
} from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import ViewBoard from "./view-board";
import ViewTimeline from "./view-timeline";
import ViewReleases from "./view-releases";
import ViewList from "./view-list";
import ViewCalendar from "./view-calendar";
import ViewMindmap from "./view-mindmap";
import NewItemForm from "./new-item-form";
import ItemDrawer from "./item-drawer";

const VIEWS: { key: RoadmapView; label: string; icon: typeof Map }[] = [
  { key: "board", label: "Board", icon: LayoutGrid },
  { key: "timeline", label: "Timeline", icon: GitBranch },
  { key: "releases", label: "Releases", icon: Package },
  { key: "list", label: "Liste", icon: List },
  { key: "calendar", label: "Calendrier", icon: CalendarDays },
  { key: "mindmap", label: "Thèmes", icon: Network },
];

export default function RoadmapClient({
  projectId,
  currentUserId,
  members,
  initialItems,
  initialReleases,
}: {
  projectId: string;
  currentUserId: string;
  members: ProjectMember[];
  initialItems: RoadmapItem[];
  initialReleases: Release[];
}) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const toast = useToast();

  const [items, setItems] = useState<RoadmapItem[]>(initialItems);
  const [releases, setReleases] = useState<Release[]>(initialReleases);
  const [creating, setCreating] = useState(false);
  const [openItemId, setOpenItemIdState] = useState<string | null>(
    () => searchParams.get("item"),
  );

  const initialView = (searchParams.get("view") as RoadmapView) || "board";
  const [view, setViewState] = useState<RoadmapView>(initialView);

  const setView = useCallback(
    (v: RoadmapView) => {
      setViewState(v);
      const params = new URLSearchParams(searchParams.toString());
      params.set("view", v);
      router.replace(`${pathname}?${params.toString()}`, { scroll: false });
    },
    [pathname, router, searchParams],
  );

  const setOpenItemId = useCallback(
    (id: string | null) => {
      setOpenItemIdState(id);
      const params = new URLSearchParams(searchParams.toString());
      if (id) params.set("item", id);
      else params.delete("item");
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [pathname, router, searchParams],
  );

  const onItemCreated = useCallback((item: RoadmapItem) => {
    setItems((prev) => [...prev, item]);
    setCreating(false);
  }, []);

  const onItemUpdated = useCallback((updated: RoadmapItem) => {
    setItems((prev) => prev.map((i) => (i.id === updated.id ? updated : i)));
  }, []);

  const onItemDeleted = useCallback((id: string) => {
    setItems((prev) => prev.filter((i) => i.id !== id));
  }, []);

  const onReleaseCreated = useCallback((release: Release) => {
    setReleases((prev) => [release, ...prev]);
  }, []);

  const onReleaseUpdated = useCallback((updated: Release) => {
    setReleases((prev) => prev.map((r) => (r.id === updated.id ? updated : r)));
  }, []);

  const onReleaseDeleted = useCallback((id: string) => {
    setReleases((prev) => prev.filter((r) => r.id !== id));
    setItems((prev) =>
      prev.map((i) => (i.release_id === id ? { ...i, release_id: null } : i)),
    );
  }, []);

  const onMove = useCallback(
    async (itemId: string, toHorizon: string, toPosition: number) => {
      // Optimistic update
      const item = items.find((i) => i.id === itemId);
      if (!item) return;

      setItems((prev) => {
        const others = prev.filter((i) => i.id !== itemId);
        const inSameHorizon = others.filter((i) => i.horizon === toHorizon);
        const beforeInsert = inSameHorizon.slice(0, toPosition);
        const afterInsert = inSameHorizon.slice(toPosition);
        const updated = { ...item, horizon: toHorizon as RoadmapItem["horizon"] };
        const ordered = [...beforeInsert, updated, ...afterInsert].map(
          (i, idx) => ({ ...i, position: idx }),
        );
        return [
          ...others.filter((i) => i.horizon !== toHorizon),
          ...ordered,
        ];
      });

      try {
        const res = await apiFetch(`/api/projects/${projectId}/roadmap/move`, {
          method: "POST",
          body: JSON.stringify({
            item_id: itemId,
            to_horizon: toHorizon,
            to_position: toPosition,
          }),
        });
        if (!res.ok) {
          toast.error(
            "Déplacement impossible",
            (await res.text()) || `Erreur ${res.status}`,
          );
          // Refetch to revert
          const fresh = await apiFetch(`/api/projects/${projectId}/roadmap`);
          if (fresh.ok) {
            const data = await fresh.json();
            setItems(data.items);
          }
        }
      } catch (err) {
        toast.error(
          "Déplacement impossible",
          err instanceof Error ? err.message : String(err),
        );
      }
    },
    [items, projectId, toast],
  );

  const stats = useMemo(() => {
    const byHorizon = { now: 0, next: 0, later: 0, done: 0 };
    items.forEach((i) => byHorizon[i.horizon]++);
    return byHorizon;
  }, [items]);

  return (
    <div className="space-y-6">
      <header className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
            Vision et trajectoire
          </p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight flex items-center gap-2">
            <Map size={22} strokeWidth={1.75} />
            Roadmap
          </h1>
          <p className="mt-2 text-sm text-[var(--muted)] max-w-2xl">
            {stats.now} en cours · {stats.next} à venir · {stats.later} envisagés ·{" "}
            {stats.done} livrés ({releases.length} releases)
          </p>
        </div>
        <div className="flex items-center gap-2">
          <ViewSwitcher view={view} setView={setView} />
          <button
            onClick={() => setCreating(true)}
            className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1.5 text-sm font-medium"
          >
            <Plus size={14} />
            Nouvel item
          </button>
        </div>
      </header>

      {creating && (
        <NewItemForm
          projectId={projectId}
          members={members}
          releases={releases}
          onClose={() => setCreating(false)}
          onCreated={onItemCreated}
        />
      )}

      <ViewSwitch
        view={view}
        items={items}
        releases={releases}
        members={members}
        currentUserId={currentUserId}
        projectId={projectId}
        onOpenItem={setOpenItemId}
        onMove={onMove}
        onReleaseCreated={onReleaseCreated}
        onReleaseUpdated={onReleaseUpdated}
        onReleaseDeleted={onReleaseDeleted}
      />

      {openItemId && (
        <ItemDrawer
          key={openItemId}
          itemId={openItemId}
          projectId={projectId}
          members={members}
          releases={releases}
          onClose={() => setOpenItemId(null)}
          onUpdated={onItemUpdated}
          onDeleted={() => {
            onItemDeleted(openItemId);
            setOpenItemId(null);
          }}
        />
      )}
    </div>
  );
}

function ViewSwitcher({
  view,
  setView,
}: {
  view: RoadmapView;
  setView: (v: RoadmapView) => void;
}) {
  return (
    <div className="inline-flex rounded-md border border-[var(--border)] p-0.5">
      {VIEWS.map(({ key, label, icon: Icon }) => (
        <button
          key={key}
          onClick={() => setView(key)}
          aria-pressed={view === key}
          title={label}
          className={clsx(
            "inline-flex items-center gap-1 rounded px-2 py-1 text-xs",
            view === key
              ? "bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)]"
              : "text-[var(--muted)] hover:text-[var(--foreground)]",
          )}
        >
          <Icon size={12} strokeWidth={2} />
          <span className="hidden md:inline">{label}</span>
        </button>
      ))}
    </div>
  );
}

function ViewSwitch(props: {
  view: RoadmapView;
  items: RoadmapItem[];
  releases: Release[];
  members: ProjectMember[];
  currentUserId: string;
  projectId: string;
  onOpenItem: (id: string) => void;
  onMove: (id: string, h: string, pos: number) => void;
  onReleaseCreated: (r: Release) => void;
  onReleaseUpdated: (r: Release) => void;
  onReleaseDeleted: (id: string) => void;
}) {
  const { view, items, releases, members, projectId, onOpenItem, onMove } = props;

  switch (view) {
    case "board":
      return <ViewBoard items={items} onOpenItem={onOpenItem} onMove={onMove} />;
    case "timeline":
      return <ViewTimeline items={items} onOpenItem={onOpenItem} />;
    case "releases":
      return (
        <ViewReleases
          items={items}
          releases={releases}
          projectId={projectId}
          onOpenItem={onOpenItem}
          onReleaseCreated={props.onReleaseCreated}
          onReleaseUpdated={props.onReleaseUpdated}
          onReleaseDeleted={props.onReleaseDeleted}
        />
      );
    case "list":
      return (
        <ViewList items={items} members={members} onOpenItem={onOpenItem} />
      );
    case "calendar":
      return <ViewCalendar items={items} onOpenItem={onOpenItem} />;
    case "mindmap":
      return <ViewMindmap items={items} onOpenItem={onOpenItem} />;
  }
}
