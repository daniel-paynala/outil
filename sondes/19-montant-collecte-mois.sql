-- Montant crédité sur les trois portefeuilles — CP, MC1, MC2.
--
-- Fenêtre : mensuelle — depuis le 1er du mois.
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
-- Même raison qu'au-dessus : le 2 du mois, le cumul est forcément sous
-- n'importe quel plancher. Pour être *alerté* d'un effondrement, c'est la
-- sonde 20 qu'il faut — une fenêtre courte, en décroissant.
select cast(
    coalesce(sum(cast(request->'transaction'->>'amount' as numeric)), 0)
    as bigint
) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id ~ '(CP|MC|MC1|MC2)$'
  and response->'status'->>'success' = 'true'
