import { createClient } from "@/lib/supabase/client";

export async function apiFetch(path: string, init?: RequestInit) {
  const supabase = createClient();
  const {
    data: { session },
  } = await supabase.auth.getSession();

  const headers = new Headers(init?.headers);
  headers.set("Authorization", `Bearer ${session?.access_token ?? ""}`);
  headers.set("Accept", "application/json");
  // Only set JSON Content-Type for non-FormData bodies — the browser must set
  // multipart boundary itself when uploading files.
  if (
    init?.body &&
    !(init.body instanceof FormData) &&
    !headers.has("Content-Type")
  ) {
    headers.set("Content-Type", "application/json");
  }

  return fetch(`${process.env.NEXT_PUBLIC_API_URL}${path}`, {
    ...init,
    headers,
  });
}
