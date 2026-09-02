-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Montant crédité, avec sa décomposition par portefeuille.
--
-- Fenêtre : totale — depuis toujours
-- Unité : « F CFA »
-- Sens : croissant, des jalons — un cumul de période est forcément bas en début de période.
--
-- `valeur` porte le total, et c'est le seul nombre sur lequel un palier se
-- décide : un seuil se lit sur un chiffre, pas sur une décomposition. Les
-- colonnes CP, MC, MC1 et MC2 sont conservées et affichées sous le total —
-- sans elles, il faudrait quatre sondes pour voir quatre portefeuilles, et en
-- payer quatre fois le coût sur une table de 890 000 lignes.
--
-- Le suffixe de l'identifiant est ce qui distingue les jambes ; `substring`
-- l'extrait une seule fois, dans la sous-requête, plutôt que de rejouer quatre
-- expressions régulières sur chaque ligne.
select
    cast(coalesce(sum(montant), 0) as bigint) as valeur,
    cast(coalesce(sum(montant) filter (where jambe = 'CP'), 0) as bigint)  as "CP",
    cast(coalesce(sum(montant) filter (where jambe = 'MC'), 0) as bigint)  as "MC",
    cast(coalesce(sum(montant) filter (where jambe = 'MC1'), 0) as bigint) as "MC1",
    cast(coalesce(sum(montant) filter (where jambe = 'MC2'), 0) as bigint) as "MC2"
from (
    select
        cast(request->'transaction'->>'amount' as numeric) as montant,
        substring(request_id from '(MC1|MC2|MC|CP)$') as jambe
    from airtel_logs
    where created_at >= :depuis
      and request_id ~ '(MC1|MC2|MC|CP)$'
      and response->'status'->>'success' = 'true'
) as jambes
