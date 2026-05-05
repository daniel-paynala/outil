export type ProjectUserStats = {
  todo: number; // tâches assignées dans la première colonne non-done
  doing: number; // tâches assignées dans les colonnes intermédiaires non-done
  done: number; // tâches assignées dans une colonne is_done
};

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
  user_stats?: ProjectUserStats;
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
  completed_at: string | null;
  estimate: string | null;
  assigned_to: string | null;
  created_by: string;
  created_at: string;
  updated_at: string;
  labels?: Label[];
  assignee?: Pick<User, "id" | "email" | "name"> | null;
  dependencies_count?: number;
  children_count?: number;
  comments_count?: number;
};

export type CardComment = {
  id: string;
  card_id: string;
  user_id: string;
  content: string;
  created_at: string;
  updated_at: string;
  user?: Pick<User, "id" | "email" | "name">;
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
  comments_count: number;
};

export type BoardColumn = {
  id: string;
  project_id: string;
  name: string;
  position: number;
  color: string | null;
  is_done: boolean;
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
  column: { id: string; name: string; position: number; is_done: boolean };
  labels: Label[];
  dependencies_count: number;
  children_count: number;
};

export type ProjectFile = {
  id: string;
  project_id: string;
  path: string;
  name: string;
  size_bytes: number;
  mime_type: string | null;
  uploaded_by: string;
  created_at: string;
  updated_at: string;
  uploader?: Pick<User, "id" | "email" | "name">;
};

export type DocPageSummary = {
  id: string;
  project_id: string;
  parent_id: string | null;
  title: string;
  slug: string;
  position: number;
  updated_at: string;
};

export type DocPage = DocPageSummary & {
  content: string | null;
  created_by: string;
  updated_by: string | null;
  created_at: string;
  deleted_at: string | null;
  creator?: Pick<User, "id" | "email" | "name">;
  updater?: Pick<User, "id" | "email" | "name"> | null;
};

export type DocRevision = {
  id: string;
  page_id: string;
  title: string;
  content: string | null;
  created_by: string;
  created_at: string;
  creator?: Pick<User, "id" | "email" | "name">;
};

export type VaultCategory =
  | "database"
  | "api"
  | "ssh"
  | "env"
  | "password"
  | "other";

export type VaultEntry = {
  id: string;
  project_id: string;
  name: string;
  category: VaultCategory;
  username: string | null;
  notes: string | null;
  url: string | null;
  expires_at: string | null;
  created_by: string;
  updated_by: string | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  creator?: Pick<User, "id" | "email" | "name">;
  updater?: Pick<User, "id" | "email" | "name"> | null;
};

export type VaultAccessLog = {
  id: string;
  entry_id: string;
  user_id: string;
  action:
    | "viewed"
    | "revealed"
    | "created"
    | "updated"
    | "deleted"
    | "restored";
  ip: string | null;
  user_agent: string | null;
  created_at: string;
  user?: Pick<User, "id" | "email" | "name">;
};

export type TimeEntry = {
  id: string;
  project_id: string;
  card_id: string | null;
  user_id: string;
  description: string | null;
  started_at: string;
  ended_at: string | null;
  seconds: number | null;
  created_at: string;
  updated_at: string;
  user?: Pick<User, "id" | "email" | "name">;
  card?: { id: string; title: string; column_id: string } | null;
  project?: Pick<Project, "id" | "name" | "slug" | "color">;
};

export type DecisionStatus =
  | "proposed"
  | "accepted"
  | "deprecated"
  | "superseded";

export type Decision = {
  id: string;
  project_id: string;
  number: number;
  title: string;
  status: DecisionStatus;
  context: string | null;
  decision: string | null;
  consequences: string | null;
  alternatives: string | null;
  references: string | null;
  decided_at: string | null;
  created_by: string;
  updated_by: string | null;
  created_at: string;
  updated_at: string;
  creator?: Pick<User, "id" | "email" | "name">;
  updater?: Pick<User, "id" | "email" | "name"> | null;
};

export type ActivityLog = {
  id: string;
  project_id: string;
  actor_id: string | null;
  action: string;
  subject_type: string | null;
  subject_id: string | null;
  subject_name: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
  actor?: Pick<User, "id" | "email" | "name"> | null;
};

type SearchProjectBadge = { id: string; name: string; color: string };

export type SearchResult = {
  id: string;
  title: string;
  snippet?: string | null;
  project: SearchProjectBadge | null;
  href: string;
};

export type SearchResults = {
  cards: SearchResult[];
  docs: SearchResult[];
  decisions: (SearchResult & { status: string })[];
  vault: (SearchResult & { category: string; username: string | null })[];
  files: (SearchResult & { mime_type: string | null })[];
  warning?: string | null;
};

export type GithubPlatform = "mobile" | "web" | "api" | "other";

export type GithubRepo = {
  id: string;
  project_id: string;
  full_name: string;
  platform: GithubPlatform;
  default_branch: string;
  description: string | null;
  linked_by: string;
  last_synced_at: string | null;
  created_at: string;
  updated_at: string;
  commits_count?: number;
  linker?: Pick<User, "id" | "email" | "name">;
};

export type GithubCommit = {
  id: string;
  github_repo_id: string;
  sha: string;
  message: string;
  author_name: string | null;
  author_email: string | null;
  author_login: string | null;
  author_avatar_url: string | null;
  html_url: string | null;
  authored_at: string | null;
  created_at: string;
  repo?: Pick<GithubRepo, "id" | "full_name" | "platform">;
};

// ── Roadmap ─────────────────────────────────────────────────────────────

export type RoadmapHorizon = "now" | "next" | "later" | "done";
export type RoadmapEffort = "S" | "M" | "L" | "XL";

export type Release = {
  id: string;
  project_id: string;
  name: string;
  description: string | null;
  shipped_at: string | null;
  color: string;
  created_by: string;
  created_at: string;
  updated_at: string;
  items_count?: number;
};

export type RoadmapItem = {
  id: string;
  project_id: string;
  release_id: string | null;
  owner_id: string | null;
  title: string;
  description: string | null;
  horizon: RoadmapHorizon;
  position: number;
  effort: RoadmapEffort | null;
  target_date: string | null;
  tags: string[] | null;
  created_by: string;
  updated_by: string | null;
  created_at: string;
  updated_at: string;
  cards_count?: number;
  owner?: Pick<User, "id" | "email" | "name"> | null;
  release?: Pick<Release, "id" | "name" | "color" | "shipped_at"> | null;
};

export type RoadmapPayload = {
  items: RoadmapItem[];
  releases: Release[];
};

export type RoadmapView =
  | "board"
  | "timeline"
  | "releases"
  | "list"
  | "calendar"
  | "mindmap";
