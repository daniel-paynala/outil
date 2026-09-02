-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- CP n'a laissé aucun log. Le seul cas qui exige la table payment : une
-- ligne absente ne se compte pas dans la table où elle manque.
select count(*) as valeur
from payment p
where p.created_at >= :depuis
  and p.status = 'SUCCESS'
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and not exists (
      select 1 from airtel_logs al
      where al.request_id = case
          when p.channel = 'USSD' then p.airtel_money_id || 'CP'
          else p.request_id || '_CP'
      end
  )
