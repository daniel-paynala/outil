import { redirect } from "next/navigation";
import { apiJson } from "@/lib/api/server";

type MeResponse = { user: { role?: string } | null };

export default async function AdminLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  let role: string | undefined;
  try {
    const me = await apiJson<MeResponse>("/api/me");
    role = me.user?.role;
  } catch {
    redirect("/dashboard");
  }

  if (role !== "admin") redirect("/dashboard");

  return <>{children}</>;
}
