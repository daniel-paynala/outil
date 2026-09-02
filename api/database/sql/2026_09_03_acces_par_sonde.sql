-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Restreindre une sonde à certaines personnes.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- ## Le défaut est « tout le monde »
--
-- Une sonde sans aucune ligne ici est visible de qui a le droit `monitoring`,
-- exactement comme aujourd'hui. La restriction est une exception qu'on pose,
-- jamais un réglage qu'on oublie : l'absence de ligne ne peut pas cacher une
-- sonde par accident.
--
-- ## Qui échappe à la restriction, et pourquoi
--
-- Les porteurs de `monitoring.admin` continuent de tout voir. Ce n'est pas une
-- faveur : ils peuvent modifier n'importe quelle sonde, y compris sa requête,
-- et l'exécuter par le bouton « Essayer ». Leur masquer le résultat pendant
-- qu'ils gardent le moyen de l'obtenir ne serait pas de la confidentialité,
-- seulement une gêne — et une gêne qu'on croit être une protection est pire
-- que pas de protection du tout.
--
-- La restriction porte donc sur les membres à qui l'on a accordé la simple
-- consultation. C'est le cas réel : cinq personnes, dont on veut que certaines
-- voient les volumes de paiement et d'autres non.

create table if not exists monitoring_probe_viewers (
    probe_id  uuid not null,
    user_id   uuid not null,

    -- Qui a ouvert cet accès, et quand. Mêmes questions que pour
    -- `user_capabilities`, et on se les pose le même jour.
    granted_by uuid null,
    granted_at timestamp not null default (now() at time zone 'utc'),

    primary key (probe_id, user_id),

    constraint monitoring_probe_viewers_probe_id_foreign
        foreign key (probe_id) references monitoring_probes(id) on delete cascade,
    constraint monitoring_probe_viewers_user_id_foreign
        foreign key (user_id) references users(id) on delete cascade,
    constraint monitoring_probe_viewers_granted_by_foreign
        foreign key (granted_by) references users(id) on delete set null
);

-- La question posée à chaque affichage est « que peut voir cette
-- personne ? » ; celle posée en cas d'incident est « qui voyait cette
-- sonde ? ». Les deux index servent, dans cet ordre d'importance.
create index if not exists monitoring_probe_viewers_user_index
    on monitoring_probe_viewers (user_id);

comment on table monitoring_probe_viewers is
    'Restriction d''une sonde à certaines personnes. Table vide pour une sonde '
    '= visible de tous ceux qui ont le droit monitoring.';
