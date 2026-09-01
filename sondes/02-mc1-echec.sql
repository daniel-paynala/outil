-- MC1 a répondu non — Rengus Digital, qui seul porte ce suffixe.
select count(*) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id like '%MC1'
  and response->'status'->>'success' is distinct from 'true'
