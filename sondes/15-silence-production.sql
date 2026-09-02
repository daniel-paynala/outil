-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Minutes depuis le dernier paiement. La seule sonde qui monte quand plus
-- rien n'arrive — un compteur d'échecs à zéro se lit comme un système sain.
select cast(coalesce(
    extract(epoch from (now() - max(created_at))) / 60,
    extract(epoch from (now() - cast(:depuis as timestamptz))) / 60
) as int) as valeur
from payment
where created_at >= :depuis
