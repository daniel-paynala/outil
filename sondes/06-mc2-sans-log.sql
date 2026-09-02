-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- MC2 n'a laissé aucun log — Rengus Digital.
select count(*) as valeur
from payment p
join merchant m on m.id = p.merchant_id
where p.created_at >= :depuis
  and p.status = 'SUCCESS'
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and m.name = 'Rengus Digital'
  and not exists (
      select 1 from airtel_logs al
      where al.request_id = case
          when p.channel = 'USSD' then p.airtel_money_id || 'MC2'
          else p.request_id || '_MC2'
      end
  )
