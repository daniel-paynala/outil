"use client";

import { useEffect, useRef, useState } from "react";
import { Check, ChevronDown, X } from "lucide-react";
import type { ProjectMember } from "@/lib/types";
import Avatar from "@/core/ui/avatar";

/**
 * Multi-select compact pour choisir plusieurs owners parmi les membres du projet.
 * - Affiche les avatars + emails des sélectionnés
 * - Click → dropdown avec checkbox-list filtrée
 * - Click hors → ferme
 */
export default function OwnersPicker({
  members,
  selected,
  onChange,
}: {
  members: ProjectMember[];
  selected: string[];
  onChange: (next: string[]) => void;
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
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

  function toggle(id: string) {
    if (selected.includes(id)) {
      onChange(selected.filter((x) => x !== id));
    } else {
      onChange([...selected, id]);
    }
  }

  const selectedMembers = members.filter((m) => selected.includes(m.id));
  const filtered = search
    ? members.filter((m) =>
        m.email.toLowerCase().includes(search.toLowerCase()),
      )
    : members;

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="w-full mt-1 flex items-center gap-1.5 flex-wrap rounded-md border border-[var(--border)] bg-[var(--background)] px-2 py-1.5 text-sm min-h-[38px] text-left hover:border-[var(--color-neutral-400)]"
      >
        {selectedMembers.length === 0 ? (
          <span className="text-[var(--muted)]">— Aucun</span>
        ) : (
          selectedMembers.map((m) => (
            <span
              key={m.id}
              className="inline-flex items-center gap-1 rounded-full bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] pl-1 pr-1.5 py-0.5 text-xs"
            >
              <Avatar user={m} size="xs" />
              <span className="truncate max-w-[140px]">{m.email}</span>
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  toggle(m.id);
                }}
                className="text-[var(--muted)] hover:text-[var(--color-danger)]"
              >
                <X size={10} />
              </button>
            </span>
          ))
        )}
        <ChevronDown
          size={14}
          className="ml-auto text-[var(--muted)] shrink-0"
        />
      </button>

      {open && (
        <div className="absolute z-50 left-0 right-0 mt-1 max-h-72 overflow-auto rounded-md border border-[var(--border)] bg-[var(--surface)] shadow-lg">
          <div className="p-2 sticky top-0 bg-[var(--surface)] border-b border-[var(--border)]">
            <input
              autoFocus
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Filtrer…"
              className="w-full rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
            />
          </div>
          {filtered.length === 0 ? (
            <p className="px-3 py-4 text-xs text-[var(--muted)] text-center">
              Aucun membre.
            </p>
          ) : (
            <ul className="py-1">
              {filtered.map((m) => {
                const isSel = selected.includes(m.id);
                return (
                  <li key={m.id}>
                    <button
                      type="button"
                      onClick={() => toggle(m.id)}
                      className="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
                    >
                      <span
                        className={`size-4 rounded border flex items-center justify-center shrink-0 ${
                          isSel
                            ? "bg-[var(--color-brand-red)] border-[var(--color-brand-red)] text-white"
                            : "border-[var(--border)]"
                        }`}
                      >
                        {isSel && <Check size={11} strokeWidth={3} />}
                      </span>
                      <Avatar user={m} size="xs" />
                      <span className="truncate">{m.email}</span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
