import { createClient } from "@/lib/supabase/server";
import { redirect } from "next/navigation";
import { CheckCircle2, AlertCircle } from "lucide-react";

export default async function DashboardPage() {
  const supabase = await createClient();

  const {
    data: { user },
  } = await supabase.auth.getUser();

  if (!user) redirect("/login");

  const {
    data: { session },
  } = await supabase.auth.getSession();

  let apiOk = false;
  let apiMe: unknown = null;
  let apiError: string | null = null;

  try {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/me`, {
      headers: { Authorization: `Bearer ${session?.access_token ?? ""}` },
      cache: "no-store",
    });
    apiMe = await res.json();
    apiOk = res.ok;
    if (!res.ok) apiError = `HTTP ${res.status}`;
  } catch (e) {
    apiError = e instanceof Error ? e.message : String(e);
  }

  return (
    <div className="space-y-8">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Vue d'ensemble
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
          Tableau de bord
        </h1>
        <p className="mt-2 text-sm text-[var(--muted)]">
          Bienvenue. Ton environnement de travail unifié.
        </p>
      </header>

      <section className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Card
          title="Session Supabase"
          status={user ? "ok" : "error"}
          statusLabel={user ? "Connecté" : "Hors session"}
        >
          <dl className="text-sm space-y-1">
            <Row label="Email" value={user.email ?? "—"} />
            <Row label="User ID" value={user.id} mono />
          </dl>
        </Card>

        <Card
          title="API Laravel"
          status={apiOk ? "ok" : "error"}
          statusLabel={apiOk ? "Authentifié" : apiError ?? "Erreur"}
        >
          <pre className="text-[11px] leading-snug font-mono text-[var(--muted)] max-h-40 overflow-auto">
            {JSON.stringify(apiMe, null, 2)}
          </pre>
        </Card>
      </section>

      <section>
        <h2 className="text-sm font-medium mb-3">Modules</h2>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          {[
            "Projets",
            "Documentation",
            "GitHub",
            "Coffre",
            "Recherche",
            "Snippets",
            "Décisions",
            "Agenda",
          ].map((m) => (
            <div
              key={m}
              className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-4 text-sm text-[var(--muted)]"
            >
              <p className="font-medium text-[var(--foreground)]">{m}</p>
              <p className="text-xs mt-1">À venir</p>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}

function Card({
  title,
  status,
  statusLabel,
  children,
}: {
  title: string;
  status: "ok" | "error";
  statusLabel: string;
  children: React.ReactNode;
}) {
  const Icon = status === "ok" ? CheckCircle2 : AlertCircle;
  const color =
    status === "ok"
      ? "text-[var(--color-success)]"
      : "text-[var(--color-danger)]";
  return (
    <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-sm font-medium">{title}</h3>
        <span className={`flex items-center gap-1.5 text-xs ${color}`}>
          <Icon size={14} strokeWidth={2} />
          {statusLabel}
        </span>
      </div>
      {children}
    </div>
  );
}

function Row({
  label,
  value,
  mono,
}: {
  label: string;
  value: string;
  mono?: boolean;
}) {
  return (
    <div className="flex items-baseline gap-3">
      <dt className="text-[var(--muted)] w-20 shrink-0">{label}</dt>
      <dd className={mono ? "font-mono text-xs" : ""}>{value}</dd>
    </div>
  );
}
