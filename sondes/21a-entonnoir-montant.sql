-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision) — UNE REQUÊTE À LA FOIS   ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- 1/3 — À quelle étape la sonde de montant perd-elle ses lignes ?
--
-- Chaque colonne est un filtre de plus. La première qui tombe à zéro désigne
-- l'hypothèse fausse. Le suffixe est déjà confirmé par les index de
-- production ; restent les deux chemins JSON.

select
    count(*)                                                    as lignes_24h,
    count(*) filter (where request_id ~ '(MC1|MC2|MC|CP)$')     as avec_suffixe,
    count(*) filter (where jsonb_exists(response, 'status'))     as reponse_a_status,
    count(*) filter (where response->'status'->>'success' = 'true') as succes,
    count(*) filter (where jsonb_exists(request, 'transaction')) as requete_a_transaction,
    count(*) filter (where request->'transaction'->>'amount' is not null) as avec_montant,
    count(*) filter (
        where request_id ~ '(MC1|MC2|MC|CP)$'
          and response->'status'->>'success' = 'true'
          and request->'transaction'->>'amount' is not null)    as les_trois
from airtel_logs
where created_at >= now() - interval '24 hours';
