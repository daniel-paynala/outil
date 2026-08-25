"use client";

import { useEffect, useState, useRef } from "react";

/**
 * Input de date contrôlé qui ne déclenche `onCommit` qu'au blur ou sur Enter,
 * jamais pendant la saisie. Évite le spam de PATCH backend pendant que l'user
 * tape "2026-05-15" caractère par caractère.
 */
export default function DateInput({
  value,
  onCommit,
  min,
  max,
  className,
  ariaLabel,
}: {
  value: string | null;
  onCommit: (next: string | null) => void;
  min?: string;
  max?: string;
  className?: string;
  ariaLabel?: string;
}) {
  const initial = value ? value.slice(0, 10) : "";
  const [local, setLocal] = useState(initial);
  const lastCommitted = useRef(initial);

  // Sync external changes (ex: après PATCH backend qui renvoie la valeur normalisée)
  useEffect(() => {
    const norm = value ? value.slice(0, 10) : "";
    setLocal(norm);
    lastCommitted.current = norm;
  }, [value]);

  function commit() {
    if (local === lastCommitted.current) return;
    // Cas vide → null
    if (local === "") {
      lastCommitted.current = "";
      onCommit(null);
      return;
    }
    // Validation : YYYY-MM-DD parseable
    const d = new Date(local);
    if (isNaN(d.getTime())) {
      // Restore previous valid value
      setLocal(lastCommitted.current);
      return;
    }
    lastCommitted.current = local;
    onCommit(local);
  }

  return (
    <input
      type="date"
      value={local}
      min={min}
      max={max}
      aria-label={ariaLabel}
      onChange={(e) => setLocal(e.target.value)}
      onBlur={commit}
      onKeyDown={(e) => {
        if (e.key === "Enter") {
          e.currentTarget.blur(); // déclenche commit via onBlur
        } else if (e.key === "Escape") {
          setLocal(lastCommitted.current);
          e.currentTarget.blur();
        }
      }}
      className={
        className ??
        "rounded border border-[var(--border)] bg-[var(--background)] px-2 py-1 text-sm"
      }
    />
  );
}
