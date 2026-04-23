"use client";

import { useState, useEffect, useMemo } from "react";
import {
  Play,
  Square,
  Plus,
  Trash2,
  Timer,
  User as UserIcon,
} from "lucide-react";
import { clsx } from "clsx";
import { apiFetch } from "@/lib/api/client";
import type { TimeEntry } from "@/lib/types";

type CardOption = { id: string; title: string };

export default function TimeClient({
  projectId,
  currentUserId,
  initialEntries,
  initialCards,
  initialRunning,
}: {
  projectId: string;
  currentUserId: string;
  initialEntries: TimeEntry[];
  initialCards: CardOption[];
  initialRunning: TimeEntry | null;
}) {
  const [entries, setEntries] = useState<TimeEntry[]>(initialEntries);
  const [running, setRunning] = useState<TimeEntry | null>(initialRunning);
  const [mineOnly, setMineOnly] = useState(false);
  const [now, setNow] = useState(Date.now());
  const [startDesc, setStartDesc] = useState("");
  const [startCard, setStartCard] = useState<string>("");
  const [manualOpen, setManualOpen] = useState(false);

  // ticker for active timer
  useEffect(() => {
    if (!running) return;
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [running]);

  const filtered = useMemo(
    () => (mineOnly ? entries.filter((e) => e.user_id === currentUserId) : entries),
    [entries, mineOnly, currentUserId],
  );

  const totals = useMemo(() => bucketTotals(filtered), [filtered]);

  async function handleStart() {
    const res = await apiFetch(`/api/projects/${projectId}/time/start`, {
      method: "POST",
      body: JSON.stringify({
        card_id: startCard || null,
        description: startDesc || null,
      }),
    });
    if (!res.ok) return;
    const entry = (await res.json()) as TimeEntry;
    setRunning(entry);
    setStartDesc("");
    setStartCard("");
  }

  async function handleStop() {
    if (!running) return;
    const res = await apiFetch(`/api/time/${running.id}/stop`, {
      method: "POST",
    });
    if (!res.ok) return;
    const stopped = (await res.json()) as TimeEntry;
    setRunning(null);
    setEntries((prev) => [stopped, ...prev.filter((e) => e.id !== stopped.id)]);
  }

  async function handleManual(entry: TimeEntry) {
    setEntries((prev) => [entry, ...prev]);
    setManualOpen(false);
  }

  async function handleDelete(entry: TimeEntry) {
    if (entry.user_id !== currentUserId) return;
    if (!confirm("Supprimer cette entrée ?")) return;
    const res = await apiFetch(`/api/time/${entry.id}`, { method: "DELETE" });
    if (res.ok) setEntries((prev) => prev.filter((e) => e.id !== entry.id));
  }

  const runningSeconds = running
    ? Math.max(0, Math.floor((now - new Date(running.started_at).getTime()) / 1000))
    : 0;

  return (
    <div className="space-y-6">
      <header className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
            Suivi du temps
          </p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight">Temps</h1>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => setMineOnly((v) => !v)}
            className={clsx(
              "inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors",
              mineOnly
                ? "bg-[var(--color-brand-red)] border-[var(--color-brand-red)] text-white"
                : "border-[var(--border)] hover:border-[var(--color-neutral-400)]",
            )}
          >
            <UserIcon size={12} />
            Mes entrées
          </button>
          <button
            onClick={() => setManualOpen(true)}
            className="inline-flex items-center gap-1.5 rounded-md border border-[var(--border)] hover:border-[var(--color-neutral-400)] px-2.5 py-1.5 text-xs font-medium"
          >
            <Plus size={12} />
            Entrée manuelle
          </button>
        </div>
      </header>

      <section className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5">
        {running ? (
          <div className="flex flex-col sm:flex-row sm:items-center gap-3">
            <div className="flex items-center gap-3 flex-1 min-w-0">
              <span className="relative flex shrink-0">
                <span className="absolute inline-flex size-full rounded-full bg-[var(--color-brand-red)] opacity-40 animate-ping" />
                <Timer
                  size={22}
                  strokeWidth={1.75}
                  className="text-[var(--color-brand-red)]"
                />
              </span>
              <div className="flex-1 min-w-0">
                <p className="text-lg font-semibold tabular-nums">
                  {formatHms(runningSeconds)}
                </p>
                <p className="text-xs text-[var(--muted)] truncate">
                  {running.description ?? "Sans description"}
                  {running.card && <> · {running.card.title}</>}
                </p>
              </div>
            </div>
            <button
              onClick={handleStop}
              className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3.5 py-2 text-sm font-medium"
            >
              <Square size={13} />
              Arrêter
            </button>
          </div>
        ) : (
          <div className="flex flex-col sm:flex-row sm:items-center gap-2">
            <input
              value={startDesc}
              onChange={(e) => setStartDesc(e.target.value)}
              placeholder="Sur quoi tu bosses ?"
              className="flex-1 rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
            />
            <select
              value={startCard}
              onChange={(e) => setStartCard(e.target.value)}
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm min-w-48"
            >
              <option value="">Aucune carte liée</option>
              {initialCards.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.title}
                </option>
              ))}
            </select>
            <button
              onClick={handleStart}
              className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3.5 py-2 text-sm font-medium"
            >
              <Play size={13} />
              Démarrer
            </button>
          </div>
        )}
      </section>

      <section className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <StatCard label="Aujourd'hui" value={formatHours(totals.today)} />
        <StatCard label="Cette semaine" value={formatHours(totals.week)} />
        <StatCard label="Ce mois" value={formatHours(totals.month)} />
        <StatCard label="Total" value={formatHours(totals.total)} />
      </section>

      {manualOpen && (
        <ManualEntryForm
          projectId={projectId}
          cards={initialCards}
          onClose={() => setManualOpen(false)}
          onCreated={handleManual}
        />
      )}

      <section>
        <h2 className="text-sm font-medium mb-3">Historique</h2>
        {filtered.length === 0 ? (
          <p className="text-sm text-[var(--muted)] text-center py-10">
            Aucune entrée de temps.
          </p>
        ) : (
          <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
            {filtered.map((e) => {
              const date = new Date(e.started_at);
              const seconds = e.seconds ?? 0;
              return (
                <li
                  key={e.id}
                  className="flex items-center gap-3 px-4 py-3 group"
                >
                  <div className="flex-1 min-w-0">
                    <p className="text-sm truncate">
                      {e.description ?? (
                        <span className="text-[var(--muted)] italic">
                          Sans description
                        </span>
                      )}
                    </p>
                    <p className="mt-0.5 text-xs text-[var(--muted)] truncate">
                      {e.card && <>{e.card.title} · </>}
                      {date.toLocaleDateString("fr-FR", {
                        day: "numeric",
                        month: "short",
                        year: "numeric",
                      })}
                      {" à "}
                      {date.toLocaleTimeString("fr-FR", {
                        hour: "2-digit",
                        minute: "2-digit",
                      })}
                      {e.user && <> · {e.user.email}</>}
                    </p>
                  </div>
                  <span className="text-sm font-medium tabular-nums shrink-0">
                    {formatHms(seconds)}
                  </span>
                  {e.user_id === currentUserId && (
                    <button
                      onClick={() => handleDelete(e)}
                      className="opacity-0 group-hover:opacity-100 p-1.5 rounded text-[var(--muted)] hover:text-[var(--color-danger)]"
                      aria-label="Supprimer"
                    >
                      <Trash2 size={13} />
                    </button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </section>
    </div>
  );
}

function ManualEntryForm({
  projectId,
  cards,
  onClose,
  onCreated,
}: {
  projectId: string;
  cards: CardOption[];
  onClose: () => void;
  onCreated: (e: TimeEntry) => void;
}) {
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [time, setTime] = useState("09:00");
  const [hours, setHours] = useState("1");
  const [minutes, setMinutes] = useState("0");
  const [description, setDescription] = useState("");
  const [cardId, setCardId] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    function onEsc(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onEsc);
    return () => window.removeEventListener("keydown", onEsc);
  }, [onClose]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const seconds =
      (parseInt(hours || "0", 10) || 0) * 3600 +
      (parseInt(minutes || "0", 10) || 0) * 60;
    if (seconds <= 0) {
      setError("Durée invalide");
      return;
    }
    setLoading(true);
    setError(null);
    const res = await apiFetch(`/api/projects/${projectId}/time`, {
      method: "POST",
      body: JSON.stringify({
        description: description || null,
        card_id: cardId || null,
        started_at: `${date}T${time}:00`,
        seconds,
      }),
    });
    setLoading(false);
    if (!res.ok) {
      setError(await res.text());
      return;
    }
    onCreated((await res.json()) as TimeEntry);
  }

  return (
    <div className="fixed inset-0 z-40 flex">
      <div className="flex-1 bg-black/20" onClick={onClose} />
      <aside className="w-full max-w-md bg-[var(--background)] border-l border-[var(--border)] shadow-2xl flex flex-col">
        <header className="px-6 py-4 border-b border-[var(--border)]">
          <h3 className="text-sm font-medium">Entrée manuelle</h3>
        </header>
        <form onSubmit={submit} className="flex-1 overflow-y-auto px-6 py-5 space-y-4">
          <div className="space-y-1.5">
            <label className="text-xs font-medium">Description</label>
            <input
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="ex: Refacto auth middleware"
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
            />
          </div>
          <div className="space-y-1.5">
            <label className="text-xs font-medium">Carte liée</label>
            <select
              value={cardId}
              onChange={(e) => setCardId(e.target.value)}
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
            >
              <option value="">Aucune</option>
              {cards.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.title}
                </option>
              ))}
            </select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <label className="text-xs font-medium">Date</label>
              <input
                type="date"
                value={date}
                onChange={(e) => setDate(e.target.value)}
                required
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-medium">Heure de début</label>
              <input
                type="time"
                value={time}
                onChange={(e) => setTime(e.target.value)}
                required
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
              />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <label className="text-xs font-medium">Heures</label>
              <input
                type="number"
                min={0}
                max={24}
                value={hours}
                onChange={(e) => setHours(e.target.value)}
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
              />
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-medium">Minutes</label>
              <input
                type="number"
                min={0}
                max={59}
                value={minutes}
                onChange={(e) => setMinutes(e.target.value)}
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
              />
            </div>
          </div>
          {error && (
            <p className="text-xs text-[var(--color-danger)]">{error}</p>
          )}
        </form>
        <footer className="px-6 py-4 border-t border-[var(--border)] flex gap-2">
          <button
            onClick={submit}
            disabled={loading}
            className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-4 py-2 text-sm font-medium disabled:opacity-50"
          >
            Enregistrer
          </button>
          <button
            onClick={onClose}
            className="text-sm text-[var(--muted)] hover:text-[var(--foreground)] px-3 py-2"
          >
            Annuler
          </button>
        </footer>
      </aside>
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-4">
      <p className="text-xs text-[var(--muted)]">{label}</p>
      <p className="mt-1 text-xl font-semibold tabular-nums">{value}</p>
    </div>
  );
}

function bucketTotals(entries: TimeEntry[]) {
  const now = new Date();
  const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const dow = now.getDay(); // 0 = Sunday
  const daysToMonday = (dow + 6) % 7; // Monday = 0
  const startOfWeek = startOfDay - daysToMonday * 24 * 3600 * 1000;
  const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1).getTime();

  let today = 0,
    week = 0,
    month = 0,
    total = 0;

  for (const e of entries) {
    const s = e.seconds ?? 0;
    total += s;
    const started = new Date(e.started_at).getTime();
    if (started >= startOfDay) today += s;
    if (started >= startOfWeek) week += s;
    if (started >= startOfMonth) month += s;
  }

  return { today, week, month, total };
}

function formatHms(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  if (h > 0) return `${h}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
  return `${m}:${String(s).padStart(2, "0")}`;
}

function formatHours(seconds: number): string {
  const h = seconds / 3600;
  if (h < 0.01) return "0h";
  return `${h.toFixed(h >= 10 ? 0 : 1)}h`;
}
