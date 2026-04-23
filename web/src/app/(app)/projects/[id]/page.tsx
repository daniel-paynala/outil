import { apiJson } from "@/lib/api/server";
import type { Project, User } from "@/lib/types";

type ProjectDetail = Project & {
  creator?: User;
  members?: Array<User & { pivot: { role: string } }>;
};

export default async function ProjectOverviewPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const project = await apiJson<ProjectDetail>(`/api/projects/${id}`);

  const createdAt = new Date(project.created_at).toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });

  return (
    <section className="grid grid-cols-1 md:grid-cols-3 gap-6">
      <div className="md:col-span-2 space-y-4">
        <Block title="À propos">
          <p className="text-sm text-[var(--muted)]">
            {project.description ?? "Aucune description."}
          </p>
        </Block>

        <Block title="Modules disponibles">
          <p className="text-sm text-[var(--muted)]">
            Ce projet dispose automatiquement d'un espace tâches, documentation,
            liaison GitHub, coffre de secrets, suivi du temps et journal de
            décisions. Active-les depuis les onglets ci-dessus dès qu'ils
            seront prêts.
          </p>
        </Block>
      </div>

      <aside className="space-y-4">
        <Block title="Détails">
          <dl className="text-sm space-y-2">
            <Row label="Créé le" value={createdAt} />
            <Row label="Slug" value={project.slug} mono />
            {project.creator && (
              <Row label="Créé par" value={project.creator.email} />
            )}
          </dl>
        </Block>

        {project.members && project.members.length > 0 && (
          <Block title={`Membres (${project.members.length})`}>
            <ul className="space-y-2">
              {project.members.map((m) => (
                <li key={m.id} className="flex items-center gap-2.5 text-sm">
                  <span className="size-6 rounded-full bg-[var(--color-neutral-200)] dark:bg-[var(--color-neutral-700)] flex items-center justify-center text-[11px] font-medium">
                    {m.email.charAt(0).toUpperCase()}
                  </span>
                  <span className="flex-1 truncate">{m.email}</span>
                  <span className="text-[10px] uppercase tracking-wider text-[var(--muted)]">
                    {m.pivot.role}
                  </span>
                </li>
              ))}
            </ul>
          </Block>
        )}
      </aside>
    </section>
  );
}

function Block({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5">
      <h2 className="text-sm font-medium mb-3">{title}</h2>
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
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-[var(--muted)] text-xs">{label}</dt>
      <dd className={mono ? "font-mono text-xs" : ""}>{value}</dd>
    </div>
  );
}
