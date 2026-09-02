-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Le mode de fenêtre que les heures ne savaient pas exprimer.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- `mensuelle` — depuis le 1er du mois, heure de Libreville.
-- `annuelle` — depuis le 1er janvier, heure de Libreville.
-- `totale` — depuis toujours, pour un cumul.
--
-- ## Ce que « totale » change à l'acquittement
--
-- Un cumul ne se remet pas à zéro. Acquitter rouvre ses paliers — « oui, j'ai
-- vu les cent millions » — mais ne déplace pas le début du comptage : un total
-- qui repartirait de zéro à chaque accusé de réception ne mesurerait plus rien.
-- C'est la seule fenêtre où l'acquittement n'agit pas sur la valeur.
--
-- ## Pourquoi 720 heures n'auraient pas suffi
--
-- Mesuré, quatre dates, ce que chaque mode donne comme début de comptage :
--
--   on regarde le    720 h glissantes    720 h calendaires   mensuelle
--   02/09 08:00      03/08 08:00         03/08 23:00         31/08 23:00
--   17/09 15:00      18/08 15:00         18/08 23:00         31/08 23:00
--   01/03 08:00      30/01 08:00         30/01 23:00         28/02 23:00
--   31/03 20:00      01/03 20:00         01/03 23:00         28/02 23:00
--
-- Une fenêtre de 720 heures ne dit jamais « ce mois-ci ». Le 17 septembre elle
-- compte encore la moitié d'août ; le 1er mars elle remonte au 30 janvier. Et
-- elle ne repart jamais : un chiffre de chiffre d'affaires qui ne se remet pas
-- à zéro le 1er ne se recoupe avec aucun rapport.
--
-- S'ajoute que les mois n'ont pas la même durée. 720 heures valent 30 jours :
-- deux de trop en février, un de moins en mars.
--
-- L'année, elle, n'était même pas exprimable : une fenêtre plafonne à
-- 720 heures, et une année en compte 8 760.

alter table monitoring_probe_windows
    drop constraint if exists monitoring_probe_windows_mode_check;

alter table monitoring_probe_windows
    add constraint monitoring_probe_windows_mode_check
    check (mode in
        ('glissante', 'calendaire', 'mensuelle', 'annuelle', 'totale'));

comment on column monitoring_probe_windows.mode is
    'glissante : les N dernières heures. calendaire : depuis minuit. '
    'mensuelle : depuis le 1er du mois. annuelle : depuis le 1er janvier. '
    'totale : depuis toujours. Ces trois derniers ignorent la colonne hours.';
