-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- ─────────────────────────────────────────────────────────────────────────
--  Arche — messagerie interne, schéma v1
--  À exécuter dans l'éditeur SQL Supabase.
--
--  Conventions reprises des 35 tables existantes :
--    · clé primaire uuid sans défaut — Laravel la génère (trait HasUuids)
--    · timestamps `timestamp without time zone`, nullables
--    · contraintes nommées <table>_<colonne>_foreign
--    · index nommés <table>_<colonnes>_index
--    · RLS activée sans politique : PostgREST et la clé anon sont murés,
--      Laravel se connecte en `postgres` (rolbypassrls) et passe outre
--
--  Deux écarts assumés à la convention, signalés en place.
-- ─────────────────────────────────────────────────────────────────────────

-- ── Conversations ────────────────────────────────────────────────────────
create table if not exists conversations (
    id              uuid primary key,

    -- Null = conversation transverse, non rattachée à un projet.
    project_id      uuid null,

    -- Null pour un échange direct : le titre est alors l'autre personne.
    name            varchar(120) null,
    topic           varchar(255) null,
    is_group        boolean not null default true,

    -- ÉCART : la convention veut CASCADE sur `created_by`. Ici, supprimer le
    -- compte du créateur effacerait la conversation et tout son historique
    -- pour les autres membres. On préfère SET NULL, comme pour `actor_id`.
    created_by      uuid null,

    -- Dénormalisé : sans lui, trier la liste impose une jointure + agrégat
    -- sur `messages` à chaque chargement.
    last_message_at timestamp null,

    created_at      timestamp null,
    updated_at      timestamp null,

    constraint conversations_project_id_foreign
        foreign key (project_id) references projects(id) on delete cascade,
    constraint conversations_created_by_foreign
        foreign key (created_by) references users(id) on delete set null
);

create index if not exists conversations_project_id_index
    on conversations (project_id);
create index if not exists conversations_last_message_at_index
    on conversations (last_message_at desc);

-- ── Membres ──────────────────────────────────────────────────────────────
create table if not exists conversation_members (
    id              uuid primary key,
    conversation_id uuid not null,
    user_id         uuid not null,

    -- Même vocabulaire que project_members.
    role            varchar(20) not null default 'member',

    -- Le compteur de non-lus se déduit d'ici :
    --   count(messages où created_at > last_read_at)
    -- Une table d'accusés de lecture par (message × membre) coûterait une
    -- ligne par message et par personne — c'est elle qui ferait gonfler la
    -- base, pas les messages eux-mêmes.
    last_read_at    timestamp null,

    created_at      timestamp null,
    updated_at      timestamp null,

    constraint conversation_members_conversation_id_foreign
        foreign key (conversation_id) references conversations(id) on delete cascade,
    constraint conversation_members_user_id_foreign
        foreign key (user_id) references users(id) on delete cascade,
    constraint conversation_members_unique unique (conversation_id, user_id)
);

create index if not exists conversation_members_user_id_index
    on conversation_members (user_id);

-- ── Messages ─────────────────────────────────────────────────────────────
create table if not exists messages (
    id              uuid primary key,
    conversation_id uuid not null,

    -- ÉCART : card_comments.user_id est en CASCADE. Pour un fil de
    -- discussion, supprimer un compte trouerait l'historique commun. On
    -- garde le message, affiché comme « utilisateur supprimé ».
    user_id         uuid null,

    body            text not null,

    created_at      timestamp null,
    updated_at      timestamp null,

    -- Suppression logique, comme card_comments.
    deleted_at      timestamp null,

    constraint messages_conversation_id_foreign
        foreign key (conversation_id) references conversations(id) on delete cascade,
    constraint messages_user_id_foreign
        foreign key (user_id) references users(id) on delete set null
);

-- L'accès dominant : les N derniers messages d'une conversation.
create index if not exists messages_conversation_id_created_at_index
    on messages (conversation_id, created_at desc);

-- ── RLS ──────────────────────────────────────────────────────────────────
-- Activée sans politique, comme 33 des 35 tables : tout accès passe par
-- l'API Laravel. Si un jour le client lit Supabase en direct (motif déjà en
-- place sur `notifications`), il faudra ajouter des politiques pour le rôle
-- `authenticated`.
alter table conversations        enable row level security;
alter table conversation_members enable row level security;
alter table messages             enable row level security;
