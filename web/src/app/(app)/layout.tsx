import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import { apiJson } from "@/lib/api/server";
import { NotificationsProvider } from "@/core/notifications/notifications-context";
import NotificationsBell from "@/core/notifications/notifications-bell";
import TopLoader from "@/core/ui/top-loader";
import Sidebar from "./sidebar";

type MeResponse = {
  user: {
    role?: string;
    name?: string | null;
    avatar_url?: string | null;
  } | null;
};

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
  let userName: string | null = null;
  let avatarUrl: string | null = null;
  try {
    const me = await apiJson<MeResponse>("/api/me");
    isAdmin = me.user?.role === "admin";
    userName = me.user?.name ?? null;
    avatarUrl = me.user?.avatar_url ?? null;
  } catch {
    // Si l'API est down on continue sans le lien admin — pas bloquant.
  }

  return (
    <NotificationsProvider>
      <TopLoader />
      <div className="min-h-full">
        <Sidebar
          email={user.email ?? ""}
          isAdmin={isAdmin}
          avatarUrl={avatarUrl}
          name={userName}
        />
        <main className="pl-60 min-w-0">
          <div className="max-w-[1400px] mx-auto px-8 py-8">
            <div className="flex justify-end mb-4">
              <NotificationsBell />
            </div>
            <div key={user.id} className="page-fade-in">
              {children}
            </div>
          </div>
        </main>
      </div>
    </NotificationsProvider>
  );
}
