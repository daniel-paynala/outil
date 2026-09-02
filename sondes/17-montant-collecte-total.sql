-- Montant crédité sur les trois portefeuilles — CP, MC1, MC2.
--
-- Fenêtre : totale — depuis toujours.
-- Unité : « F CFA » · Sens : croissant, des jalons.
--
-- Le montant d'une jambe vit dans le log Airtel, pas dans `payment` :
-- `request->'transaction'->>'amount'`, exactement d'où le tableau de bord le
-- tire pour ses rapports de bénéficiaires. Les trois jambes s'additionnent :
-- ce sont trois portefeuilles distincts, pas trois vues du même montant.
--
-- Seules les jambes réussies comptent : une réconciliation en échec n'a rien
-- crédité, et l'inclure ferait afficher de l'argent qui n'est jamais arrivé.
--
-- Les paliers sont des jalons qu'on franchit une fois : 100 M, 500 M, 1 Md. Ce
-- n'est pas une alarme, c'est une nouvelle qu'on veut recevoir.
select cast(
    coalesce(sum(cast(request->'transaction'->>'amount' as numeric)), 0)
    as bigint
) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id ~ '(CP|MC|MC1|MC2)$'
  and response->'status'->>'success' = 'true'
