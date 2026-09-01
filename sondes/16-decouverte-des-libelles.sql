-- PAS UNE SONDE : rend des lignes, pas un nombre. À lancer dans DBeaver ou
-- l'éditeur Supabase pour connaître les libellés réels avant de figer les
-- motifs des sondes 07 à 13.
select
    coalesce(response->>'message', response->'status'->>'message') as message,
    jsonb_exists(response, 'error') as infrastructure,
    count(*) as occurrences
from airtel_logs
where created_at >= now() - interval '30 days'
  and request_id ~ '(CP|MC|MC1|MC2)$'
  and response->'status'->>'success' is distinct from 'true'
group by 1, 2
order by occurrences desc;
