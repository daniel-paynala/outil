-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- Les paliers des trois sondes de montant, posés sur des mesures.
--
-- ## Ce que valaient les précédents
--
--   historique          406 jours, depuis le 23/07/2025
--   moyenne 30 jours      7 348 690 F/jour
--   moyenne de l'année    3 932 945 F/jour   (l'activité a presque doublé)
--   cumul                 1,12 Md
--   année en cours        960 M
--
-- Le cumul dépassait déjà mes trois premiers jalons. Ils ont d'ailleurs tous
-- sonné au premier tour — un seul signalement chacun, la règle sautant les
-- paliers intermédiaires — puis n'auraient plus rien dit pendant des années.
-- Une échelle dont tous les crans sont derrière soi ne dit plus rien, et on
-- croit encore la lire.
--
-- ## Pourquoi la clé est le mode et non le titre
--
-- Un premier jet appariait sur le titre exact. Une sonde renommée depuis
-- l'écran n'aurait alors rien reçu, et l'`update` aurait rendu « succès » sans
-- toucher une ligne — la pire forme d'échec, celle qui se félicite.
--
-- Le mode de fenêtre, lui, ne se renomme pas : `mensuelle`, `annuelle` et
-- `totale` sont les trois seules fenêtres de période, et l'unité « F CFA » les
-- distingue de toute autre sonde qui en porterait une.
--
-- Le compte de lignes touchées est affiché à la fin. Il doit valoir 3.
--
-- ## Pourquoi `last_run_at` repart à zéro
--
-- Une fenêtre de période se recharge selon ses heures — 24 pour ces trois-là.
-- Elles ont donc tourné une fois, puis attendent demain. Tout ce qui a changé
-- entre-temps — la décomposition par portefeuille, un déploiement, ces
-- paliers — ne se verrait qu'après ce délai.
--
-- Vider `last_run_at` rend la fenêtre immédiatement due : elle recompte au
-- prochain tour du planificateur, dans la minute. C'est le seul moyen de ne
-- pas attendre un jour pour voir l'effet d'un changement, et c'est sans risque :
-- on ne perd qu'un horodatage, jamais une valeur ni un palier signalé.

update monitoring_probe_windows w
set tiers = case w.mode
        when 'mensuelle' then '[100000000, 250000000, 500000000, 1000000000]'
        when 'annuelle'  then '[1000000000, 2000000000, 3000000000, 5000000000]'
        when 'totale'    then '[1500000000, 2000000000, 3000000000, 5000000000, 10000000000]'
    end::jsonb,

    -- Recompter tout de suite, sans attendre les vingt-quatre heures.
    last_run_at = null
from monitoring_probes p
where p.id = w.probe_id
  and p.unit = 'F CFA'
  and w.mode in ('mensuelle', 'annuelle', 'totale');

-- Ce qui est posé, et à quelle distance du prochain jalon.
select
    p.title,
    w.mode,
    w.tiers,
    w.last_value,
    w.severest_tier as deja_signale,
    p.timeout_ms,
    w.hours         as rechargement_h,
    w.last_run_at
from monitoring_probes p
join monitoring_probe_windows w on w.probe_id = p.id
where p.unit = 'F CFA'
order by w.mode;
