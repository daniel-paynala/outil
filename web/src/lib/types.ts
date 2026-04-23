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

export type Label = {
  id: string;
  project_id: string;
  name: string;
  color: string;
};

export type CardSummary = {
  id: string;
  project_id: string;
  column_id: string;
  parent_card_id: string | null;
  title: string;
  description: string | null;
  position: number;
  priority: "low" | "medium" | "high" | "urgent" | null;
  due_date: string | null;
  estimate: string | null;
  assigned_to: string | null;
  created_by: string;
  created_at: string;
  updated_at: string;
  labels?: Label[];
  assignee?: Pick<User, "id" | "email" | "name"> | null;
  dependencies_count?: number;
  children_count?: number;
};

export type Card = CardSummary;

export type CardDetail = CardSummary & {
  labels: Label[];
  assignee: Pick<User, "id" | "email" | "name"> | null;
  creator: Pick<User, "id" | "email" | "name"> | null;
  parent: { id: string; title: string } | null;
  children: Array<{
    id: string;
    title: string;
    column_id: string;
    position: number;
    priority: string | null;
    due_date: string | null;
    assigned_to: string | null;
    assignee: Pick<User, "id" | "email" | "name"> | null;
  }>;
  dependencies: Array<{ id: string; title: string; column_id: string }>;
  dependents: Array<{ id: string; title: string; column_id: string }>;
};

export type BoardColumn = {
  id: string;
  project_id: string;
  name: string;
  position: number;
  color: string | null;
  cards: CardSummary[];
  created_at: string;
  updated_at: string;
};

export type ProjectMember = User & { pivot: { role: string } };

export type ProjectDetail = Project & {
  creator?: User;
  members?: ProjectMember[];
};

export type MyTask = CardSummary & {
  project: Pick<Project, "id" | "name" | "slug" | "color">;
  column: { id: string; name: string; position: number };
  labels: Label[];
  dependencies_count: number;
  children_count: number;
};
