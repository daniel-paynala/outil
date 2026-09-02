-- ⚠ À exécuter UNE FOIS sur la base Paynala, avec un compte qui peut écrire.
--    C'est la vraie cure du « statement timeout », pas un réglage d'Arche.
--
-- ## Le constat
--
-- Onze sondes rendaient toutes :
--
--     SQLSTATE[57014] : canceling statement due to statement timeout
--
-- Chaque sonde filtre `airtel_logs` sur `created_at`. Sans index, Postgres
-- parcourt les ~890 000 lignes en entier — à chaque sonde, à chaque minute.
-- Onze parcours complets par minute sur une base de production.
--
-- Avec l'index, une fenêtre d'une heure ne lit que les lignes de cette heure.
-- Une fenêtre annuelle reste lourde, mais devient une lecture ordonnée au lieu
-- d'un balayage plus un tri.
--
-- `concurrently` : la création ne pose pas de verrou d'écriture sur la table.
-- Elle est plus lente, mais la production continue de journaliser pendant ce
-- temps. À lancer hors transaction — l'éditeur SQL de Supabase le permet.

create index concurrently if not exists airtel_logs_created_at_index
    on airtel_logs (created_at);

-- Ce que l'index a changé, à relancer avant et après :
--
--     explain (analyze, buffers)
--     select count(*) from airtel_logs
--     where created_at >= now() - interval '1 hour';
--
-- « Seq Scan » avant, « Index Scan » après. Si le plan ne change pas, c'est que
-- le planificateur juge la fenêtre trop large pour l'index — ce qui arrive
-- légitimement sur la sonde annuelle, et justifie à elle seule son délai plus
-- long et sa cadence plus lente.

-- Et l'index qui sert au reste : la réconciliation cherche par identifiant.
-- Il existe probablement déjà — le tableau de bord Paynala compte dessus pour
-- ses jointures latérales. À vérifier plutôt qu'à créer à l'aveugle :
--
--     select indexname, indexdef from pg_indexes where tablename = 'airtel_logs';
