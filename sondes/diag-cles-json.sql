-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision) — UNE REQUÊTE À LA FOIS   ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- 2/3 — Les clés de premier niveau des deux documents.
--
-- C'est ce qui dira si `response->'status'` et `request->'transaction'`
-- existent réellement, ou si les chemins portent d'autres noms.

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
