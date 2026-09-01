select count(*) as valeur
from payment p
where p.created_at >= :depuis
  and p.status = 'FAILED'
