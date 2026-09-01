import { apiJson } from "@/lib/api/server";
import UsersTable, { type AdminUser } from "./users-table";

type MeResponse = { id: string; user: { id: string } };

export const dynamic = "force-dynamic";

export default async function AdminUsersPage() {
  const [users, me] = await Promise.all([
    apiJson<AdminUser[]>("/api/admin/users"),
    apiJson<MeResponse>("/api/me"),
  ]);

  return (
    <div className="space-y-6">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Administration
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
          Utilisateurs
        </h1>
        <p className="mt-2 text-sm text-[var(--muted)]">
          {users.length} utilisateur{users.length > 1 ? "s" : ""} sur Arche.
          Modifie le rôle ou le nom puis enregistre. Les droits, eux,
          s&apos;appliquent au clic — une porte ne reste pas ouverte en
          attendant une confirmation.
        </p>
      </header>

      <UsersTable users={users} currentUserId={me.id} />
    </div>
  );
}
