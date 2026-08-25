"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";
import { CheckCircle2, AlertCircle, Info, X } from "lucide-react";
import { clsx } from "clsx";

export type ToastVariant = "success" | "error" | "info";

export type Toast = {
  id: number;
  variant: ToastVariant;
  title: string;
  description?: string;
  duration?: number;
};

type ToastContextValue = {
  toast: (t: Omit<Toast, "id">) => void;
  success: (title: string, description?: string) => void;
  error: (title: string, description?: string) => void;
  info: (title: string, description?: string) => void;
  dismiss: (id: number) => void;
};

const ToastContext = createContext<ToastContextValue | null>(null);

let nextId = 1;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const dismiss = useCallback((id: number) => {
    setToasts((cur) => cur.filter((t) => t.id !== id));
  }, []);

  const toast = useCallback((t: Omit<Toast, "id">) => {
    const id = nextId++;
    setToasts((cur) => [...cur, { ...t, id }]);
  }, []);

  const success = useCallback(
    (title: string, description?: string) =>
      toast({ variant: "success", title, description }),
    [toast],
  );
  const error = useCallback(
    (title: string, description?: string) =>
      toast({ variant: "error", title, description, duration: 6000 }),
    [toast],
  );
  const info = useCallback(
    (title: string, description?: string) =>
      toast({ variant: "info", title, description }),
    [toast],
  );

  return (
    <ToastContext.Provider value={{ toast, success, error, info, dismiss }}>
      {children}
      <ToastViewport toasts={toasts} dismiss={dismiss} />
    </ToastContext.Provider>
  );
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error("useToast must be used within ToastProvider");
  return ctx;
}

function ToastViewport({
  toasts,
  dismiss,
}: {
  toasts: Toast[];
  dismiss: (id: number) => void;
}) {
  return (
    <div className="fixed bottom-4 right-4 z-[100] flex flex-col gap-2 w-[min(380px,calc(100vw-2rem))] pointer-events-none">
      {toasts.map((t) => (
        <ToastItem key={t.id} toast={t} dismiss={dismiss} />
      ))}
    </div>
  );
}

function ToastItem({
  toast,
  dismiss,
}: {
  toast: Toast;
  dismiss: (id: number) => void;
}) {
  const [leaving, setLeaving] = useState(false);
  const duration = toast.duration ?? 4000;

  useEffect(() => {
    const timeout = setTimeout(() => setLeaving(true), duration);
    return () => clearTimeout(timeout);
  }, [duration]);

  useEffect(() => {
    if (!leaving) return;
    const timeout = setTimeout(() => dismiss(toast.id), 200);
    return () => clearTimeout(timeout);
  }, [leaving, toast.id, dismiss]);

  const Icon =
    toast.variant === "success"
      ? CheckCircle2
      : toast.variant === "error"
        ? AlertCircle
        : Info;

  const colorVar =
    toast.variant === "success"
      ? "var(--color-success)"
      : toast.variant === "error"
        ? "var(--color-danger)"
        : "var(--color-info)";

  return (
    <div
      role="status"
      aria-live="polite"
      className={clsx(
        "pointer-events-auto rounded-lg border bg-[var(--surface)] shadow-lg px-3.5 py-3 flex items-start gap-3",
        "transition-all duration-200 ease-out",
        leaving
          ? "opacity-0 translate-x-2"
          : "opacity-100 translate-x-0 animate-toast-in",
      )}
      style={{ borderColor: `color-mix(in srgb, ${colorVar} 35%, transparent)` }}
    >
      <Icon size={16} style={{ color: colorVar }} className="mt-0.5 shrink-0" />
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium leading-tight">{toast.title}</p>
        {toast.description && (
          <p className="text-xs text-[var(--muted)] mt-1 leading-snug">
            {toast.description}
          </p>
        )}
      </div>
      <button
        onClick={() => setLeaving(true)}
        className="shrink-0 text-[var(--muted)] hover:text-[var(--foreground)] transition-colors"
        aria-label="Fermer"
      >
        <X size={14} />
      </button>
    </div>
  );
}
