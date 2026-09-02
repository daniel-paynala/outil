-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : PAYNALA  —  la base surveillée, pas celle d'Arche         ║
-- ║  Tables : payment, merchant, airtel_logs, subscription…           ║
-- ║  Où : console SQL d'Arche (Supervision), ou DBeaver               ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- Pourquoi la sonde annuelle expire, alors que l'index existe.
--
-- ## Ce que les index de production ont appris
--
--   airtel_logs_created_at_idx      (created_at)                 19 MB · 41 349 lectures
--   idx_airtel_logs_request_id      (request_id)                 27 MB · 25 458 096
--   airtel_logs_mc_created_idx      (created_at) WHERE request_id ~ '_?MC[12]?$'
--   airtel_logs_mc_key_idx          (regexp_replace(...))  WHERE request_id ~ '_?MC[12]?$'
--
-- Deux choses en découlent.
--
-- **Le motif de suffixe réel est `_?MC[12]?$`** : un tiret bas facultatif, puis
-- MC, puis un 1 ou un 2 facultatif. Mon `(MC1|MC2|MC|CP)$` reconnaît les mêmes
-- chaînes — mais ce n'est pas le même *texte*, et c'est le texte qui compte.
--
-- **Quelqu'un a déjà optimisé exactement cette charge.** Les deux index
-- partiels n'existent que pour les jambes MC filtrées par date. Postgres ne
-- s'en sert que s'il peut prouver que le prédicat de la requête implique celui
-- de l'index — ce qu'il fait par comparaison de l'expression, pas par
-- raisonnement sur les expressions régulières. Écrire un motif équivalent mais
-- différent revient donc à ignorer un index taillé pour nous.
--
-- Les trois requêtes ci-dessous mesurent au lieu de supposer.


-- ── 1. Le plan actuel, celui qui expire ─────────────────────────────────
explain (analyze, buffers, timing)
select count(*)
from airtel_logs
where created_at >= now() - interval '1 year'
  and request_id ~ '(MC1|MC2|MC|CP)$';


-- ── 2. Le même compte, avec le prédicat exact de l'index partiel ────────
--
-- Ne couvre que les jambes MC — l'index partiel ne connaît qu'elles.
explain (analyze, buffers, timing)
select count(*)
from airtel_logs
where created_at >= now() - interval '1 year'
  and request_id ~ '_?MC[12]?$';


-- ── 3. Et sur une heure, pour voir si l'index sert vraiment ─────────────
--
-- Si le plan passe de « Seq Scan » sur un an à « Index Scan » sur une heure,
-- c'est le planificateur qui écarte l'index quand la fenêtre est trop large —
-- comportement normal, et qui justifie à lui seul les 45 s et la cadence lente
-- de la sonde annuelle plutôt qu'une réécriture.
explain (analyze, buffers, timing)
select count(*)
from airtel_logs
where created_at >= now() - interval '1 hour'
  and request_id ~ '(MC1|MC2|MC|CP)$';
