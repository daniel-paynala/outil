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

export type Card = {
  id: string;
  project_id: string;
  column_id: string;
  title: string;
  description: string | null;
  position: number;
  priority: "low" | "medium" | "high" | "urgent" | null;
  due_date: string | null;
  assigned_to: string | null;
  created_by: string;
  created_at: string;
  updated_at: string;
};

export type BoardColumn = {
  id: string;
  project_id: string;
  name: string;
  position: number;
  color: string | null;
  cards: Card[];
  created_at: string;
  updated_at: string;
};
