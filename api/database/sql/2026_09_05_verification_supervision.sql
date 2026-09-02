-- Où en est l'installation de la supervision ?
--
-- À coller dans l'éditeur SQL de Supabase. Ne modifie rien : elle liste ce qui
-- manque, dans l'ordre où il faut l'appliquer.
--
-- Chaque ligne « MANQUE » désigne le fichier à exécuter. Les six s'appliquent
-- dans l'ordre de leur date : chacun suppose les précédents.

select * from (
    values
    ('1. user_capabilities',
     '2026_09_01_capabilities.sql',
     to_regclass('public.user_capabilities') is not null),

    ('2. monitored_databases',
     '2026_09_01_monitoring.sql',
     to_regclass('public.monitored_databases') is not null),

    ('2. monitoring_probes',
     '2026_09_01_monitoring.sql',
     to_regclass('public.monitoring_probes') is not null),

    ('2. monitoring_probe_windows',
     '2026_09_01_monitoring.sql',
     to_regclass('public.monitoring_probe_windows') is not null),

    ('2. monitoring_alerts',
     '2026_09_01_monitoring.sql',
     to_regclass('public.monitoring_alerts') is not null),

    ('3. colonne mode',
     '2026_09_02_fenetres_calendaires.sql',
     exists (select 1 from information_schema.columns
             where table_name = 'monitoring_probe_windows'
               and column_name = 'mode')),

    ('4. colonne direction',
     '2026_09_02_sens_des_paliers.sql',
     exists (select 1 from information_schema.columns
             where table_name = 'monitoring_probe_windows'
               and column_name = 'direction')),

    ('4. colonne severest_tier (renommée)',
     '2026_09_02_sens_des_paliers.sql',
     exists (select 1 from information_schema.columns
             where table_name = 'monitoring_probe_windows'
               and column_name = 'severest_tier')),

    ('5. monitoring_probe_viewers',
     '2026_09_03_acces_par_sonde.sql',
     to_regclass('public.monitoring_probe_viewers') is not null),

    ('6. modes mensuelle / annuelle / totale',
     '2026_09_04_fenetres_de_periode.sql',
     exists (select 1 from pg_constraint
             where conname = 'monitoring_probe_windows_mode_check'
               and pg_get_constraintdef(oid) like '%totale%'))
) as controle(objet, fichier, present)
order by objet;

-- Et qui peut voir la supervision aujourd'hui. Une liste vide explique à elle
-- seule un menu absent : le droit ne se déduit pas du rôle pour les membres.
select u.email, u.role, c.capability
from users u
left join user_capabilities c on c.user_id = u.id
order by u.role desc, u.email;
