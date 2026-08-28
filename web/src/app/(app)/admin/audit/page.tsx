import { redirect } from "next/navigation";
import { apiJson } from "@/lib/api/server";
import AuditClient from "./audit-client";

type MeResponse = { user: { role?: string } | null };

export default async function AuditPage() {
  // Defense-in-depth: check server-side même si le layout admin filtre déjà.
  let role: string | undefined;
  try {
    const me = await apiJson<MeResponse>("/api/me");
    role = me.user?.role;
  } catch {
    redirect("/dashboard");
  }
  if (role !== "admin") redirect("/dashboard");

  return <AuditClient />;
}
