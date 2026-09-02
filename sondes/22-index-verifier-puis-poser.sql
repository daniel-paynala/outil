-- Y a-t-il déjà un index utile sur `airtel_logs` ?
--
-- À lancer sur la base Paynala. Les deux premières requêtes ne modifient rien.
-- La troisième est commentée : on ne la décommente que si le verdict le dit.
--
-- ## Pourquoi vérifier plutôt que poser directement
--
-- `create index if not exists` ne teste que le **nom**. Un index qui couvre
-- déjà `created_at` sous un autre nom passerait au travers, et on en créerait
-- un doublon : deux fois le coût à chaque écriture de journal, deux fois la
-- place sur disque, pour rien.
--
-- ## Ce qui compte est la colonne de tête
--
-- Un index sur `(request_id, created_at)` n'aide **pas** un filtre
-- `created_at >= …` : Postgres ne peut s'en servir que s'il connaît d'abord
-- `request_id`. Seule la première colonne de l'index compte ici, d'où la
-- lecture de `indkey[0]` plutôt qu'un simple `like '%created_at%'` sur la
-- définition, qui aurait dit « oui » à tort.


-- ── 1. Le verdict, en une ligne ─────────────────────────────────────────
select
    case
        when count(*) > 0
            then 'INDEX DÉJÀ PRÉSENT — ne rien créer, passer à l''étape 2'
        else 'AUCUN INDEX EN TÊTE SUR created_at — l''étape 3 est justifiée'
    end as verdict,
    count(*) as index_utiles
from pg_index ix
join pg_class c on c.oid = ix.indexrelid
join pg_class t on t.oid = ix.indrelid
where t.relname = 'airtel_logs'
  and (
      select attname
      from pg_attribute
      where attrelid = ix.indrelid
        and attnum = ix.indkey[0]
  ) = 'created_at';


-- ── 2. Tout ce que la table porte déjà, et ce que ça sert ───────────────
--
-- `idx_scan` à zéro sur un index volumineux est un index qu'on paie à chaque
-- écriture sans que personne ne le lise.
select
    c.relname as index_nom,
    (select attname
       from pg_attribute
      where attrelid = ix.indrelid and attnum = ix.indkey[0]) as colonne_de_tete,
    pg_size_pretty(pg_relation_size(c.oid)) as taille,
    coalesce(s.idx_scan, 0) as lectures,
    pg_get_indexdef(c.oid) as definition
from pg_index ix
join pg_class c on c.oid = ix.indexrelid
join pg_class t on t.oid = ix.indrelid
left join pg_stat_user_indexes s on s.indexrelid = c.oid
where t.relname = 'airtel_logs'
order by pg_relation_size(c.oid) desc;


-- ── 3. À décommenter seulement si l'étape 1 dit qu'il est justifié ──────
--
-- `concurrently` ne pose pas de verrou d'écriture : la création est plus lente,
-- mais la production continue de journaliser pendant ce temps. À lancer hors
-- transaction — l'éditeur SQL de Supabase le permet.
--
-- create index concurrently if not exists airtel_logs_created_at_index
--     on airtel_logs (created_at);


-- ── 4. Ce que ça aura changé, à relancer avant et après ─────────────────
--
-- explain (analyze, buffers)
-- select count(*) from airtel_logs
-- where created_at >= now() - interval '1 hour';
--
-- « Seq Scan » avant, « Index Scan » après. Si le plan ne change pas, c'est que
-- le planificateur juge la fenêtre trop large pour l'index — ce qui arrive
-- légitimement sur la sonde annuelle, et justifie à elle seule son délai plus
-- long et sa cadence plus lente.
