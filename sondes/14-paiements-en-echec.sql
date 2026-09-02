-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Niveau paiement, pas réconciliation. La sonde la moins chère.
select count(*) as valeur
from payment
where created_at >= :depuis
  and status = 'FAILED'
