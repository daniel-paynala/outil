import { createClient } from "@/lib/supabase/server";
import { redirect } from "next/navigation";
import LogoutButton from "./logout-button";

export default async function DashboardPage() {
  const supabase = await createClient();

  const {
    data: { user },
  } = await supabase.auth.getUser();

  if (!user) redirect("/login");

  const {
    data: { session },
  } = await supabase.auth.getSession();

  let apiMe: unknown = null;
  let apiError: string | null = null;

  try {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/me`, {
      headers: {
        Authorization: `Bearer ${session?.access_token ?? ""}`,
      },
      cache: "no-store",
    });
    apiMe = await res.json();
    if (!res.ok) apiError = `API ${res.status}`;
  } catch (e) {
    apiError = e instanceof Error ? e.message : String(e);
  }

  return (
    <main className="min-h-screen p-8 space-y-6">
      <header className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">outil — dashboard</h1>
        <LogoutButton />
      </header>

      <section className="space-y-2">
        <h2 className="text-lg font-medium">Supabase user</h2>
        <pre className="text-xs bg-black/5 dark:bg-white/10 rounded p-3 overflow-auto">
          {JSON.stringify({ id: user.id, email: user.email }, null, 2)}
        </pre>
      </section>

      <section className="space-y-2">
        <h2 className="text-lg font-medium">Laravel /api/me</h2>
        {apiError && <p className="text-sm text-red-500">{apiError}</p>}
        <pre className="text-xs bg-black/5 dark:bg-white/10 rounded p-3 overflow-auto">
          {JSON.stringify(apiMe, null, 2)}
        </pre>
      </section>
    </main>
  );
}
