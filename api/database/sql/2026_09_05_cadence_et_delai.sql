-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Le délai d'exécution et la cadence, réglables par sonde. Et le détail.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- ## Ce que l'usage a montré
--
--     SQLSTATE[57014] : canceling statement due to statement timeout
--
-- Le plafond était de huit secondes pour toutes les sondes. C'était le bon
-- ordre de grandeur pour compter des time-outs sur une table indexée ; ce ne
-- l'est pas pour croiser `payment` et les ~890 000 lignes d'`airtel_logs`. Le
-- tableau de bord Paynala, qui exécute les mêmes requêtes, leur accorde
-- 45 secondes et note qu'elles « prennent légitimement 30 s et plus ».
--
-- ## Pourquoi la cadence compte autant que le délai
--
-- Onze sondes à 45 secondes, toutes les minutes, ne tiennent pas : elles se
-- chevauchent, la garde les empêche de partir, et la supervision décroche sans
-- rien dire. Une sonde de montant mensuel n'a aucun besoin de tourner chaque
-- minute — son chiffre bouge de quelques francs. Une sonde de time-outs, si.
--
-- Le défaut reste une minute : aucune sonde existante ne change de rythme.

alter table monitoring_probes
    add column if not exists timeout_ms integer not null default 8000;

alter table monitoring_probes
    add column if not exists interval_minutes integer not null default 1;

alter table monitoring_probes
    drop constraint if exists monitoring_probes_timeout_check;

alter table monitoring_probes
    add constraint monitoring_probes_timeout_check
    check (timeout_ms between 1000 and 60000);

alter table monitoring_probes
    drop constraint if exists monitoring_probes_interval_check;

alter table monitoring_probes
    add constraint monitoring_probes_interval_check
    check (interval_minutes between 1 and 1440);

-- Le détail : les colonnes que la requête rend en plus de `valeur`.
--
-- Une sonde ne peut alerter que sur un seul nombre — c'est ce qui rend un
-- palier interprétable. Mais une somme totale sans sa décomposition oblige à
-- créer quatre sondes pour voir quatre portefeuilles, et à en surveiller
-- quatre fois le coût. Les colonnes supplémentaires sont donc conservées et
-- affichées sous le chiffre, sans jamais participer au signalement.
alter table monitoring_probe_windows
    add column if not exists last_detail jsonb null;

comment on column monitoring_probes.timeout_ms is
    'Plafond côté Postgres, en millisecondes. 8000 par défaut ; jusqu''à 60000 '
    'pour une requête qui croise airtel_logs.';

comment on column monitoring_probes.interval_minutes is
    'Cadence d''exécution. 1 par défaut. Un cumul mensuel n''a pas besoin de '
    'tourner chaque minute.';

comment on column monitoring_probe_windows.last_detail is
    'Les colonnes rendues en plus de `valeur`, telles quelles. Affichées sous '
    'le chiffre, jamais utilisées pour décider d''un palier.';

-- L'erreur appartient à la sonde, pas à la base.
--
-- Constaté en usage : une seule sonde trop lente peignait son « statement
-- timeout » sur les dix autres cartes de la même base, qui allaient
-- parfaitement bien. On cherchait une panne partout au lieu d'une requête à un
-- seul endroit. `monitored_databases.last_error` reste, mais ne parle plus que
-- de la base elle-même — vérification de lecture seule, connexion refusée.
alter table monitoring_probes
    add column if not exists last_error text null;

comment on column monitoring_probes.last_error is
    'Le dernier échec de cette sonde. Effacé dès qu''elle réussit.';
