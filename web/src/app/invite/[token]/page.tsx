"use client";

import { useEffect, useState, type FormEvent, use } from "react";
import { useRouter } from "next/navigation";
import Image from "next/image";
import { Loader2, AlertCircle, CheckCircle2 } from "lucide-react";
import { createClient } from "@/lib/supabase/client";
import { useToast } from "@/core/toast/toast-context";

type Status = "loading" | "ready" | "invalid" | "expired" | "used";

type InvitePayload = {
  email: string;
  name: string | null;
};

export default function InvitePage({
  params,
}: {
  params: Promise<{ token: string }>;
}) {
  const router = useRouter();
  const toast = useToast();
  const { token } = use(params);

  const [status, setStatus] = useState<Status>("loading");
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [invite, setInvite] = useState<InvitePayload | null>(null);

  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        const apiBase = process.env.NEXT_PUBLIC_API_URL || "";
        const res = await fetch(`${apiBase}/api/invite/${token}`, {
          cache: "no-store",
        });
        if (cancelled) return;

        const body = await res.json().catch(() => ({}));

        if (res.ok) {
          setInvite(body as InvitePayload);
          setStatus("ready");
          return;
        }

        if (res.status === 410) {
          // Expired or already used
          setStatus(body.error?.toLowerCase().includes("utilisé") ? "used" : "expired");
          setErrorMsg(body.error ?? null);
        } else {
          setStatus("invalid");
          setErrorMsg(body.error ?? `HTTP ${res.status}`);
        }
      } catch (e) {
        if (!cancelled) {
          setStatus("invalid");
          setErrorMsg(e instanceof Error ? e.message : "Erreur réseau");
        }
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [token]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);

    if (password.length < 12) {
      setFormError("Le mot de passe doit faire au moins 12 caractères.");
      return;
    }
    if (password !== confirm) {
      setFormError("Les deux mots de passe ne correspondent pas.");
      return;
    }

    setSubmitting(true);
    try {
      const apiBase = process.env.NEXT_PUBLIC_API_URL || "";
      const res = await fetch(`${apiBase}/api/invite/${token}/activate`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ password }),
      });
      const body = await res.json().catch(() => ({}));

      if (!res.ok) {
        throw new Error(body.error ?? `HTTP ${res.status}`);
      }

      // Auto-login pour amener directement au dashboard
      const supabase = createClient();
      const { error: signInErr } = await supabase.auth.signInWithPassword({
        email: body.email ?? invite?.email ?? "",
        password,
      });

      if (signInErr) {
        toast.success("Compte activé", "Connecte-toi avec ton nouveau mot de passe.");
        router.replace("/login");
        return;
      }

      toast.success("Bienvenue sur Arche", "Ton compte est activé.");
      router.replace("/dashboard");
      router.refresh();
    } catch (err) {
      const msg = err instanceof Error ? err.message : "Erreur inconnue";
      setFormError(msg);
      toast.error("Activation impossible", msg);
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
            Bienvenue sur Arche
          </h1>
          <p className="text-sm text-[var(--muted)] mt-1 text-center">
            Choisis ton mot de passe pour activer ton compte.
          </p>
        </div>

        {status === "loading" && (
          <div className="flex flex-col items-center gap-3 py-12 text-[var(--muted)]">
            <Loader2 className="w-5 h-5 animate-spin" />
            <p className="text-sm">Vérification du lien…</p>
          </div>
        )}

        {(status === "invalid" || status === "expired" || status === "used") && (
          <div className="rounded-xl border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 p-6 space-y-3">
            <AlertCircle className="w-6 h-6 text-[var(--color-danger)]" />
            <p className="text-[var(--color-danger)] font-medium text-sm">
              {status === "used"
                ? "Ce lien a déjà été utilisé."
                : status === "expired"
                  ? "Ce lien a expiré."
                  : "Lien d'invitation invalide."}
            </p>
            {errorMsg && (
              <p className="text-xs text-[var(--muted)]">{errorMsg}</p>
            )}
            <p className="text-sm text-[var(--muted)]">
              Demande à un administrateur de te renvoyer une nouvelle invitation
              depuis la page <span className="font-medium">Utilisateurs</span>.
            </p>
          </div>
        )}

        {status === "ready" && invite && (
          <form
            onSubmit={submit}
            className="rounded-xl border border-[var(--border)] bg-[var(--surface)] p-6 space-y-5"
          >
            <div className="rounded-md bg-[var(--color-success)]/5 border border-[var(--color-success)]/20 px-3 py-2 flex items-start gap-2">
              <CheckCircle2 className="w-4 h-4 text-[var(--color-success)] mt-0.5 shrink-0" />
              <div className="text-xs">
                <p className="text-[var(--color-success)] font-medium">
                  Lien valide{invite.name ? `, ${invite.name}` : ""}
                </p>
                <p className="text-[var(--muted)] mt-0.5">
                  Compte :{" "}
                  <span className="font-mono text-[var(--foreground)]">
                    {invite.email}
                  </span>
                </p>
              </div>
            </div>

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

            {formError && (
              <div className="rounded-md border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 px-3 py-2 text-xs text-[var(--color-danger)]">
                {formError}
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
                  Activation…
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
