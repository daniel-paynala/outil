/**
 * Generic Suspense fallback for /(app)/* routes.
 * Shown by Next.js while a server component (page.tsx) is rendering.
 */
export default function Loading() {
  return (
    <div className="space-y-6 animate-pulse">
      <div className="space-y-2">
        <div className="h-3 w-24 rounded bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-800)]" />
        <div className="h-7 w-72 rounded bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-800)]" />
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            className="h-20 rounded-lg border border-[var(--border)] bg-[var(--surface)]"
          />
        ))}
      </div>
      <div className="h-64 rounded-lg border border-[var(--border)] bg-[var(--surface)]" />
    </div>
  );
}
