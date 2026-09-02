-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision) — délai : 60 secondes      ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- Les vrais volumes, pour poser les paliers sur des mesures.
--
-- ## Pourquoi cette requête
--
-- Mes paliers ont été posés à l'aveugle : 10 M / 50 M / 100 M / 250 M pour le
-- mois. Or 24 h pèsent 11,4 M — un mois en fera donc ~340 M, et les quatre
-- jalons seraient tous franchis avant le 22. Quatre notifications dans les
-- trois premières semaines, puis plus rien : une échelle qui ne dit plus rien
-- passé le début du mois.
--
-- Note : `::` serait toléré ici — la console ne lie aucun paramètre — mais on
-- écrit `cast(… as …)` quand même. Une requête de console finit toujours par
-- être recopiée dans une sonde, et là le `::` se ferait prendre pour un
-- paramètre nommé.
--
-- ⚠ Choisir 60 secondes dans le sélecteur de délai : le cumul depuis toujours
-- balaie tout l'historique, et un an prend déjà ~14,7 s.

select
    cast(coalesce(sum(montant) filter (
        where cree_le >= date_trunc('month', now())), 0) as bigint)  as ce_mois,
    cast(coalesce(sum(montant) filter (
        where cree_le >= date_trunc('year', now())), 0) as bigint)   as cette_annee,
    cast(coalesce(sum(montant), 0) as bigint)                        as depuis_toujours,
    cast(min(cree_le) as date)                                       as premiere_ligne,
    cast(coalesce(sum(montant) filter (
        where cree_le >= now() - interval '30 days'), 0) / 30 as bigint) as moyenne_par_jour
from (
    select
        created_at as cree_le,
        cast(request->'transaction'->>'amount' as numeric) as montant
    from airtel_logs
    where request_id ~ '(MC1|MC2|MC|CP)$'
      and response->'status'->>'success' = 'true'
) as jambes;
