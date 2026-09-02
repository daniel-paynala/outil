-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- CP a répondu non. Une seule table, aucune jointure.
select count(*) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id like '%CP'
  and response->'status'->>'success' is distinct from 'true'
