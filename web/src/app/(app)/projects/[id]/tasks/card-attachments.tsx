"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type DragEvent,
} from "react";
import {
  Paperclip,
  Plus,
  Loader2,
  Trash2,
  Download,
  FileText,
  FileImage,
  FileArchive,
  FileCode,
  FileSpreadsheet,
  File as FileIcon,
  type LucideIcon,
} from "lucide-react";
import { clsx } from "clsx";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import Avatar from "@/core/ui/avatar";
import type { CardAttachment } from "@/lib/types";

type SignedUrlResponse = {
  url: string;
  expires_in: number;
  name: string;
  mime_type: string | null;
};

export default function CardAttachments({
  cardId,
  onCountChange,
}: {
  cardId: string;
  onCountChange?: (n: number) => void;
}) {
  const toast = useToast();
  const [items, setItems] = useState<CardAttachment[] | null>(null);
  const [uploading, setUploading] = useState(false);
  const [dragActive, setDragActive] = useState(false);
  const [thumbs, setThumbs] = useState<Record<string, string>>({});
  const inputRef = useRef<HTMLInputElement>(null);

  // Load attachments
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const res = await apiFetch(`/api/cards/${cardId}/attachments`);
      if (!res.ok) {
        if (!cancelled) setItems([]);
        return;
      }
      const list = (await res.json()) as CardAttachment[];
      if (!cancelled) {
        setItems(list);
        onCountChange?.(list.length);
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cardId]);

  // Lazy-fetch signed thumbnails for images
  useEffect(() => {
    if (!items) return;
    const images = items.filter(
      (a) => (a.mime_type ?? "").startsWith("image/") && !thumbs[a.id],
    );
    if (images.length === 0) return;

    let cancelled = false;
    (async () => {
      const entries = await Promise.all(
        images.map(async (a) => {
          try {
            const res = await apiFetch(`/api/attachments/${a.id}`);
            if (!res.ok) return null;
            const data = (await res.json()) as SignedUrlResponse;
            return [a.id, data.url] as const;
          } catch {
            return null;
          }
        }),
      );
      if (cancelled) return;
      setThumbs((prev) => {
        const next = { ...prev };
        for (const e of entries) if (e) next[e[0]] = e[1];
        return next;
      });
    })();

    return () => {
      cancelled = true;
    };
  }, [items, thumbs]);

  const upload = useCallback(
    async (list: FileList | File[]) => {
      const arr = Array.from(list);
      if (arr.length === 0) return;
      setUploading(true);
      try {
        const results = await Promise.allSettled(
          arr.map(async (f) => {
            const form = new FormData();
            form.append("file", f);
            const res = await apiFetch(`/api/cards/${cardId}/attachments`, {
              method: "POST",
              body: form,
            });
            if (!res.ok) {
              const txt = await res.text();
              throw new Error(`${f.name} : ${txt || res.status}`);
            }
            return (await res.json()) as CardAttachment;
          }),
        );

        const created: CardAttachment[] = [];
        for (const r of results) {
          if (r.status === "fulfilled") {
            created.push(r.value);
            toast.success("Fichier ajouté", r.value.name);
          } else {
            const msg =
              r.reason instanceof Error ? r.reason.message : String(r.reason);
            toast.error("Envoi impossible", msg);
          }
        }
        if (created.length > 0) {
          setItems((prev) => {
            const next = [...created, ...(prev ?? [])];
            onCountChange?.(next.length);
            return next;
          });
        }
      } finally {
        setUploading(false);
      }
    },
    [cardId, toast, onCountChange],
  );

  async function handleDownload(att: CardAttachment) {
    try {
      const res = await apiFetch(`/api/attachments/${att.id}`);
      if (!res.ok) {
        toast.error(
          "Téléchargement impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      const data = (await res.json()) as SignedUrlResponse;
      window.open(data.url, "_blank");
    } catch (err) {
      toast.error(
        "Téléchargement impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  async function handleDelete(att: CardAttachment) {
    if (!confirm(`Supprimer "${att.name}" ?`)) return;
    try {
      const res = await apiFetch(`/api/attachments/${att.id}`, {
        method: "DELETE",
      });
      if (!res.ok) {
        toast.error(
          "Suppression impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      setItems((prev) => {
        const next = (prev ?? []).filter((a) => a.id !== att.id);
        onCountChange?.(next.length);
        return next;
      });
      setThumbs((prev) => {
        const { [att.id]: _omit, ...rest } = prev;
        return rest;
      });
      toast.success("Supprimé", att.name);
    } catch (err) {
      toast.error(
        "Suppression impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  function onDragOver(e: DragEvent<HTMLDivElement>) {
    e.preventDefault();
  }
  function onDragEnter(e: DragEvent<HTMLDivElement>) {
    e.preventDefault();
    setDragActive(true);
  }
  function onDragLeave(e: DragEvent<HTMLDivElement>) {
    e.preventDefault();
    setDragActive(false);
  }
  function onDrop(e: DragEvent<HTMLDivElement>) {
    e.preventDefault();
    setDragActive(false);
    if (e.dataTransfer.files.length > 0) upload(e.dataTransfer.files);
  }

  const count = items?.length ?? 0;

  return (
    <section>
      <div className="flex items-center justify-between mb-3">
        <h3 className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-[var(--muted)]">
          <Paperclip size={12} strokeWidth={2} />
          Pièces jointes {items && `(${count})`}
        </h3>
        <button
          onClick={() => inputRef.current?.click()}
          disabled={uploading}
          className="inline-flex items-center gap-1 text-[11px] rounded border border-dashed border-[var(--border)] px-2 py-0.5 text-[var(--muted)] hover:text-[var(--foreground)] disabled:opacity-50"
        >
          {uploading ? (
            <Loader2 size={10} className="animate-spin" />
          ) : (
            <Plus size={10} />
          )}
          Ajouter
        </button>
        <input
          ref={inputRef}
          type="file"
          multiple
          className="hidden"
          onChange={(e) => {
            if (e.target.files && e.target.files.length > 0) {
              upload(e.target.files);
              e.target.value = "";
            }
          }}
        />
      </div>

      <div
        onDragEnter={onDragEnter}
        onDragOver={onDragOver}
        onDragLeave={onDragLeave}
        onDrop={onDrop}
        className={clsx(
          "rounded-md border border-dashed transition-colors",
          dragActive
            ? "border-[var(--color-brand-red)] bg-[var(--color-brand-red)]/5"
            : "border-[var(--border)]",
          count === 0 ? "p-4" : "p-2",
        )}
      >
        {items === null ? (
          <p className="text-xs text-[var(--muted)] text-center py-2">
            Chargement…
          </p>
        ) : count === 0 ? (
          <p className="text-xs text-[var(--muted)] text-center py-2">
            {dragActive
              ? "Déposez ici pour ajouter…"
              : "Aucune pièce jointe — glissez-déposez ou cliquez sur Ajouter."}
          </p>
        ) : (
          <ul className="space-y-1.5">
            {items.map((a) => (
              <AttachmentRow
                key={a.id}
                att={a}
                thumbUrl={thumbs[a.id]}
                onDownload={() => handleDownload(a)}
                onDelete={() => handleDelete(a)}
              />
            ))}
          </ul>
        )}

        {uploading && count > 0 && (
          <div className="mt-2 inline-flex items-center gap-2 text-[11px] text-[var(--muted)]">
            <Loader2 size={11} className="animate-spin" />
            Envoi en cours…
          </div>
        )}
      </div>
    </section>
  );
}

function AttachmentRow({
  att,
  thumbUrl,
  onDownload,
  onDelete,
}: {
  att: CardAttachment;
  thumbUrl: string | undefined;
  onDownload: () => void;
  onDelete: () => void;
}) {
  const isImage = (att.mime_type ?? "").startsWith("image/");
  const Icon = iconFor(att.mime_type, att.name);

  return (
    <li className="flex items-center gap-3 rounded-md border border-[var(--border)] bg-[var(--surface)] p-2 group">
      {isImage ? (
        thumbUrl ? (
          <button
            onClick={() => window.open(thumbUrl, "_blank")}
            className="size-12 rounded overflow-hidden shrink-0 bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)]"
            title={`Ouvrir ${att.name}`}
          >
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={thumbUrl}
              alt={att.name}
              className="size-full object-cover"
            />
          </button>
        ) : (
          <div className="size-12 rounded shrink-0 bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)] flex items-center justify-center">
            <Loader2
              size={14}
              className="animate-spin text-[var(--muted)]"
            />
          </div>
        )
      ) : (
        <div className="size-12 rounded shrink-0 bg-[var(--color-neutral-100)] dark:bg-[var(--color-neutral-800)] flex items-center justify-center">
          <Icon
            size={20}
            strokeWidth={1.5}
            className="text-[var(--muted)]"
          />
        </div>
      )}

      <div className="flex-1 min-w-0">
        <p className="text-sm truncate" title={att.name}>
          {att.name}
        </p>
        <div className="mt-0.5 flex items-center gap-1.5 text-[11px] text-[var(--muted)] min-w-0">
          <span className="shrink-0">{formatBytes(att.size_bytes)}</span>
          <span className="shrink-0">·</span>
          <span className="shrink-0">{formatRelative(att.created_at)}</span>
          {att.uploader && (
            <>
              <span className="shrink-0">·</span>
              <Avatar user={att.uploader} size="xs" />
              <span className="truncate">
                {att.uploader.name ?? att.uploader.email}
              </span>
            </>
          )}
        </div>
      </div>

      <div className="flex items-center gap-1 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
        <button
          onClick={onDownload}
          title="Télécharger"
          className="p-1.5 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)] text-[var(--muted)]"
        >
          <Download size={14} />
        </button>
        <button
          onClick={onDelete}
          title="Supprimer"
          className="p-1.5 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)] text-[var(--muted)] hover:text-[var(--color-danger)]"
        >
          <Trash2 size={14} />
        </button>
      </div>
    </li>
  );
}

function formatBytes(n: number | null | undefined): string {
  if (n === null || n === undefined) return "—";
  if (n < 1024) return `${n} o`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} Ko`;
  if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} Mo`;
  return `${(n / (1024 * 1024 * 1024)).toFixed(2)} Go`;
}

function formatRelative(iso: string): string {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "";
  const diffMs = Date.now() - then;
  const sec = Math.max(0, Math.floor(diffMs / 1000));
  if (sec < 60) return "à l'instant";
  const min = Math.floor(sec / 60);
  if (min < 60) return `il y a ${min} min`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `il y a ${hr} h`;
  const day = Math.floor(hr / 24);
  if (day < 30) return `il y a ${day} j`;
  const mo = Math.floor(day / 30);
  if (mo < 12) return `il y a ${mo} mois`;
  const yr = Math.floor(day / 365);
  return `il y a ${yr} an${yr > 1 ? "s" : ""}`;
}

function iconFor(mime: string | null, name: string): LucideIcon {
  const m = (mime ?? "").toLowerCase();
  const ext = name.split(".").pop()?.toLowerCase() ?? "";
  if (m.startsWith("image/")) return FileImage;
  if (m === "application/pdf" || ext === "pdf") return FileText;
  if (
    m.includes("spreadsheet") ||
    ["csv", "xls", "xlsx", "ods"].includes(ext)
  )
    return FileSpreadsheet;
  if (
    m.includes("zip") ||
    m.includes("x-tar") ||
    ["zip", "tar", "gz", "7z", "rar"].includes(ext)
  )
    return FileArchive;
  if (
    m.includes("json") ||
    m.includes("xml") ||
    m.startsWith("text/") ||
    [
      "js",
      "ts",
      "tsx",
      "jsx",
      "php",
      "py",
      "go",
      "rs",
      "sql",
      "html",
      "css",
      "md",
      "yml",
      "yaml",
    ].includes(ext)
  )
    return FileCode;
  return FileIcon;
}
