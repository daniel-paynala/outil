"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import { usePathname, useSearchParams } from "next/navigation";

/**
 * Linear progress bar at the top of the viewport, shown during route navigation.
 * Triggered by:
 *  - Internal <a>/<Link> clicks (DOM capture)
 *  - Programmatic navigation followed by pathname/searchParams change
 * Auto-completes when the new route is fully rendered.
 */
export default function TopLoader() {
  return (
    <Suspense fallback={null}>
      <TopLoaderInner />
    </Suspense>
  );
}

function TopLoaderInner() {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [progress, setProgress] = useState(0);
  const [visible, setVisible] = useState(false);
  const fadeTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const tickRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const safetyRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const lastKey = useRef<string>(`${pathname}?${searchParams.toString()}`);

  function clearAll() {
    if (tickRef.current) clearInterval(tickRef.current);
    if (fadeTimeoutRef.current) clearTimeout(fadeTimeoutRef.current);
    if (safetyRef.current) clearTimeout(safetyRef.current);
  }

  function start() {
    clearAll();
    setVisible(true);
    setProgress(12);
    // Trickle progress up to 90% while waiting for navigation to settle
    tickRef.current = setInterval(() => {
      setProgress((p) => {
        if (p >= 90) return p;
        // Slow down as we approach the cap
        const delta = (95 - p) * 0.08;
        return Math.min(90, p + delta);
      });
    }, 200);
    // Safety net : auto-finish after 8s
    safetyRef.current = setTimeout(() => finish(), 8000);
  }

  function finish() {
    if (tickRef.current) clearInterval(tickRef.current);
    if (safetyRef.current) clearTimeout(safetyRef.current);
    setProgress(100);
    fadeTimeoutRef.current = setTimeout(() => {
      setVisible(false);
      setProgress(0);
    }, 220);
  }

  // Intercept link clicks to start the loader as soon as the user commits
  useEffect(() => {
    function onClick(e: MouseEvent) {
      // Only respond to plain left clicks
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey)
        return;
      const target = (e.target as HTMLElement)?.closest?.(
        "a[href]",
      ) as HTMLAnchorElement | null;
      if (!target) return;
      const href = target.getAttribute("href");
      if (!href || href.startsWith("#")) return;
      if (target.target === "_blank") return;
      let url: URL;
      try {
        url = new URL(href, window.location.origin);
      } catch {
        return;
      }
      if (url.origin !== window.location.origin) return;
      // Same URL → no nav
      if (
        url.pathname === window.location.pathname &&
        url.search === window.location.search
      )
        return;
      start();
    }
    document.addEventListener("click", onClick);
    return () => document.removeEventListener("click", onClick);
  }, []);

  // Detect actual route change — this is the "done" signal
  useEffect(() => {
    const key = `${pathname}?${searchParams.toString()}`;
    if (key !== lastKey.current) {
      lastKey.current = key;
      if (visible) finish();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname, searchParams]);

  useEffect(() => {
    return () => clearAll();
  }, []);

  return (
    <div
      aria-hidden
      className="pointer-events-none fixed inset-x-0 top-0 z-[200] h-[2px]"
      style={{ display: visible ? "block" : "none" }}
    >
      <div
        className="h-full bg-[var(--color-brand-red)] shadow-[0_0_8px_rgba(217,52,43,0.6)] transition-[width,opacity] duration-200 ease-out"
        style={{
          width: `${progress}%`,
          opacity: progress >= 100 ? 0 : 1,
        }}
      />
    </div>
  );
}
