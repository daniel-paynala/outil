"use client";

import { useState, useCallback, useRef } from "react";
import {
  Upload,
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
import type { ProjectFile } from "@/lib/types";

export default function DocumentsClient({
  projectId,
  initialFiles,
}: {
  projectId: string;
  initialFiles: ProjectFile[];
}) {
  const [files, setFiles] = useState<ProjectFile[]>(initialFiles);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [dragActive, setDragActive] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const upload = useCallback(
    async (list: FileList | File[]) => {
      setError(null);
      const arr = Array.from(list);
      if (arr.length === 0) return;

      setUploading(true);
      try {
        for (const f of arr) {
          const form = new FormData();
          form.append("file", f);
          const res = await apiFetch(`/api/projects/${projectId}/files`, {
            method: "POST",
            body: form,
          });
          if (!res.ok) {
            const txt = await res.text();
            throw new Error(`${f.name} : ${txt || res.status}`);
          }
          const created = (await res.json()) as ProjectFile;
          setFiles((prev) => [created, ...prev]);
        }
      } catch (e) {
        setError(e instanceof Error ? e.message : String(e));
      } finally {
        setUploading(false);
      }
    },
    [projectId],
  );

  async function handleDownload(file: ProjectFile) {
    const res = await apiFetch(`/api/files/${file.id}`);
    if (!res.ok) return;
    const { url } = (await res.json()) as { url: string };
    window.open(url, "_blank");
  }

  async function handleDelete(file: ProjectFile) {
    if (!confirm(`Supprimer "${file.name}" ?`)) return;
    const res = await apiFetch(`/api/files/${file.id}`, { method: "DELETE" });
    if (res.ok) setFiles((prev) => prev.filter((f) => f.id !== file.id));
  }

  return (
    <div className="space-y-6">
      <div
        onDragEnter={(e) => {
          e.preventDefault();
          setDragActive(true);
        }}
        onDragLeave={(e) => {
          e.preventDefault();
          setDragActive(false);
        }}
        onDragOver={(e) => e.preventDefault()}
        onDrop={(e) => {
          e.preventDefault();
          setDragActive(false);
          upload(e.dataTransfer.files);
        }}
        className={clsx(
          "rounded-lg border-2 border-dashed transition-colors p-8 text-center",
          dragActive
            ? "border-[var(--color-brand-red)] bg-[var(--color-brand-red)]/5"
            : "border-[var(--border)] hover:border-[var(--color-neutral-400)]",
        )}
      >
        <input
          ref={inputRef}
          type="file"
          multiple
          className="hidden"
          onChange={(e) => e.target.files && upload(e.target.files)}
        />
        <Upload
          size={28}
          strokeWidth={1.5}
          className="mx-auto text-[var(--muted)]"
        />
        <p className="mt-3 text-sm">
          <button
            onClick={() => inputRef.current?.click()}
            className="text-[var(--color-brand-red)] font-medium hover:underline"
          >
            Clique pour choisir
          </button>{" "}
          ou glisse-dépose tes fichiers ici
        </p>
        <p className="mt-1 text-xs text-[var(--muted)]">
          Jusqu'à 50&nbsp;Mo par fichier · stockage Supabase
        </p>

        {uploading && (
          <div className="mt-4 inline-flex items-center gap-2 text-xs text-[var(--muted)]">
            <Loader2 size={13} className="animate-spin" />
            Envoi en cours…
          </div>
        )}
      </div>

      {error && (
        <p className="text-sm text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded-md px-3 py-2">
          {error}
        </p>
      )}

      {files.length === 0 ? (
        <p className="text-sm text-[var(--muted)] text-center py-10">
          Aucun fichier pour l'instant.
        </p>
      ) : (
        <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
          {files.map((f) => {
            const Icon = iconFor(f.mime_type, f.name);
            return (
              <li
                key={f.id}
                className="flex items-center gap-3 px-4 py-3 group"
              >
                <Icon
                  size={20}
                  strokeWidth={1.5}
                  className="text-[var(--muted)] shrink-0"
                />
                <div className="flex-1 min-w-0">
                  <p className="text-sm truncate">{f.name}</p>
                  <p className="text-xs text-[var(--muted)] truncate mt-0.5">
                    {formatBytes(f.size_bytes)} ·{" "}
                    {new Date(f.created_at).toLocaleDateString("fr-FR", {
                      day: "numeric",
                      month: "short",
                      year: "numeric",
                    })}
                    {f.uploader && <> · {f.uploader.email}</>}
                  </p>
                </div>
                <div className="flex items-center gap-1 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                  <button
                    onClick={() => handleDownload(f)}
                    title="Télécharger"
                    className="p-2 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)] text-[var(--muted)]"
                  >
                    <Download size={14} />
                  </button>
                  <button
                    onClick={() => handleDelete(f)}
                    title="Supprimer"
                    className="p-2 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)] text-[var(--muted)] hover:text-[var(--color-danger)]"
                  >
                    <Trash2 size={14} />
                  </button>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function formatBytes(n: number): string {
  if (n < 1024) return `${n} o`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} Ko`;
  if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} Mo`;
  return `${(n / (1024 * 1024 * 1024)).toFixed(2)} Go`;
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
    ["js", "ts", "tsx", "jsx", "php", "py", "go", "rs", "sql", "html", "css", "md", "yml", "yaml"].includes(ext)
  )
    return FileCode;
  return FileIcon;
}
