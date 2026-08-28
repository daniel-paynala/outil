"use client";

import { useEffect, useState, type FormEvent } from "react";
import Image from "next/image";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { createClient } from "@/lib/supabase/client";
import { useToast } from "@/core/toast/toast-context";

type Status = "loading" | "ready" | "no-session" | "error";

export default function AccountSetupPage() {
  const router = useRouter();
  const supabase = createClient();
  const toast = useToast();

  const [status, setStatus] = useState<Status>("loading");
  const [email, setEmail] = useState<string | null>(null);
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function init() {
      // supabase-js parse automatiquement le hash #access_token=... à l'init.
      // On laisse une frame puis on lit la session.
      await new Promise((r) => setTimeout(r, 50));
      const {
        data: { session },
      } = await supabase.auth.getSession();
      if (cancelled) return;

      if (!session) {
        setStatus("no-session");
        return;
      }
      setEmail(session.user.email ?? null);
      setStatus("ready");
    }

    init();

    return () => {
      cancelled = true;
    };
  }, [supabase]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);

    if (password.length < 12) {
      setError("Le mot de passe doit faire au moins 12 caractères.");
      return;
    }
    if (password !== confirm) {
      setError("Les deux mots de passe ne correspondent pas.");
      return;
    }

    setSubmitting(true);
    const { error: err } = await supabase.auth.updateUser({ password });
    if (err) {
      setError(err.message);
      toast.error("Activation impossible", err.message);
      setSubmitting(false);
      return;
    }
    toast.success("Compte activé", "Mot de passe défini.");
    router.replace("/dashboard");
    router.refresh();
  }

  return (
    <main className="min-h-screen flex items-center justify-center px-6 py-12 bg-[var(--background)]">
      <div className="w-full max-w-sm">
        <div className="flex flex-col items-center mb-8">
          <Image
            src="/paynala-icon.png"
            alt="Paynala"
            width={44}
            height={44}
            priority
          />
          <h1 className="mt-4 text-xl font-semibold tracking-tight">
            Bienvenue sur Arche
          </h1>
          <p className="text-sm text-[var(--muted)] mt-1">
            Choisis ton mot de passe pour activer ton compte.
          </p>
        </div>

        {status === "loading" && (
          <div className="flex justify-center py-12 text-[var(--muted)]">
            <Loader2 className="w-5 h-5 animate-spin" />
          </div>
        )}

        {status === "no-session" && (
          <div className="rounded-xl border border-[var(--border)] bg-[var(--surface)] p-6 text-sm text-[var(--muted)] space-y-3">
            <p className="text-[var(--color-danger)] font-medium">
              Lien d&apos;invitation invalide ou expiré.
            </p>
            <p>
              Demande à un administrateur de te renvoyer une nouvelle
              invitation. Les liens sont valables 24 heures.
            </p>
          </div>
        )}

        {status === "ready" && (
          <form
            onSubmit={submit}
            className="rounded-xl border border-[var(--border)] bg-[var(--surface)] p-6 space-y-5"
          >
            {email && (
              <div className="text-xs text-[var(--muted)] -mb-2">
                Connecté en tant que{" "}
                <span className="font-mono text-[var(--foreground)]">
                  {email}
                </span>
              </div>
            )}

            <div className="space-y-1.5">
              <label htmlFor="password" className="text-xs font-medium">
                Nouveau mot de passe
              </label>
              <input
                id="password"
                type="password"
                autoComplete="new-password"
                required
                minLength={12}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm placeholder:text-[var(--muted)]"
                placeholder="Au moins 12 caractères"
              />
            </div>

            <div className="space-y-1.5">
              <label htmlFor="confirm" className="text-xs font-medium">
                Confirmation
              </label>
              <input
                id="confirm"
                type="password"
                autoComplete="new-password"
                required
                minLength={12}
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm placeholder:text-[var(--muted)]"
                placeholder="Retape le mot de passe"
              />
            </div>

            {error && (
              <div className="rounded-md border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 px-3 py-2 text-xs text-[var(--color-danger)]">
                {error}
              </div>
            )}

            <button
              type="submit"
              disabled={submitting}
              className="w-full rounded-md bg-[var(--color-brand-red)] text-white text-sm font-medium px-3 py-2 disabled:opacity-50 disabled:cursor-not-allowed hover:opacity-90"
            >
              {submitting ? (
                <span className="inline-flex items-center gap-2">
                  <Loader2 className="w-4 h-4 animate-spin" />
                  Enregistrement…
                </span>
              ) : (
                "Activer mon compte"
              )}
            </button>
          </form>
        )}
      </div>
    </main>
  );
}
