"use client";

import { useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { Loader2, MailCheck, ArrowLeft } from "lucide-react";

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const apiBase = process.env.NEXT_PUBLIC_API_URL || "";
      const res = await fetch(`${apiBase}/api/auth/forgot-password`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: email.trim().toLowerCase() }),
      });
      // Pour éviter l'enumeration, l'API renvoie toujours 200 même si l'email
      // n'existe pas. On affiche le même message dans tous les cas.
      if (!res.ok && res.status >= 500) {
        throw new Error(`Erreur ${res.status}`);
      }
      setDone(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Erreur réseau");
    } finally {
      setSubmitting(false);
    }
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
            Mot de passe oublié
          </h1>
          <p className="text-sm text-[var(--muted)] mt-1 text-center">
            Reçois un lien sécurisé pour redéfinir ton mot de passe.
          </p>
        </div>

        {done ? (
          <div className="rounded-xl border border-[var(--color-success)]/30 bg-[var(--color-success)]/5 p-6 space-y-3 text-center">
            <MailCheck className="w-7 h-7 text-[var(--color-success)] mx-auto" />
            <p className="text-sm font-medium">Vérifie ta boîte mail</p>
            <p className="text-xs text-[var(--muted)] leading-relaxed">
              Si un compte Arche existe avec l&apos;email{" "}
              <span className="font-mono text-[var(--foreground)]">{email}</span>,
              tu vas recevoir un lien de réinitialisation. Le lien est valable
              48&nbsp;heures.
            </p>
            <Link
              href="/login"
              className="inline-flex items-center gap-1 text-xs text-[var(--color-brand-red)] hover:underline"
            >
              <ArrowLeft size={11} /> Retour à la connexion
            </Link>
          </div>
        ) : (
          <form
            onSubmit={submit}
            className="rounded-xl border border-[var(--border)] bg-[var(--surface)] p-6 space-y-5"
          >
            <div className="space-y-1.5">
              <label htmlFor="email" className="text-xs font-medium">
                Email
              </label>
              <input
                id="email"
                type="email"
                autoComplete="email"
                required
                autoFocus
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="nom@paynala.com"
                className="w-full rounded-md border border-[var(--border)] bg-[var(--background)] px-3 py-2 text-sm placeholder:text-[var(--muted)]"
              />
            </div>

            {error && (
              <p className="text-xs text-[var(--color-danger)] border border-[var(--color-danger)]/20 rounded-md px-3 py-2">
                {error}
              </p>
            )}

            <button
              type="submit"
              disabled={submitting}
              className="w-full flex items-center justify-center gap-2 rounded-md bg-[var(--color-brand-red)] hover:bg-[var(--color-brand-red-600)] text-white text-sm font-medium py-2.5 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            >
              {submitting && <Loader2 size={14} className="animate-spin" />}
              {submitting ? "Envoi…" : "Recevoir le lien"}
            </button>

            <Link
              href="/login"
              className="block text-center text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
            >
              ← Retour à la connexion
            </Link>
          </form>
        )}
      </div>
    </main>
  );
}
