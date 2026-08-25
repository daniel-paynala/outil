"use client";

import { useCallback, useMemo, useRef, useState } from "react";
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
  Folder,
  FolderPlus,
  FolderUp,
  ChevronRight,
  Home,
  MoreHorizontal,
  Pencil,
  Move,
  Plus,
  X,
  type LucideIcon,
} from "lucide-react";
import { clsx } from "clsx";
import { apiFetch } from "@/lib/api/client";
import { useToast } from "@/core/toast/toast-context";
import Avatar from "@/core/ui/avatar";
import type { ProjectFile, ProjectFolder } from "@/lib/types";

type DragPayload =
  | { kind: "file"; id: string }
  | { kind: "folder"; id: string };

export default function DocumentsClient({
  projectId,
  initialFiles,
  initialFolders,
}: {
  projectId: string;
  initialFiles: ProjectFile[];
  initialFolders: ProjectFolder[];
}) {
  const toast = useToast();

  const [files, setFiles] = useState<ProjectFile[]>(initialFiles);
  const [folders, setFolders] = useState<ProjectFolder[]>(initialFolders);
  const [currentFolderId, setCurrentFolderId] = useState<string | null>(null);

  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [zoneDragActive, setZoneDragActive] = useState(false);
  const [dragOverFolderId, setDragOverFolderId] = useState<string | null>(null);
  const [openMenuId, setOpenMenuId] = useState<string | null>(null);

  const [picker, setPicker] = useState<
    | { kind: "file"; id: string; name: string }
    | { kind: "folder"; id: string; name: string }
    | null
  >(null);

  const inputRef = useRef<HTMLInputElement>(null);
  const folderInputRef = useRef<HTMLInputElement>(null);
  const dragPayloadRef = useRef<DragPayload | null>(null);

  // ── Folder import state ──────────────────────────────────────────────────
  const [importing, setImporting] = useState<{
    total: number;
    done: number;
    foldersCreated: number;
  } | null>(null);

  // ── Lookups ──────────────────────────────────────────────────────────────

  const foldersById = useMemo(() => {
    const m = new Map<string, ProjectFolder>();
    for (const f of folders) m.set(f.id, f);
    return m;
  }, [folders]);

  const childrenByParent = useMemo(() => {
    const m = new Map<string | null, ProjectFolder[]>();
    for (const f of folders) {
      const list = m.get(f.parent_id) ?? [];
      list.push(f);
      m.set(f.parent_id, list);
    }
    for (const list of m.values()) list.sort((a, b) => a.name.localeCompare(b.name));
    return m;
  }, [folders]);

  const filesByFolder = useMemo(() => {
    const m = new Map<string | null, ProjectFile[]>();
    for (const f of files) {
      const k = f.folder_id ?? null;
      const list = m.get(k) ?? [];
      list.push(f);
      m.set(k, list);
    }
    return m;
  }, [files]);

  const visibleFolders = childrenByParent.get(currentFolderId) ?? [];
  const visibleFiles = filesByFolder.get(currentFolderId) ?? [];

  const breadcrumb = useMemo(() => {
    const trail: ProjectFolder[] = [];
    let cur = currentFolderId;
    while (cur) {
      const f = foldersById.get(cur);
      if (!f) break;
      trail.unshift(f);
      cur = f.parent_id;
    }
    return trail;
  }, [currentFolderId, foldersById]);

  // ── Counts (live, ignoring server hints) ─────────────────────────────────

  const folderFileCount = useCallback(
    (folderId: string) => (filesByFolder.get(folderId) ?? []).length,
    [filesByFolder],
  );
  const folderChildrenCount = useCallback(
    (folderId: string) => (childrenByParent.get(folderId) ?? []).length,
    [childrenByParent],
  );

  // ── Upload ───────────────────────────────────────────────────────────────

  const upload = useCallback(
    async (list: FileList | File[], targetFolderId: string | null) => {
      setError(null);
      const arr = Array.from(list);
      if (arr.length === 0) return;

      setUploading(true);
      try {
        for (const f of arr) {
          const form = new FormData();
          form.append("file", f);
          if (targetFolderId) form.append("folder_id", targetFolderId);
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
          toast.success("Fichier importé", created.name);
        }
      } catch (e) {
        const msg = e instanceof Error ? e.message : String(e);
        setError(msg);
        toast.error("Import impossible", msg);
      } finally {
        setUploading(false);
      }
    },
    [projectId, toast],
  );

  // ── Folder import ────────────────────────────────────────────────────────

  const importFolder = useCallback(
    async (entries: { file: File; relativePath: string }[]) => {
      // Filter hidden files / hidden folders (any segment starting with ".")
      const cleaned = entries.filter(({ relativePath }) => {
        const segs = relativePath.split("/").filter(Boolean);
        return segs.length > 0 && !segs.some((s) => s.startsWith("."));
      });

      if (cleaned.length === 0) {
        toast.info("Rien à importer", "Aucun fichier valide trouvé.");
        return;
      }

      // Collect all unique folder paths to create
      const folderPaths = new Set<string>();
      for (const { relativePath } of cleaned) {
        const segs = relativePath.split("/").filter(Boolean);
        // last segment is the file name
        for (let i = 1; i < segs.length; i++) {
          folderPaths.add(segs.slice(0, i).join("/"));
        }
      }

      // Confirm if very large
      if (cleaned.length > 500) {
        const ok = confirm(
          `Importer ${cleaned.length} fichiers et ${folderPaths.size} dossiers ?`,
        );
        if (!ok) return;
      }

      setImporting({
        total: cleaned.length,
        done: 0,
        foldersCreated: 0,
      });

      let foldersCreatedCount = 0;

      // path -> folder id (relative to currentFolderId). Empty path = currentFolderId.
      const pathToFolderId = new Map<string, string | null>();
      pathToFolderId.set("", currentFolderId);

      // Build initial path map from already-existing folders under currentFolderId
      // We resolve segments lazily (only for paths we encounter), descending the tree.
      const resolveFolderId = async (segments: string[]): Promise<string> => {
        let parentId: string | null = currentFolderId;
        let acc = "";
        for (const seg of segments) {
          acc = acc ? `${acc}/${seg}` : seg;
          const cached = pathToFolderId.get(acc);
          if (cached !== undefined && cached !== null) {
            parentId = cached;
            continue;
          }
          // Look in current state for an existing folder with this name+parent
          const existing = findFolderByNameAndParent(folders, seg, parentId);
          if (existing) {
            pathToFolderId.set(acc, existing.id);
            parentId = existing.id;
            continue;
          }
          // Create the folder via API
          const res = await apiFetch(`/api/projects/${projectId}/folders`, {
            method: "POST",
            body: JSON.stringify({ name: seg, parent_id: parentId }),
          });
          if (!res.ok) {
            const txt = await res.text();
            throw new Error(`Création du dossier "${seg}" : ${txt || res.status}`);
          }
          const created = (await res.json()) as ProjectFolder;
          // Update state and lookups
          folders.push(created); // mutate local snapshot so next iterations see it
          setFolders((prev) => [...prev, created]);
          foldersCreatedCount += 1;
          setImporting((cur) =>
            cur ? { ...cur, foldersCreated: foldersCreatedCount } : cur,
          );
          pathToFolderId.set(acc, created.id);
          parentId = created.id;
        }
        return parentId as string;
      };

      // Group files by parent path so we create each folder exactly once.
      const byPath = new Map<string, { file: File; relativePath: string }[]>();
      for (const entry of cleaned) {
        const segs = entry.relativePath.split("/").filter(Boolean);
        const parentPath = segs.slice(0, -1).join("/");
        const list = byPath.get(parentPath) ?? [];
        list.push(entry);
        byPath.set(parentPath, list);
      }

      // Sort paths shortest-first so parents are created before children.
      const sortedPaths = Array.from(byPath.keys()).sort(
        (a, b) => a.split("/").length - b.split("/").length || a.localeCompare(b),
      );

      const failures: { name: string; error: string }[] = [];

      // Resolve all needed folder ids (sequential per path so parents exist first)
      for (const path of sortedPaths) {
        const segs = path ? path.split("/") : [];
        try {
          const targetId =
            segs.length === 0
              ? (currentFolderId as string | null)
              : await resolveFolderId(segs);
          pathToFolderId.set(path, targetId);
        } catch (e) {
          const msg = e instanceof Error ? e.message : String(e);
          // Mark all files under this path as failed
          for (const entry of byPath.get(path) ?? []) {
            failures.push({ name: entry.relativePath, error: msg });
          }
          byPath.delete(path);
        }
      }

      // Upload files in batches of 5 in parallel.
      const CONCURRENCY = 5;
      const queue: { file: File; relativePath: string; folderId: string | null }[] =
        [];
      for (const [path, items] of byPath) {
        const folderId = pathToFolderId.get(path) ?? null;
        for (const it of items) {
          queue.push({ ...it, folderId });
        }
      }

      const uploadOne = async (job: {
        file: File;
        relativePath: string;
        folderId: string | null;
      }) => {
        try {
          const form = new FormData();
          form.append("file", job.file);
          if (job.folderId) form.append("folder_id", job.folderId);
          const res = await apiFetch(`/api/projects/${projectId}/files`, {
            method: "POST",
            body: form,
          });
          if (!res.ok) {
            const txt = await res.text();
            throw new Error(txt || `Erreur ${res.status}`);
          }
          const created = (await res.json()) as ProjectFile;
          setFiles((prev) => [created, ...prev]);
        } catch (e) {
          failures.push({
            name: job.relativePath,
            error: e instanceof Error ? e.message : String(e),
          });
        } finally {
          setImporting((cur) => (cur ? { ...cur, done: cur.done + 1 } : cur));
        }
      };

      // Run with a sliding window
      let cursor = 0;
      const runners: Promise<void>[] = [];
      const launch = async () => {
        while (cursor < queue.length) {
          const idx = cursor++;
          await uploadOne(queue[idx]);
        }
      };
      for (let i = 0; i < Math.min(CONCURRENCY, queue.length); i++) {
        runners.push(launch());
      }
      await Promise.all(runners);

      const successCount = cleaned.length - failures.length;
      setImporting(null);

      if (failures.length === 0) {
        toast.success(
          "Dossier importé",
          `${successCount} fichier${successCount > 1 ? "s" : ""}, ${foldersCreatedCount} dossier${foldersCreatedCount > 1 ? "s" : ""} créé${foldersCreatedCount > 1 ? "s" : ""}`,
        );
      } else if (failures.length < cleaned.length) {
        toast.error(
          "Import partiel",
          `${successCount}/${cleaned.length} fichiers importés. ${failures.length} échec${failures.length > 1 ? "s" : ""} : ${failures
            .slice(0, 3)
            .map((f) => f.name)
            .join(", ")}${failures.length > 3 ? "…" : ""}`,
        );
      } else {
        toast.error(
          "Import impossible",
          `Aucun fichier importé (${failures.length} échec${failures.length > 1 ? "s" : ""}).`,
        );
      }
    },
    [projectId, currentFolderId, folders, toast],
  );

  // ── File actions ─────────────────────────────────────────────────────────

  async function handleDownload(file: ProjectFile) {
    const res = await apiFetch(`/api/files/${file.id}`);
    if (!res.ok) {
      toast.error(
        "Téléchargement impossible",
        (await res.text()) || `Erreur ${res.status}`,
      );
      return;
    }
    const { url } = (await res.json()) as { url: string };
    window.open(url, "_blank");
  }

  async function handleDeleteFile(file: ProjectFile) {
    if (!confirm(`Supprimer "${file.name}" ?`)) return;
    try {
      const res = await apiFetch(`/api/files/${file.id}`, { method: "DELETE" });
      if (res.ok) {
        setFiles((prev) => prev.filter((f) => f.id !== file.id));
        toast.success("Supprimé", file.name);
      } else {
        toast.error(
          "Suppression impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
      }
    } catch (err) {
      toast.error(
        "Suppression impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  async function moveFile(fileId: string, targetFolderId: string | null) {
    const file = files.find((f) => f.id === fileId);
    if (!file) return;
    if ((file.folder_id ?? null) === targetFolderId) return;
    const previous = file.folder_id ?? null;
    setFiles((prev) =>
      prev.map((f) => (f.id === fileId ? { ...f, folder_id: targetFolderId } : f)),
    );
    try {
      const res = await apiFetch(`/api/files/${fileId}`, {
        method: "PATCH",
        body: JSON.stringify({ folder_id: targetFolderId }),
      });
      if (!res.ok) {
        setFiles((prev) =>
          prev.map((f) => (f.id === fileId ? { ...f, folder_id: previous } : f)),
        );
        toast.error(
          "Déplacement impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      const updated = (await res.json()) as ProjectFile;
      setFiles((prev) => prev.map((f) => (f.id === updated.id ? updated : f)));
      const dest = targetFolderId
        ? foldersById.get(targetFolderId)?.name ?? "Dossier"
        : "Racine";
      toast.success("Déplacé", `${file.name} → ${dest}`);
    } catch (err) {
      setFiles((prev) =>
        prev.map((f) => (f.id === fileId ? { ...f, folder_id: previous } : f)),
      );
      toast.error(
        "Déplacement impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  // ── Folder actions ───────────────────────────────────────────────────────

  async function createFolder(parentId: string | null) {
    const name = prompt("Nom du nouveau dossier ?");
    if (!name || !name.trim()) return;
    try {
      const res = await apiFetch(`/api/projects/${projectId}/folders`, {
        method: "POST",
        body: JSON.stringify({ name: name.trim(), parent_id: parentId }),
      });
      if (!res.ok) {
        toast.error(
          "Création impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      const created = (await res.json()) as ProjectFolder;
      setFolders((prev) => [...prev, created]);
      toast.success("Dossier créé", created.name);
    } catch (err) {
      toast.error(
        "Création impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  async function renameFolder(folder: ProjectFolder) {
    setOpenMenuId(null);
    const next = prompt("Nouveau nom :", folder.name);
    if (!next || !next.trim() || next.trim() === folder.name) return;
    try {
      const res = await apiFetch(`/api/folders/${folder.id}`, {
        method: "PATCH",
        body: JSON.stringify({ name: next.trim() }),
      });
      if (!res.ok) {
        toast.error(
          "Renommage impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      const updated = (await res.json()) as ProjectFolder;
      setFolders((prev) => prev.map((f) => (f.id === updated.id ? updated : f)));
      toast.success("Renommé", updated.name);
    } catch (err) {
      toast.error(
        "Renommage impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  async function moveFolder(folderId: string, targetParentId: string | null) {
    const folder = foldersById.get(folderId);
    if (!folder) return;
    if ((folder.parent_id ?? null) === targetParentId) return;
    if (folderId === targetParentId) {
      toast.error("Déplacement impossible", "Un dossier ne peut pas être dans lui-même.");
      return;
    }
    // Empêche de mettre un dossier dans un de ses descendants
    if (targetParentId && isDescendant(folders, targetParentId, folderId)) {
      toast.error(
        "Déplacement impossible",
        "Tu ne peux pas placer un dossier dans un de ses sous-dossiers.",
      );
      return;
    }
    const previous = folder.parent_id;
    setFolders((prev) =>
      prev.map((f) => (f.id === folderId ? { ...f, parent_id: targetParentId } : f)),
    );
    try {
      const res = await apiFetch(`/api/folders/${folderId}`, {
        method: "PATCH",
        body: JSON.stringify({ parent_id: targetParentId }),
      });
      if (!res.ok) {
        setFolders((prev) =>
          prev.map((f) => (f.id === folderId ? { ...f, parent_id: previous } : f)),
        );
        toast.error(
          "Déplacement impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      const updated = (await res.json()) as ProjectFolder;
      setFolders((prev) => prev.map((f) => (f.id === updated.id ? updated : f)));
      const dest = targetParentId
        ? foldersById.get(targetParentId)?.name ?? "Dossier"
        : "Racine";
      toast.success("Déplacé", `${folder.name} → ${dest}`);
    } catch (err) {
      setFolders((prev) =>
        prev.map((f) => (f.id === folderId ? { ...f, parent_id: previous } : f)),
      );
      toast.error(
        "Déplacement impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  async function handleDeleteFolder(folder: ProjectFolder) {
    setOpenMenuId(null);
    if (
      !confirm(
        `Supprimer le dossier "${folder.name}" ?\n\nLes fichiers qu'il contient seront déplacés à la racine, les sous-dossiers seront supprimés.`,
      )
    ) {
      return;
    }
    try {
      const res = await apiFetch(`/api/folders/${folder.id}`, {
        method: "DELETE",
      });
      if (!res.ok) {
        toast.error(
          "Suppression impossible",
          (await res.text()) || `Erreur ${res.status}`,
        );
        return;
      }
      // Reconstruit l'état local : sous-dossiers récursifs supprimés,
      // fichiers du dossier (et descendants) remontés à la racine.
      const toRemove = collectDescendants(folders, folder.id);
      toRemove.add(folder.id);
      setFolders((prev) => prev.filter((f) => !toRemove.has(f.id)));
      setFiles((prev) =>
        prev.map((f) =>
          f.folder_id && toRemove.has(f.folder_id) ? { ...f, folder_id: null } : f,
        ),
      );
      if (currentFolderId && toRemove.has(currentFolderId)) {
        setCurrentFolderId(folder.parent_id);
      }
      toast.success("Dossier supprimé", folder.name);
    } catch (err) {
      toast.error(
        "Suppression impossible",
        err instanceof Error ? err.message : String(err),
      );
    }
  }

  // ── Drag and drop ────────────────────────────────────────────────────────

  function startDragFile(e: React.DragEvent, file: ProjectFile) {
    dragPayloadRef.current = { kind: "file", id: file.id };
    e.dataTransfer.effectAllowed = "move";
    e.dataTransfer.setData("text/x-arche-file", file.id);
  }
  function startDragFolder(e: React.DragEvent, folder: ProjectFolder) {
    dragPayloadRef.current = { kind: "folder", id: folder.id };
    e.dataTransfer.effectAllowed = "move";
    e.dataTransfer.setData("text/x-arche-folder", folder.id);
  }
  function endDrag() {
    dragPayloadRef.current = null;
    setDragOverFolderId(null);
  }

  function isInternalDrag(e: React.DragEvent): boolean {
    const t = e.dataTransfer.types;
    return (
      t.includes("text/x-arche-file") ||
      t.includes("text/x-arche-folder") ||
      dragPayloadRef.current !== null
    );
  }

  function onFolderDragOver(e: React.DragEvent, folderId: string) {
    if (!isInternalDrag(e)) return;
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = "move";
    setDragOverFolderId(folderId);
  }

  function onFolderDrop(e: React.DragEvent, folderId: string) {
    if (!isInternalDrag(e)) return;
    e.preventDefault();
    e.stopPropagation();
    setDragOverFolderId(null);
    const payload = dragPayloadRef.current;
    if (!payload) return;
    if (payload.kind === "file") {
      void moveFile(payload.id, folderId);
    } else if (payload.kind === "folder") {
      void moveFolder(payload.id, folderId);
    }
    dragPayloadRef.current = null;
  }

  function onBreadcrumbDrop(e: React.DragEvent, target: string | null) {
    if (!isInternalDrag(e)) return;
    e.preventDefault();
    const payload = dragPayloadRef.current;
    if (!payload) return;
    if (payload.kind === "file") void moveFile(payload.id, target);
    else if (payload.kind === "folder") void moveFolder(payload.id, target);
    dragPayloadRef.current = null;
  }

  // ── Render ───────────────────────────────────────────────────────────────

  return (
    <div className="space-y-4" onClick={() => setOpenMenuId(null)}>
      {/* Header : breadcrumb + actions */}
      <div className="flex flex-wrap items-center gap-3">
        <nav className="flex items-center gap-1 text-sm flex-1 min-w-0">
          <BreadcrumbItem
            label={<Home size={14} />}
            ariaLabel="Racine"
            onClick={() => setCurrentFolderId(null)}
            onDragOver={(e) => {
              if (isInternalDrag(e)) e.preventDefault();
            }}
            onDrop={(e) => onBreadcrumbDrop(e, null)}
            current={currentFolderId === null}
          />
          {breadcrumb.map((f) => (
            <span key={f.id} className="flex items-center gap-1 min-w-0">
              <ChevronRight
                size={13}
                className="text-[var(--muted)] shrink-0"
              />
              <BreadcrumbItem
                label={f.name}
                onClick={() => setCurrentFolderId(f.id)}
                onDragOver={(e) => {
                  if (isInternalDrag(e)) e.preventDefault();
                }}
                onDrop={(e) => onBreadcrumbDrop(e, f.id)}
                current={currentFolderId === f.id}
              />
            </span>
          ))}
        </nav>

        <div className="flex items-center gap-2 shrink-0">
          <button
            onClick={(e) => {
              e.stopPropagation();
              void createFolder(currentFolderId);
            }}
            className="inline-flex items-center gap-1.5 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
          >
            <FolderPlus size={14} />
            Dossier
          </button>
          <button
            onClick={(e) => {
              e.stopPropagation();
              folderInputRef.current?.click();
            }}
            disabled={importing !== null}
            className="inline-flex items-center gap-1.5 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)] disabled:opacity-50 disabled:cursor-not-allowed"
            title="Importer un dossier complet depuis le disque"
          >
            <FolderUp size={14} />
            Importer un dossier
          </button>
          <button
            onClick={(e) => {
              e.stopPropagation();
              inputRef.current?.click();
            }}
            className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-3 py-1.5 text-xs font-medium"
          >
            <Plus size={14} />
            Fichier
          </button>
          <input
            ref={inputRef}
            type="file"
            multiple
            className="hidden"
            onChange={(e) => {
              if (e.target.files) {
                void upload(e.target.files, currentFolderId);
                e.target.value = "";
              }
            }}
          />
          <input
            ref={folderInputRef}
            type="file"
            multiple
            className="hidden"
            // webkitdirectory n'est pas dans les types React standards
            {...({ webkitdirectory: "", directory: "" } as Record<string, string>)}
            onChange={(e) => {
              const list = e.target.files;
              if (list && list.length > 0) {
                const entries = Array.from(list).map((f) => ({
                  file: f,
                  relativePath:
                    (f as File & { webkitRelativePath?: string })
                      .webkitRelativePath || f.name,
                }));
                void importFolder(entries);
              }
              e.target.value = "";
            }}
          />
        </div>
      </div>

      {/* Import progress banner */}
      {importing && (
        <div className="rounded-lg border border-[var(--color-brand-red)]/30 bg-[var(--color-brand-red)]/5 px-4 py-3">
          <div className="flex items-center justify-between gap-3 mb-2">
            <div className="flex items-center gap-2 text-sm">
              <Loader2
                size={14}
                className="animate-spin text-[var(--color-brand-red)]"
              />
              <span className="font-medium">
                Import en cours : {importing.done} / {importing.total} fichier
                {importing.total > 1 ? "s" : ""}
              </span>
              {importing.foldersCreated > 0 && (
                <span className="text-xs text-[var(--muted)]">
                  · {importing.foldersCreated} dossier
                  {importing.foldersCreated > 1 ? "s" : ""} créé
                  {importing.foldersCreated > 1 ? "s" : ""}
                </span>
              )}
            </div>
            <span className="text-xs text-[var(--muted)] tabular-nums">
              {importing.total > 0
                ? Math.round((importing.done / importing.total) * 100)
                : 0}
              %
            </span>
          </div>
          <div className="h-1.5 w-full rounded-full bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-800)] overflow-hidden">
            <div
              className="h-full bg-[var(--color-brand-red)] transition-all duration-200"
              style={{
                width: `${importing.total > 0 ? (importing.done / importing.total) * 100 : 0}%`,
              }}
            />
          </div>
        </div>
      )}

      {/* Drop zone OS uploads */}
      <div
        onDragEnter={(e) => {
          if (isInternalDrag(e)) return;
          e.preventDefault();
          setZoneDragActive(true);
        }}
        onDragLeave={(e) => {
          if (isInternalDrag(e)) return;
          e.preventDefault();
          setZoneDragActive(false);
        }}
        onDragOver={(e) => {
          if (!isInternalDrag(e)) e.preventDefault();
        }}
        onDrop={(e) => {
          if (isInternalDrag(e)) return;
          e.preventDefault();
          setZoneDragActive(false);

          // Detect directory entries via DataTransferItem.webkitGetAsEntry()
          const items = e.dataTransfer.items;
          const entries: FileSystemEntryLike[] = [];
          let hasDirectory = false;
          if (items && items.length > 0) {
            for (let i = 0; i < items.length; i++) {
              const item = items[i] as DataTransferItem & {
                webkitGetAsEntry?: () => FileSystemEntryLike | null;
              };
              const entry = item.webkitGetAsEntry?.() ?? null;
              if (entry) {
                entries.push(entry);
                if (entry.isDirectory) hasDirectory = true;
              }
            }
          }

          if (hasDirectory) {
            // Walk all entries (files + directories) and import as a folder tree
            void (async () => {
              const collected: { file: File; relativePath: string }[] = [];
              for (const entry of entries) {
                try {
                  const items = await traverseEntry(entry, "");
                  collected.push(...items);
                } catch (err) {
                  toast.error(
                    "Lecture du dossier impossible",
                    err instanceof Error ? err.message : String(err),
                  );
                }
              }
              if (collected.length > 0) {
                await importFolder(collected);
              }
            })();
            return;
          }

          if (e.dataTransfer.files.length > 0) {
            void upload(e.dataTransfer.files, currentFolderId);
          }
        }}
        className={clsx(
          "rounded-lg border-2 border-dashed transition-colors px-6 py-5 text-center",
          zoneDragActive
            ? "border-[var(--color-brand-red)] bg-[var(--color-brand-red)]/5"
            : "border-[var(--border)] hover:border-[var(--color-neutral-400)]",
        )}
      >
        <div className="flex items-center justify-center gap-2 text-sm">
          <Upload
            size={16}
            strokeWidth={1.5}
            className="text-[var(--muted)]"
          />
          <span>
            <button
              onClick={(e) => {
                e.stopPropagation();
                inputRef.current?.click();
              }}
              className="text-[var(--color-brand-red)] font-medium hover:underline"
            >
              Clique pour choisir
            </button>{" "}
            ou glisse-dépose dans{" "}
            <strong className="font-medium">
              {currentFolderId
                ? foldersById.get(currentFolderId)?.name ?? "ce dossier"
                : "la racine"}
            </strong>
          </span>
          {uploading && (
            <span className="inline-flex items-center gap-1.5 text-xs text-[var(--muted)] ml-2">
              <Loader2 size={12} className="animate-spin" />
              Envoi…
            </span>
          )}
        </div>
      </div>

      {error && (
        <p className="text-sm text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded-md px-3 py-2">
          {error}
        </p>
      )}

      {/* Section Dossiers */}
      <section>
        <h3 className="text-[11px] uppercase tracking-wider font-semibold text-[var(--muted)] mb-2 px-1">
          Dossiers ({visibleFolders.length})
        </h3>
        {visibleFolders.length === 0 ? (
          <p className="text-xs text-[var(--muted)] italic px-1 py-2">
            Aucun sous-dossier ici.
          </p>
        ) : (
          <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
            {visibleFolders.map((folder) => {
              const fc = folderFileCount(folder.id);
              const cc = folderChildrenCount(folder.id);
              const isOver = dragOverFolderId === folder.id;
              return (
                <li
                  key={folder.id}
                  draggable
                  onDragStart={(e) => startDragFolder(e, folder)}
                  onDragEnd={endDrag}
                  onDragEnter={(e) => onFolderDragOver(e, folder.id)}
                  onDragOver={(e) => onFolderDragOver(e, folder.id)}
                  onDragLeave={(e) => {
                    e.stopPropagation();
                    setDragOverFolderId((cur) => (cur === folder.id ? null : cur));
                  }}
                  onDrop={(e) => onFolderDrop(e, folder.id)}
                  onDoubleClick={() => setCurrentFolderId(folder.id)}
                  className={clsx(
                    "flex items-center gap-3 px-4 py-3 group cursor-pointer transition-colors",
                    isOver
                      ? "bg-[var(--color-brand-red)]/10 outline outline-1 outline-[var(--color-brand-red)]/40"
                      : "hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]/40",
                  )}
                  onClick={() => setCurrentFolderId(folder.id)}
                >
                  <Folder
                    size={20}
                    strokeWidth={1.5}
                    className="text-[var(--color-brand-red)] shrink-0"
                    fill="currentColor"
                    fillOpacity={0.12}
                  />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium truncate">{folder.name}</p>
                    <p className="text-xs text-[var(--muted)] mt-0.5 truncate">
                      {summarizeFolderContent(fc, cc)}
                    </p>
                  </div>

                  <div
                    className="relative shrink-0"
                    onClick={(e) => e.stopPropagation()}
                  >
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        setOpenMenuId((cur) =>
                          cur === folder.id ? null : folder.id,
                        );
                      }}
                      title="Actions"
                      aria-label="Menu dossier"
                      className="p-2 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)] text-[var(--muted)] opacity-60 group-hover:opacity-100 transition-opacity"
                    >
                      <MoreHorizontal size={14} />
                    </button>
                    {openMenuId === folder.id && (
                      <div className="absolute right-0 top-9 z-20 w-48 rounded-md border border-[var(--border)] bg-[var(--background)] shadow-md p-1">
                        <MenuItem
                          icon={<Pencil size={12} />}
                          label="Renommer"
                          onClick={() => void renameFolder(folder)}
                        />
                        <MenuItem
                          icon={<Move size={12} />}
                          label="Déplacer…"
                          onClick={() => {
                            setOpenMenuId(null);
                            setPicker({
                              kind: "folder",
                              id: folder.id,
                              name: folder.name,
                            });
                          }}
                        />
                        <MenuItem
                          icon={<Trash2 size={12} />}
                          label="Supprimer"
                          onClick={() => void handleDeleteFolder(folder)}
                          danger
                        />
                      </div>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </section>

      {/* Section Fichiers */}
      <section>
        <h3 className="text-[11px] uppercase tracking-wider font-semibold text-[var(--muted)] mb-2 px-1">
          Fichiers ({visibleFiles.length})
        </h3>
        {visibleFiles.length === 0 && visibleFolders.length === 0 ? (
          <div className="rounded-lg border border-dashed border-[var(--border)] bg-[var(--surface)] py-10 text-center">
            <p className="text-sm text-[var(--muted)]">
              Ce dossier est vide.
            </p>
            <p className="text-xs text-[var(--muted)] mt-1">
              Glisse-dépose un fichier ou crée un sous-dossier.
            </p>
          </div>
        ) : visibleFiles.length === 0 ? (
          <p className="text-xs text-[var(--muted)] italic px-1 py-2">
            Aucun fichier directement dans ce dossier.
          </p>
        ) : (
          <ul className="rounded-lg border border-[var(--border)] bg-[var(--surface)] divide-y divide-[var(--border)]">
            {visibleFiles.map((f) => {
              const Icon = iconFor(f.mime_type, f.name);
              return (
                <li
                  key={f.id}
                  draggable
                  onDragStart={(e) => startDragFile(e, f)}
                  onDragEnd={endDrag}
                  className="flex items-center gap-3 px-4 py-3 group cursor-grab active:cursor-grabbing"
                >
                  <Icon
                    size={20}
                    strokeWidth={1.5}
                    className="text-[var(--muted)] shrink-0"
                  />
                  <div className="flex-1 min-w-0">
                    <p className="text-sm truncate">{f.name}</p>
                    <p className="text-xs text-[var(--muted)] truncate mt-0.5 flex items-center gap-1.5">
                      {f.uploader && (
                        <>
                          <Avatar user={f.uploader} size="xs" />
                          <span className="truncate">
                            {f.uploader.name ?? f.uploader.email}
                          </span>
                          <span aria-hidden>·</span>
                        </>
                      )}
                      <span>{relativeDate(f.created_at)}</span>
                      <span aria-hidden>·</span>
                      <span>{formatBytes(f.size_bytes)}</span>
                    </p>
                  </div>
                  <div className="flex items-center gap-1 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button
                      onClick={() =>
                        setPicker({ kind: "file", id: f.id, name: f.name })
                      }
                      title="Déplacer"
                      className="p-2 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)] text-[var(--muted)]"
                    >
                      <Move size={14} />
                    </button>
                    <button
                      onClick={() => handleDownload(f)}
                      title="Télécharger"
                      className="p-2 rounded hover:bg-[var(--color-neutral-200)] dark:hover:bg-[var(--color-neutral-700)] text-[var(--muted)]"
                    >
                      <Download size={14} />
                    </button>
                    <button
                      onClick={() => handleDeleteFile(f)}
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
      </section>

      {/* Folder picker modal */}
      {picker && (
        <FolderPickerModal
          title={
            picker.kind === "file"
              ? `Déplacer "${picker.name}"`
              : `Déplacer le dossier "${picker.name}"`
          }
          folders={folders}
          excludeFolderId={picker.kind === "folder" ? picker.id : null}
          currentParent={
            picker.kind === "file"
              ? files.find((f) => f.id === picker.id)?.folder_id ?? null
              : foldersById.get(picker.id)?.parent_id ?? null
          }
          onCancel={() => setPicker(null)}
          onSelect={(targetId) => {
            const p = picker;
            setPicker(null);
            if (!p) return;
            if (p.kind === "file") void moveFile(p.id, targetId);
            else void moveFolder(p.id, targetId);
          }}
        />
      )}
    </div>
  );
}

// ── Sub-components ─────────────────────────────────────────────────────────

function BreadcrumbItem({
  label,
  ariaLabel,
  onClick,
  onDragOver,
  onDrop,
  current,
}: {
  label: React.ReactNode;
  ariaLabel?: string;
  onClick: () => void;
  onDragOver: (e: React.DragEvent) => void;
  onDrop: (e: React.DragEvent) => void;
  current?: boolean;
}) {
  return (
    <button
      onClick={onClick}
      onDragOver={onDragOver}
      onDrop={onDrop}
      aria-label={ariaLabel}
      className={clsx(
        "inline-flex items-center gap-1 rounded px-1.5 py-1 max-w-[12rem] truncate",
        current
          ? "text-[var(--foreground)] font-medium"
          : "text-[var(--muted)] hover:text-[var(--foreground)] hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]",
      )}
    >
      <span className="truncate">{label}</span>
    </button>
  );
}

function MenuItem({
  icon,
  label,
  onClick,
  danger,
}: {
  icon: React.ReactNode;
  label: string;
  onClick: () => void;
  danger?: boolean;
}) {
  return (
    <button
      onClick={onClick}
      className={clsx(
        "flex items-center gap-2 w-full text-left px-2 py-1.5 text-xs rounded hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]",
        danger && "text-[var(--color-danger)]",
      )}
    >
      {icon}
      {label}
    </button>
  );
}

function FolderPickerModal({
  title,
  folders,
  excludeFolderId,
  currentParent,
  onCancel,
  onSelect,
}: {
  title: string;
  folders: ProjectFolder[];
  excludeFolderId: string | null;
  currentParent: string | null;
  onCancel: () => void;
  onSelect: (id: string | null) => void;
}) {
  // Construit une liste à plat avec niveaux, en excluant l'arbre du dossier déplacé
  const flat = useMemo(() => {
    const blocked = new Set<string>();
    if (excludeFolderId) {
      const all = collectDescendants(folders, excludeFolderId);
      for (const id of all) blocked.add(id);
      blocked.add(excludeFolderId);
    }
    const out: { folder: ProjectFolder; depth: number }[] = [];
    const childMap = new Map<string | null, ProjectFolder[]>();
    for (const f of folders) {
      const list = childMap.get(f.parent_id) ?? [];
      list.push(f);
      childMap.set(f.parent_id, list);
    }
    for (const list of childMap.values())
      list.sort((a, b) => a.name.localeCompare(b.name));

    function walk(parent: string | null, depth: number) {
      const kids = childMap.get(parent) ?? [];
      for (const k of kids) {
        if (blocked.has(k.id)) continue;
        out.push({ folder: k, depth });
        walk(k.id, depth + 1);
      }
    }
    walk(null, 0);
    return out;
  }, [folders, excludeFolderId]);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
      onClick={onCancel}
    >
      <div
        className="w-full max-w-md rounded-lg border border-[var(--border)] bg-[var(--surface)] shadow-xl flex flex-col max-h-[80vh]"
        onClick={(e) => e.stopPropagation()}
      >
        <header className="flex items-center justify-between px-4 py-3 border-b border-[var(--border)]">
          <h3 className="text-sm font-semibold truncate">{title}</h3>
          <button
            onClick={onCancel}
            aria-label="Fermer"
            className="p-1 rounded hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)] text-[var(--muted)]"
          >
            <X size={14} />
          </button>
        </header>

        <div className="overflow-y-auto p-2 flex-1">
          <FolderPickerRow
            depth={0}
            label={
              <span className="inline-flex items-center gap-2">
                <FolderUp
                  size={16}
                  strokeWidth={1.5}
                  className="text-[var(--muted)]"
                />
                Racine
              </span>
            }
            disabled={currentParent === null}
            onClick={() => onSelect(null)}
          />
          {flat.map(({ folder, depth }) => (
            <FolderPickerRow
              key={folder.id}
              depth={depth + 1}
              label={
                <span className="inline-flex items-center gap-2">
                  <Folder
                    size={16}
                    strokeWidth={1.5}
                    className="text-[var(--color-brand-red)]"
                  />
                  {folder.name}
                </span>
              }
              disabled={currentParent === folder.id}
              onClick={() => onSelect(folder.id)}
            />
          ))}
          {flat.length === 0 && excludeFolderId && (
            <p className="text-xs text-[var(--muted)] italic px-2 py-3">
              Aucun autre dossier disponible.
            </p>
          )}
        </div>

        <footer className="px-4 py-3 border-t border-[var(--border)] flex justify-end">
          <button
            onClick={onCancel}
            className="text-xs text-[var(--muted)] hover:text-[var(--foreground)] px-3 py-1.5"
          >
            Annuler
          </button>
        </footer>
      </div>
    </div>
  );
}

function FolderPickerRow({
  depth,
  label,
  disabled,
  onClick,
}: {
  depth: number;
  label: React.ReactNode;
  disabled?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      style={{ paddingLeft: `${0.5 + depth * 1}rem` }}
      className={clsx(
        "flex items-center w-full text-left px-2 py-1.5 text-sm rounded",
        disabled
          ? "opacity-40 cursor-not-allowed"
          : "hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]",
      )}
    >
      {label}
      {disabled && (
        <span className="ml-auto text-[10px] text-[var(--muted)]">actuel</span>
      )}
    </button>
  );
}

// ── Utils ─────────────────────────────────────────────────────────────────

// Minimal types for the non-standard FileSystem Entry API
type FileSystemEntryLike = {
  name: string;
  isFile: boolean;
  isDirectory: boolean;
  fullPath?: string;
};
type FileSystemFileEntryLike = FileSystemEntryLike & {
  file: (
    onSuccess: (file: File) => void,
    onError?: (e: unknown) => void,
  ) => void;
};
type FileSystemDirectoryEntryLike = FileSystemEntryLike & {
  createReader: () => {
    readEntries: (
      onSuccess: (entries: FileSystemEntryLike[]) => void,
      onError?: (e: unknown) => void,
    ) => void;
  };
};

async function traverseEntry(
  entry: FileSystemEntryLike,
  basePath: string,
): Promise<{ file: File; relativePath: string }[]> {
  const out: { file: File; relativePath: string }[] = [];
  if (entry.isFile) {
    const fileEntry = entry as FileSystemFileEntryLike;
    const file = await new Promise<File>((resolve, reject) => {
      fileEntry.file(resolve, reject);
    });
    const relativePath = basePath ? `${basePath}/${entry.name}` : entry.name;
    out.push({ file, relativePath });
    return out;
  }
  if (entry.isDirectory) {
    const dirEntry = entry as FileSystemDirectoryEntryLike;
    const here = basePath ? `${basePath}/${entry.name}` : entry.name;
    const reader = dirEntry.createReader();
    // readEntries() ne renvoie qu'un batch à la fois, il faut boucler.
    const all: FileSystemEntryLike[] = [];
    while (true) {
      const batch = await new Promise<FileSystemEntryLike[]>((resolve, reject) => {
        reader.readEntries(resolve, reject);
      });
      if (batch.length === 0) break;
      all.push(...batch);
    }
    for (const child of all) {
      const items = await traverseEntry(child, here);
      out.push(...items);
    }
  }
  return out;
}

function findFolderByNameAndParent(
  folders: ProjectFolder[],
  name: string,
  parentId: string | null,
): ProjectFolder | null {
  for (const f of folders) {
    if (f.name === name && (f.parent_id ?? null) === parentId) return f;
  }
  return null;
}

function summarizeFolderContent(files: number, children: number): string {
  if (files === 0 && children === 0) return "vide";
  const parts: string[] = [];
  if (files > 0) parts.push(`${files} fichier${files > 1 ? "s" : ""}`);
  if (children > 0)
    parts.push(`${children} sous-dossier${children > 1 ? "s" : ""}`);
  return parts.join(", ");
}

function collectDescendants(
  folders: ProjectFolder[],
  rootId: string,
): Set<string> {
  const childMap = new Map<string | null, ProjectFolder[]>();
  for (const f of folders) {
    const list = childMap.get(f.parent_id) ?? [];
    list.push(f);
    childMap.set(f.parent_id, list);
  }
  const out = new Set<string>();
  const queue: string[] = [rootId];
  while (queue.length) {
    const cur = queue.shift()!;
    const kids = childMap.get(cur) ?? [];
    for (const k of kids) {
      if (!out.has(k.id)) {
        out.add(k.id);
        queue.push(k.id);
      }
    }
  }
  return out;
}

function isDescendant(
  folders: ProjectFolder[],
  candidateChild: string,
  ancestor: string,
): boolean {
  return collectDescendants(folders, ancestor).has(candidateChild);
}

function relativeDate(iso: string): string {
  const d = new Date(iso);
  const diffMs = Date.now() - d.getTime();
  const sec = Math.round(diffMs / 1000);
  const min = Math.round(sec / 60);
  const hr = Math.round(min / 60);
  const day = Math.round(hr / 24);
  if (sec < 60) return "à l'instant";
  if (min < 60) return `il y a ${min} min`;
  if (hr < 24) return `il y a ${hr} h`;
  if (day < 7) return `il y a ${day} j`;
  return d.toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
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
