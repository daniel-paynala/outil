-- Montant crédité sur les trois portefeuilles — CP, MC1, MC2.
--
-- Fenêtre : 1 h glissante.
-- Unité : « F CFA » · Sens : DÉCROISSANT — le danger est en bas.
--
-- Le montant d'une jambe vit dans le log Airtel, pas dans `payment` :
-- `request->'transaction'->>'amount'`, exactement d'où le tableau de bord le
-- tire pour ses rapports de bénéficiaires. Les trois jambes s'additionnent :
-- ce sont trois portefeuilles distincts, pas trois vues du même montant.
--
-- Seules les jambes réussies comptent : une réconciliation en échec n'a rien
-- crédité, et l'inclure ferait afficher de l'argent qui n'est jamais arrivé.
--
-- C'est celle qui alerte vraiment. Les trois précédentes sont des compteurs :
-- utiles à lire, incapables de prévenir. Sur une heure glissante, un montant
-- qui tombe sous le plancher habituel signale un incident tout de suite, sans
-- attendre la fin du mois.
--
-- Ses planchers dépendent du volume réel : à régler après observation.
select cast(
    coalesce(sum(cast(request->'transaction'->>'amount' as numeric)), 0)
    as bigint
) as valeur
from airtel_logs
where created_at >= :depuis
  and request_id ~ '(CP|MC|MC1|MC2)$'
  and response->'status'->>'success' = 'true'
