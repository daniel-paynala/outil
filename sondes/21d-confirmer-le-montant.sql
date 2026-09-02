-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision) — UNE REQUÊTE À LA FOIS   ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- La requête de la sonde, telle quelle, sur 24 h.
--
-- Seule différence avec ce qui tourne en production : `:depuis` remplacé par
-- une date littérale, parce que la console ne lie aucun paramètre. Le reste est
-- identique au caractère près.
--
-- L'entonnoir a montré que 2 520 lignes sur 2 657 passent les trois filtres.
-- Cette requête dit ce qu'elles pèsent.

select
    cast(coalesce(sum(montant), 0) as bigint) as valeur,
    cast(coalesce(sum(montant) filter (where jambe = 'CP'), 0) as bigint)  as "CP",
    cast(coalesce(sum(montant) filter (where jambe = 'MC'), 0) as bigint)  as "MC",
    cast(coalesce(sum(montant) filter (where jambe = 'MC1'), 0) as bigint) as "MC1",
    cast(coalesce(sum(montant) filter (where jambe = 'MC2'), 0) as bigint) as "MC2",
    count(*) as jambes_comptees
from (
    select
        cast(request->'transaction'->>'amount' as numeric) as montant,
        substring(request_id from '(MC1|MC2|MC|CP)$') as jambe
    from airtel_logs
    where created_at >= now() - interval '24 hours'
      and request_id ~ '(MC1|MC2|MC|CP)$'
      and response->'status'->>'success' = 'true'
) as jambes;
