-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- Une sonde signale-t-elle un incident, ou franchit-elle un jalon ?
--
-- ## Le défaut que ceci corrige
--
-- La pastille disait orange sur « Montant collecté — depuis toujours », et
-- Daniel a lu ce qu'elle disait : « c'est pas assez ? ». Le code couleur —
-- vert, orange, rouge — a été conçu pour des sondes qui comptent ce qui ne
-- devrait pas arriver. Sur une sonde de montant, franchir un palier est une
-- bonne nouvelle, et la peindre en orange fait lire l'inverse.
--
-- Le sens de dégradation ne suffisait pas à le dire : une sonde d'erreurs
-- croissante et une sonde de chiffre d'affaires croissante montent toutes les
-- deux, mais l'une empire pendant que l'autre prospère.
--
-- `incident` — le défaut, et le comportement actuel. Franchir un palier
--              demande un geste : pastille orange puis rouge, section « à
--              traiter », acquittement.
--
-- `jalon`    — franchir est une nouvelle qu'on veut recevoir. Pastille neutre,
--              pas de section « à traiter », rien à acquitter : le jalon
--              suivant se signalera de lui-même puisqu'il est plus haut.

alter table monitoring_probes
    add column if not exists nature varchar(16) not null default 'incident';

alter table monitoring_probes
    drop constraint if exists monitoring_probes_nature_check;

alter table monitoring_probes
    add constraint monitoring_probes_nature_check
    check (nature in ('incident', 'jalon'));

comment on column monitoring_probes.nature is
    'incident : franchir un palier demande un geste. jalon : franchir est une '
    'bonne nouvelle — pastille neutre, pas d''acquittement.';

-- Les quatre sondes de montant sont des jalons, sauf celle qui surveille un
-- effondrement sur une heure : elle, c'est bien un incident.
update monitoring_probes p
set nature = 'jalon'
where p.unit = 'F CFA'
  and exists (
      select 1 from monitoring_probe_windows w
      where w.probe_id = p.id
        and w.mode in ('mensuelle', 'annuelle', 'totale')
  );

select title, unit, nature from monitoring_probes order by nature, title;
