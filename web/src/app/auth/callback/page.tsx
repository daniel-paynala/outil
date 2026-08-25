"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Loader2, AlertCircle } from "lucide-react";
import { createClient } from "@/lib/supabase/client";

/**
 * Callback Supabase Auth — gère les deux flows :
 *  - PKCE : ?code=xxx → exchangeCodeForSession
 *  - Implicit (utilisé par les invites/magic links) : #access_token=xxx&refresh_token=yyy
 *    → supabase-js parse automatiquement le hash au démarrage du client
 *
 * Une fois la session établie, redirige vers ?next= (ou /dashboard).
 */
export default function AuthCallbackPage() {
  return (
    <Suspense fallback={<LoadingState />}>
      <CallbackInner />
    </Suspense>
  );
}

function CallbackInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const supabase = createClient();
    const next = searchParams.get("next") || "/dashboard";
    const code = searchParams.get("code");
    const errorDescription =
      searchParams.get("error_description") ||
      (typeof window !== "undefined"
        ? new URLSearchParams(window.location.hash.replace(/^#/, "")).get(
            "error_description",
          )
        : null);

    async function run() {
      if (errorDescription) {
        setError(errorDescription);
        return;
      }

      try {
        // 1) Flow PKCE explicite (?code=)
        if (code) {
          const { error: exErr } = await supabase.auth.exchangeCodeForSession(code);
          if (exErr) {
            setError(exErr.message);
            return;
          }
        } else {
          // 2) Flow implicit (#access_token=) — supabase-js le parse au démarrage,
          //    on attend juste que la session soit posée dans le storage.
          await new Promise((r) => setTimeout(r, 80));
        }

        const {
          data: { session },
        } = await supabase.auth.getSession();

        if (cancelled) return;

        if (!session) {
          setError("Lien invalide ou expiré.");
          return;
        }

        if (typeof window !== "undefined" && window.location.hash) {
          history.replaceState(
            null,
            "",
            window.location.pathname + window.location.search,
          );
        }
        router.replace(next);
      } catch (e) {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "Erreur inconnue");
        }
      }
    }

    run();
    return () => {
      cancelled = true;
    };
  }, [router, searchParams]);

  return (
    <main className="min-h-screen flex items-center justify-center px-6 py-12">
      <div className="w-full max-w-sm text-center">
        {error ? (
          <div className="rounded-xl border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 p-6 space-y-3">
            <AlertCircle className="w-6 h-6 text-[var(--color-danger)] mx-auto" />
            <p className="text-sm text-[var(--color-danger)] font-medium">
              Lien invalide ou expiré
            </p>
            <p className="text-xs text-[var(--muted)]">{error}</p>
            <button
              onClick={() => router.replace("/login")}
              className="mt-2 text-xs text-[var(--color-brand-red)] hover:underline"
            >
              Retour à la connexion
            </button>
          </div>
        ) : (
          <LoadingState />
        )}
      </div>
    </main>
  );
}

function LoadingState() {
  return (
    <div className="text-[var(--muted)] flex flex-col items-center gap-3">
      <Loader2 className="w-6 h-6 animate-spin" />
      <p className="text-sm">Activation en cours…</p>
    </div>
  );
}
