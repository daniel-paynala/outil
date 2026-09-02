-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- MC2 a répondu non — Rengus Digital uniquement.
select count(*) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id like '%MC2'
  and response->'status'->>'success' is distinct from 'true'
