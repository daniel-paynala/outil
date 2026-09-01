select cast(coalesce(
    extract(epoch from (now() - max(p.created_at))) / 60,
    extract(epoch from (now() - cast(:depuis as timestamptz))) / 60
) as int) as valeur
from payment p
where p.created_at >= :depuis
