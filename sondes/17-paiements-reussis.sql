-- Paiements réussis sur la fenêtre. La seule sonde qui compte une bonne
-- nouvelle : à lire avec la note sur l'acquittement, dans SONDES.md.
select count(*) as valeur
from payment
where created_at >= :depuis
  and status = 'SUCCESS'
