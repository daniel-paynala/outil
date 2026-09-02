-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Droits accordés au cas par cas.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- ## Pourquoi une table et non une colonne sur `users`
--
-- `peut_superviser` aurait suffi aujourd'hui, et il aurait fallu une colonne de
-- plus au droit suivant. Surtout, une colonne ne dit pas **qui** a accordé le
-- droit ni **quand** — deux questions qu'on se pose forcément le jour où
-- quelqu'un voit ce qu'il ne devrait pas.

create table if not exists user_capabilities (
    user_id     uuid not null,
    capability  varchar(64) not null,

    -- Qui a accordé, et quand. `granted_by` est nullable pour les droits posés
    -- à la main dans cet éditeur, qui n'ont pas d'auteur applicatif.
    granted_by  uuid null,
    granted_at  timestamp not null default (now() at time zone 'utc'),

    -- Un droit ne s'accorde qu'une fois par personne.
    primary key (user_id, capability),

    constraint user_capabilities_user_id_foreign
        foreign key (user_id) references users(id) on delete cascade,
    constraint user_capabilities_granted_by_foreign
        foreign key (granted_by) references users(id) on delete set null
);

-- La question posée à chaque requête est « qu'a le droit de faire cette
-- personne ? ». L'index par utilisateur sert donc tout le trafic ; celui par
-- droit ne sert qu'à la question inverse, rare, mais c'est celle qu'on pose en
-- cas d'incident : « qui avait accès ? »
create index if not exists user_capabilities_capability_index
    on user_capabilities (capability);

comment on table user_capabilities is
    'Droits accordés individuellement, au-delà du couple membre/administrateur. '
    'Le premier usage est la supervision des bases, qui donne à voir des '
    'données de production.';
