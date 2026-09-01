-- Niveau paiement, pas réconciliation. La sonde la moins chère.
select count(*) as valeur
from payment
where created_at >= :depuis
  and status = 'FAILED'
