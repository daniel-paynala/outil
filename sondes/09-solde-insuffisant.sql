-- Solde insuffisant. Le métier qui fonctionne.
select count(*) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id ~ '(CP|MC|MC1|MC2)$'
  and response->'status'->>'success' is distinct from 'true'
  and not jsonb_exists(response, 'error')
  and coalesce(response->>'message', response->'status'->>'message') !~* 'timeout|time out|timed out|ETIMEDOUT|ESOCKETTIMEDOUT'
  and coalesce(response->>'message', response->'status'->>'message') !~* 'invalid.*transaction|transaction.*invalide'
  and coalesce(response->>'message', response->'status'->>'message') ~* 'solde.*insuffisant|insufficient|balance'
