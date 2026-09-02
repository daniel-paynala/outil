-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Time-out, quelle que soit la forme du log.
select count(*) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id ~ '(CP|MC|MC1|MC2)$'
  and response->'status'->>'success' is distinct from 'true'
  and coalesce(response->>'message', response->'status'->>'message') ~* 'timeout|time out|timed out|ETIMEDOUT|ESOCKETTIMEDOUT'
