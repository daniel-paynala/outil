"use client";

import { clsx } from "clsx";

type Sized = {
  size?: "xs" | "sm" | "md" | "lg" | "xl";
};

type Identity = {
  email?: string | null;
  name?: string | null;
  avatar_url?: string | null;
};

const SIZES: Record<NonNullable<Sized["size"]>, string> = {
  xs: "size-5 text-[10px]",
  sm: "size-6 text-[11px]",
  md: "size-7 text-xs",
  lg: "size-9 text-sm",
  xl: "size-16 text-xl",
};

/**
 * Avatar carré arrondi qui affiche la photo si présente, sinon l'initiale.
 * Fallback : 1ère lettre du nom, ou de l'email, ou "?".
 */
export default function Avatar({
  user,
  size = "md",
  className,
}: {
  user: Identity | null | undefined;
  className?: string;
} & Sized) {
  const initial = ((user?.name ?? user?.email ?? "?")
    .charAt(0)
    .toUpperCase());

  const base = clsx(
    "rounded-full flex items-center justify-center font-medium overflow-hidden shrink-0",
    SIZES[size],
    className,
  );

  if (user?.avatar_url) {
    return (
      <img
        src={user.avatar_url}
        alt={user?.name ?? user?.email ?? "Avatar"}
        className={clsx(base, "object-cover bg-[var(--color-neutral-200)]")}
      />
    );
  }

  return (
    <span
      className={clsx(
        base,
        "bg-[var(--color-brand-red)] text-white",
      )}
      title={user?.email ?? user?.name ?? undefined}
    >
      {initial}
    </span>
  );
}
