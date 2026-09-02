-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- Les sondes tournent-elles, à quelle cadence, et que rendent-elles ?
--
-- ## Ce que la colonne `cadence_constatee` prouve
--
-- Pour une fenêtre de période — ce mois-ci, cette année, depuis toujours — le
-- champ `hours` est l'intervalle de rechargement depuis le commit 18f3771.
-- Avant lui, ces fenêtres retombaient sur `interval_minutes`, soit une minute.
--
-- Si `il_y_a` reste sous la minute pour la sonde annuelle, le déploiement n'a
-- pas pris ce commit : elle recompte alors treize mois d'historique toutes les
-- soixante secondes, à neuf ou quinze secondes la fois.
--
-- Si `il_y_a` s'étale sur des heures, c'est en place.

select
    p.title,
    w.mode,
    w.hours                       as rechargement_h,
    p.timeout_ms,
    w.last_value,
    w.last_detail,
    w.severest_tier               as palier_signale,
    w.last_run_at,
    date_trunc('second', now() at time zone 'utc' - w.last_run_at) as il_y_a,
    p.last_error
from monitoring_probes p
join monitoring_probe_windows w on w.probe_id = p.id
order by w.last_run_at desc nulls first;
