-- PAS UNE SONDE : à lancer dans DBeaver ou l'éditeur Supabase de la base
-- Paynala. Elle dit à quelle étape la sonde de montant perd ses lignes.
--
-- Chaque colonne est un filtre de plus. La première qui tombe à zéro est celle
-- dont l'hypothèse est fausse — les motifs de suffixe et les chemins JSON ont
-- été déduits de `filters.js`, jamais vérifiés sur les données réelles.

select
    count(*)                                                   as lignes_24h,
    count(*) filter (where request_id ~ '(CP|MC|MC1|MC2)$')    as avec_suffixe,
    count(*) filter (where jsonb_exists(response, 'status'))    as reponse_status,
    count(*) filter (where response->'status'->>'success' = 'true') as succes,
    count(*) filter (where jsonb_exists(request, 'transaction')) as requete_transaction,
    count(*) filter (where request->'transaction'->>'amount' is not null) as avec_montant,
    count(*) filter (
        where request_id ~ '(CP|MC|MC1|MC2)$'
          and response->'status'->>'success' = 'true'
          and request->'transaction'->>'amount' is not null)   as les_trois
from airtel_logs
where created_at >= now() - interval '24 hours';

-- À quoi ressemblent réellement les identifiants ? Le suffixe est la seule
-- chose qui distingue une jambe de réconciliation d'un log de paiement.
select
    right(request_id, 4) as fin_identifiant,
    count(*) as occurrences
from airtel_logs
where created_at >= now() - interval '7 days'
group by 1
order by occurrences desc
limit 20;

-- Et les clés de premier niveau des deux documents, pour vérifier les chemins.
select 'response' as document, k as cle, count(*) as occurrences
from airtel_logs, lateral jsonb_object_keys(response) as k
where created_at >= now() - interval '7 days'
group by 1, 2
union all
select 'request', k, count(*)
from airtel_logs, lateral jsonb_object_keys(request) as k
where created_at >= now() - interval '7 days'
group by 1, 2
order by document, occurrences desc;
