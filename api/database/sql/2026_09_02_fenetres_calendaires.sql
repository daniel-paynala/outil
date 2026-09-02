-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Le mode d'une fenêtre de sonde : glissante ou calendaire.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- ## Pourquoi ce choix ne pouvait pas être tranché une fois pour toutes
--
-- Une fenêtre **glissante** couvre les N dernières heures à tout instant. Elle
-- attrape une rafale à cheval sur minuit — deux incidents à 23 h et deux à 1 h
-- font quatre — là où le découpage par journée n'en voit que deux d'un côté et
-- deux de l'autre, et ne signale rien.
--
-- Une fenêtre **calendaire** compte depuis minuit. Elle dit ce que tout le
-- monde entend par « trois time-outs dans la journée », se recoupe avec les
-- rapports, et repart à zéro chaque nuit sans acquittement.
--
-- La première est meilleure pour détecter, la seconde pour décider. D'où un
-- choix par fenêtre.
--
-- Le défaut est `glissante` : c'est le comportement actuel, et les fenêtres
-- déjà enregistrées ne doivent pas changer de sens sous les pieds de qui a
-- réglé leurs paliers.

alter table monitoring_probe_windows
    add column if not exists mode varchar(16) not null default 'glissante';

alter table monitoring_probe_windows
    drop constraint if exists monitoring_probe_windows_mode_check;

alter table monitoring_probe_windows
    add constraint monitoring_probe_windows_mode_check
    check (mode in ('glissante', 'calendaire'));

-- Une fenêtre calendaire ne se découpe qu'en journées entières : « six heures
-- depuis minuit » changerait de longueur au fil de la journée.
alter table monitoring_probe_windows
    drop constraint if exists monitoring_probe_windows_calendaire_check;

alter table monitoring_probe_windows
    add constraint monitoring_probe_windows_calendaire_check
    check (mode <> 'calendaire' or hours % 24 = 0);

comment on column monitoring_probe_windows.mode is
    'glissante : les N dernières heures. calendaire : depuis minuit, heure de '
    'Libreville — voir config/monitoring.php.';
