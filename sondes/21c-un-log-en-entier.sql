-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision) — UNE REQUÊTE À LA FOIS   ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- 3/3 — Un log de réconciliation, en entier.
--
-- Quand les deux requêtes précédentes ne suffisent pas : voir la forme réelle
-- vaut mieux que la déduire. `request->'pin'` est écarté — c'est un secret,
-- et il ne doit jamais sortir de la base.

select
    request_id,
    created_at,
    http_code,
    response,
    request - 'pin' as request_sans_pin
from airtel_logs
where created_at >= now() - interval '7 days'
  and request_id ~ '(MC1|MC2|MC|CP)$'
order by created_at desc
limit 3;
