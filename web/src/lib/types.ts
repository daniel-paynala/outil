export type Project = {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  color: string;
  created_by: string;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
};

export type User = {
  id: string;
  email: string;
  name: string | null;
  role: "admin" | "member";
};
