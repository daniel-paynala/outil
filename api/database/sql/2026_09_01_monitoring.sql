-- Supervision des bases de l'entreprise.
--
-- À appliquer dans l'éditeur SQL de Supabase, après
-- `2026_09_01_capabilities.sql` dont il dépend.

-- ── Les bases surveillées ────────────────────────────────────────────────
--
-- Une ligne par base de production à surveiller. Les identifiants sont chiffrés
-- au repos par Laravel — même mécanisme que le coffre — et ne repartent jamais
-- vers l'application : elle affiche l'hôte et l'utilisateur, jamais le mot de
-- passe.
create table if not exists monitored_databases (
    id          uuid primary key,
    name        varchar(80) not null,

    host        varchar(255) not null,
    port        integer not null default 5432,
    dbname      varchar(120) not null,
    username    varchar(120) not null,
    -- Chiffré applicativement. La colonne est du texte parce que le chiffré
    -- l'est : la base ne sait pas ce qu'elle contient, et c'est le but.
    password    text not null,

    -- Vérifié à l'ajout : on se connecte et on **tente une écriture**. Si elle
    -- réussit, la base est refusée. C'est la seule façon de constater qu'un
    -- accès est en lecture seule plutôt que de le croire sur parole.
    read_only_verified_at timestamp null,

    last_error  text null,
    created_by  uuid null,
    created_at  timestamp null,
    updated_at  timestamp null,

    constraint monitored_databases_created_by_foreign
        foreign key (created_by) references users(id) on delete set null
);

-- ── Les sondes ───────────────────────────────────────────────────────────
--
-- Une requête qui rend **un seul nombre**, comparé à des paliers. Le contrat
-- est volontairement étroit : une sonde qui rendrait des lignes serait un
-- rapport, pas une alerte, et l'outil ne saurait pas quoi en faire.
create table if not exists monitoring_probes (
    id          uuid primary key,
    database_id uuid not null,

    title       varchar(120) not null,
    -- Ce que le nombre compte, pour la notification : « 12 time-outs ».
    unit        varchar(40) not null default 'événements',

    -- La requête doit rendre une colonne nommée `valeur`. `:depuis` y est
    -- substitué par le début de la fenêtre — voir `monitoring_probe_windows`.
    query       text not null,

    -- Depuis quand on compte. Posé à l'acquittement : les time-outs restent en
    -- base, ce qu'on remet à zéro est le point de départ du comptage.
    --
    -- Un décompte ne peut pas être « effacé », et un incident qui stagne
    -- au-dessus du premier palier ne redescendrait jamais tout seul. C'est donc
    -- un geste explicite qui referme l'affaire.
    counting_from  timestamp null,
    acknowledged_by uuid null,

    enabled     boolean not null default true,
    created_by  uuid null,
    created_at  timestamp null,
    updated_at  timestamp null,

    constraint monitoring_probes_database_id_foreign
        foreign key (database_id) references monitored_databases(id) on delete cascade,
    constraint monitoring_probes_created_by_foreign
        foreign key (created_by) references users(id) on delete set null,
    constraint monitoring_probes_acknowledged_by_foreign
        foreign key (acknowledged_by) references users(id) on delete set null
);

create index if not exists monitoring_probes_enabled_index
    on monitoring_probes (enabled);

-- ── Les fenêtres et leur état ────────────────────────────────────────────
--
-- Une sonde peut être observée sur plusieurs durées, chacune avec ses paliers
-- et son état propre. La fenêtre de 48 h attrape ce que celle de 24 h laisse
-- passer : deux time-outs par heure ne franchissent jamais 10 sur une journée,
-- mais en accumulent cinquante sur deux jours.
create table if not exists monitoring_probe_windows (
    id          uuid primary key,
    probe_id    uuid not null,

    hours       integer not null,
    -- Croissants, en JSON : [3, 10, 20, 40, 60, 100].
    tiers       jsonb not null,

    -- Le palier le plus haut déjà signalé depuis le dernier acquittement.
    --
    -- Sans lui, une fenêtre glissante renotifierait sans fin : le compte
    -- redescend quand de vieux événements en sortent, puis repasse le même
    -- palier. À douze secondes d'intervalle, cela fait trois cents
    -- notifications par heure et plus personne ne regarde l'application.
    highest_tier integer not null default 0,

    last_value  integer null,
    last_run_at timestamp null,

    constraint monitoring_probe_windows_probe_id_foreign
        foreign key (probe_id) references monitoring_probes(id) on delete cascade,
    constraint monitoring_probe_windows_unique unique (probe_id, hours)
);

-- ── Le journal des franchissements ───────────────────────────────────────
--
-- Une ligne par palier franchi. Ce n'est pas de la redondance avec l'état :
-- l'état dit où l'on en est, le journal dit comment on y est arrivé — à quelle
-- vitesse, et combien de fois ce mois-ci.
create table if not exists monitoring_alerts (
    id          uuid primary key,
    probe_id    uuid not null,
    window_hours integer not null,

    tier        integer not null,
    value       integer not null,
    raised_at   timestamp not null,

    constraint monitoring_alerts_probe_id_foreign
        foreign key (probe_id) references monitoring_probes(id) on delete cascade
);

create index if not exists monitoring_alerts_probe_raised_index
    on monitoring_alerts (probe_id, raised_at desc);
