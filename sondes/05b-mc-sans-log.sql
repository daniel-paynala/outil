-- MC n'a laissé aucun log — tous les marchands sauf Rengus Digital.
select count(*) as valeur
from payment p
where p.created_at >= :depuis
  and p.status = 'SUCCESS'
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and not exists (
      select 1 from airtel_logs al
      where al.request_id = case
          when p.channel = 'USSD' then p.airtel_money_id || 'MC'
          else p.request_id || '_MC'
      end
  )
