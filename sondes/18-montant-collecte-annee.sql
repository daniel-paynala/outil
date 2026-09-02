-- Montant crédité sur les trois portefeuilles — CP, MC1, MC2.
--
-- Fenêtre : annuelle — depuis le 1er janvier.
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
-- Croissant et non décroissant : un cumul depuis le 1er janvier est
-- forcément bas en février. Un plancher annuel sonnerait tous les débuts
-- d'année sans qu'il se soit rien passé.
select cast(
    coalesce(sum(cast(request->'transaction'->>'amount' as numeric)), 0)
    as bigint
) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id ~ '(CP|MC|MC1|MC2)$'
  and response->'status'->>'success' = 'true'
