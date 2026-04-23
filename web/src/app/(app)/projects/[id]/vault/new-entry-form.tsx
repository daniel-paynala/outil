"use client";

import { useState, useEffect } from "react";
import { X, Eye, EyeOff, Loader2 } from "lucide-react";
import type { VaultCategory, VaultEntry } from "@/lib/types";
import { apiFetch } from "@/lib/api/client";
import { CATEGORIES } from "./vault-client";

export default function NewEntryForm({
  projectId,
  onClose,
  onCreated,
}: {
  projectId: string;
  onClose: () => void;
  onCreated: (e: VaultEntry) => void;
}) {
  const [name, setName] = useState("");
  const [category, setCategory] = useState<VaultCategory>("api");
  const [username, setUsername] = useState("");
  const [secret, setSecret] = useState("");
  const [url, setUrl] = useState("");
  const [notes, setNotes] = useState("");
  const [expiresAt, setExpiresAt] = useState("");
  const [showSecret, setShowSecret] = useState(false);
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
    setError(null);
    setLoading(true);

    const body = {
      name,
      category,
      username: username || null,
      secret,
      url: url || null,
      notes: notes || null,
      expires_at: expiresAt || null,
    };

    const res = await apiFetch(`/api/projects/${projectId}/vault`, {
      method: "POST",
      body: JSON.stringify(body),
    });
    setLoading(false);
    if (!res.ok) {
      setError(await res.text());
      return;
    }
    onCreated((await res.json()) as VaultEntry);
  }

  return (
    <div className="fixed inset-0 z-40 flex">
      <div className="flex-1 bg-black/20" onClick={onClose} />
      <aside className="w-full max-w-lg bg-[var(--background)] border-l border-[var(--border)] flex flex-col shadow-2xl">
        <header className="px-6 py-4 border-b border-[var(--border)] flex items-center">
          <h3 className="text-sm font-medium flex-1">Nouvelle entrée</h3>
          <button
            onClick={onClose}
            className="p-1.5 rounded hover:bg-[var(--color-neutral-100)] dark:hover:bg-[var(--color-neutral-800)]"
          >
            <X size={15} />
          </button>
        </header>

        <form onSubmit={submit} className="flex-1 overflow-y-auto px-6 py-5 space-y-4">
          <Field label="Nom" required>
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              autoFocus
              maxLength={120}
              placeholder="ex: Stripe prod API key"
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
            />
          </Field>

          <Field label="Catégorie" required>
            <select
              value={category}
              onChange={(e) => setCategory(e.target.value as VaultCategory)}
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
            >
              {CATEGORIES.map((c) => (
                <option key={c.key} value={c.key}>
                  {c.label}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Utilisateur / identifiant">
            <input
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              placeholder="ex: postgres, admin@company.com"
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
            />
          </Field>

          <Field label="Secret" required>
            <div className="relative">
              <textarea
                value={secret}
                onChange={(e) => setSecret(e.target.value)}
                required
                rows={showSecret ? 4 : 1}
                spellCheck={false}
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 pr-10 text-sm font-mono"
                style={
                  !showSecret
                    ? {
                        WebkitTextSecurity: "disc",
                        textSecurity: "disc",
                      } as React.CSSProperties
                    : undefined
                }
              />
              <button
                type="button"
                onClick={() => setShowSecret((v) => !v)}
                className="absolute right-2 top-2 p-1 rounded text-[var(--muted)] hover:text-[var(--foreground)]"
                aria-label={showSecret ? "Masquer" : "Afficher"}
              >
                {showSecret ? <EyeOff size={13} /> : <Eye size={13} />}
              </button>
            </div>
          </Field>

          <Field label="URL (dashboard, serveur…)">
            <input
              type="url"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder="https://dashboard.stripe.com"
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
            />
          </Field>

          <Field label="Notes">
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={3}
              placeholder="Contexte, rotation, lien doc…"
              className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm resize-y"
            />
          </Field>

          <Field label="Date d'expiration">
            <input
              type="date"
              value={expiresAt}
              onChange={(e) => setExpiresAt(e.target.value)}
              className="rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm"
            />
          </Field>

          {error && (
            <p className="text-xs text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded px-3 py-2">
              {error}
            </p>
          )}
        </form>

        <footer className="px-6 py-4 border-t border-[var(--border)] flex gap-2">
          <button
            onClick={submit}
            disabled={loading || !name.trim() || !secret.trim()}
            className="inline-flex items-center gap-1.5 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white px-4 py-2 text-sm font-medium disabled:opacity-50"
          >
            {loading && <Loader2 size={13} className="animate-spin" />}
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

function Field({
  label,
  required,
  children,
}: {
  label: string;
  required?: boolean;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <label className="text-xs font-medium flex items-center gap-1">
        {label}
        {required && <span className="text-[var(--color-brand-red)]">*</span>}
      </label>
      {children}
    </div>
  );
}
