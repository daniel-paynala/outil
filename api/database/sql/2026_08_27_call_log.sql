-- Journal des appels internes.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- ## Ce qu'il contient, et pourquoi si peu
--
-- Qui, quand, combien de temps, et comment ça s'est terminé. Rien d'autre —
-- surtout pas la voix, qui ne passe jamais par le serveur et n'est enregistrée
-- nulle part.
--
-- Une ligne par appel, écrite par **l'appelant seul**. Les deux la voient : la
-- requête cherche sur les deux colonnes. Faire écrire les deux côtés
-- produirait deux lignes pour une même conversation, et un historique qui
-- compte double.

create table if not exists call_logs (
    id            uuid primary key,

    caller_id     uuid not null,
    callee_id     uuid not null,

    -- Décroché. Null pour un appel manqué, refusé ou annulé — c'est ce qui
    -- distingue un appel qui a eu lieu d'un appel qui a seulement sonné.
    connected_at  timestamp null,

    -- Durée de la communication, en secondes. Zéro quand personne n'a
    -- décroché. Stockée plutôt que recalculée : la fin d'appel n'est pas
    -- toujours enregistrée, et une soustraction donnerait alors des durées
    -- absurdes.
    duration      integer not null default 0,

    -- `hungUp`, `declined`, `unanswered`, `busy`, `failed`, `cancelled`.
    -- Distinguer « refusé » de « pas de réponse » compte : le premier n'appelle
    -- pas de rappel, le second si.
    end_reason    varchar(20) not null,

    -- `direct` ou `relayed`. Conservé pour le diagnostic : une série d'appels
    -- relayés explique une latence que rien d'autre ne signale.
    route         varchar(10) null,

    created_at    timestamp null,
    updated_at    timestamp null,

    constraint call_logs_caller_id_foreign
        foreign key (caller_id) references users(id) on delete cascade,
    constraint call_logs_callee_id_foreign
        foreign key (callee_id) references users(id) on delete cascade
);

-- L'historique se lit toujours du plus récent au plus ancien, et toujours
-- filtré sur soi — d'où deux index, un par rôle possible.
create index if not exists call_logs_caller_id_created_at_index
    on call_logs (caller_id, created_at desc);
create index if not exists call_logs_callee_id_created_at_index
    on call_logs (callee_id, created_at desc);
