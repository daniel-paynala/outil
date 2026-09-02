-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- MC a répondu non — tous les marchands sauf Rengus Digital.
select count(*) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id like '%MC'
  and response->'status'->>'success' is distinct from 'true'
