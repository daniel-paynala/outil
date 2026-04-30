import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import { apiJson } from "@/lib/api/server";
import Sidebar from "./sidebar";

type MeResponse = { user: { role?: string } | null };

export default async function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();

  if (!user) redirect("/login");

  let isAdmin = false;
  try {
    const me = await apiJson<MeResponse>("/api/me");
    isAdmin = me.user?.role === "admin";
  } catch {
    // Si l'API est down on continue sans le lien admin — pas bloquant.
  }

  return (
    <div className="flex min-h-full">
      <Sidebar email={user.email ?? ""} isAdmin={isAdmin} />
      <main className="flex-1 min-w-0">
        <div className="max-w-[1400px] mx-auto px-8 py-8">{children}</div>
      </main>
    </div>
  );
}
