-- Connexion coupée : socket, DNS, refus de connexion.
select count(*) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id ~ '(CP|MC|MC1|MC2)$'
  and response->'status'->>'success' is distinct from 'true'
  and jsonb_exists(response, 'error')
  and coalesce(response->>'message', response->'status'->>'message') !~* 'timeout|time out|timed out|ETIMEDOUT|ESOCKETTIMEDOUT'
  and coalesce(response->>'message', response->'status'->>'message') ~* 'socket hang up|ECONNRESET|ECONNREFUSED|ENETUNREACH|EAI_AGAIN|EHOSTUNREACH'
