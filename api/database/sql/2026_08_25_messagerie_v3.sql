-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- ─────────────────────────────────────────────────────────────────────────
--  Arche — messagerie v3 : édition et accusés de lecture
--  À exécuter dans l'éditeur SQL Supabase, après le v2.
-- ─────────────────────────────────────────────────────────────────────────

-- Les positions de lecture doivent circuler en temps réel.
--
-- `messages` était seul publié : une modification de message passait déjà
-- (une publication couvre insert, update et delete), mais pas le « vu ».
-- Celui-ci se déduit de `conversation_members.last_read_at` : sans diffusion
-- de cette table, l'accusé n'apparaîtrait qu'au prochain chargement de la
-- liste — soit exactement le délai qu'on vient de supprimer.
--
-- Les politiques de lecture posées en v2 s'appliquent : un membre ne reçoit
-- que les positions des conversations dont il fait partie.
do $$
begin
    if not exists (
        select 1 from pg_publication_tables
        where pubname = 'supabase_realtime' and tablename = 'conversation_members'
    ) then
        alter publication supabase_realtime add table conversation_members;
    end if;
end $$;

-- Trace de l'édition.
--
-- On pourrait comparer `updated_at` à `created_at`, mais toute écriture sur
-- la ligne touche `updated_at` — y compris une suppression logique. Une
-- colonne dédiée dit exactement ce qu'elle dit, et un message jamais modifié
-- la laisse à null.
alter table messages add column if not exists edited_at timestamp null;
