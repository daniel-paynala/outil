-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- Les paliers des trois sondes de montant, posés sur des mesures.
--
-- ## Ce que valaient les précédents
--
-- Je les avais écrits sans connaître les volumes. Mesuré depuis :
--
--   historique          406 jours, depuis le 23/07/2025
--   moyenne 30 jours      7 348 690 F/jour
--   moyenne de l'année    3 932 945 F/jour   (l'activité a presque doublé)
--   cumul                 1,12 Md
--   année en cours        960 M
--
-- Le cumul « depuis toujours » dépassait donc déjà mes trois premiers jalons.
-- Ils auraient été franchis au tout premier tour — un seul signalement, la
-- règle sautant les paliers intermédiaires, puis plus rien pendant des années.
--
-- ## Comment ceux-ci sont choisis
--
-- Un jalon doit tomber assez souvent pour qu'on le remarque, assez rarement
-- pour qu'il compte. Au rythme des trente derniers jours :
--
--   ce mois-ci        100 M   dans ~11 jours     → un signalement par mois
--   cette année       1 Md    dans ~5 jours      → puis 2 Md en fin d'année
--   depuis toujours   1,5 Md  dans ~51 jours     → un par trimestre environ
--
-- Les jalons suivants de chaque échelle sont là pour les mois et les années
-- exceptionnels. Ils ne sonneront pas cette année, et c'est voulu : une échelle
-- dont tous les crans sont franchis ne dit plus rien.

update monitoring_probe_windows w
set tiers = '[100000000, 250000000, 500000000, 1000000000]'::jsonb
from monitoring_probes p
where p.id = w.probe_id
  and p.title = 'Montant collecté — ce mois-ci';

update monitoring_probe_windows w
set tiers = '[1000000000, 2000000000, 3000000000, 5000000000]'::jsonb
from monitoring_probes p
where p.id = w.probe_id
  and p.title = 'Montant collecté — cette année';

update monitoring_probe_windows w
set tiers = '[1500000000, 2000000000, 3000000000, 5000000000, 10000000000]'::jsonb
from monitoring_probes p
where p.id = w.probe_id
  and p.title = 'Montant collecté — depuis toujours';

-- Ce qui est posé, et à quelle distance du prochain jalon.
select
    p.title,
    w.tiers,
    w.severest_tier as deja_signale,
    w.last_value
from monitoring_probes p
join monitoring_probe_windows w on w.probe_id = p.id
where p.title like 'Montant collecté%'
order by w.mode;
