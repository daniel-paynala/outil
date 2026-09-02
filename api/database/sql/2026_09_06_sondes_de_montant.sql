-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
--
-- Les trois sondes de montant collecté : depuis toujours, cette année, ce mois.
--
-- ## Ce qu'il faut avoir appliqué avant
--
-- Ce fichier écrit dans des colonnes que les précédents créent. Dans l'ordre :
-- capabilities, monitoring, fenetres_calendaires, sens_des_paliers,
-- acces_par_sonde, fenetres_de_periode, cadence_et_delai. La requête de
-- vérification `2026_09_05_verification_supervision.sql` dit lesquels manquent.
--
-- ## À vérifier avant de lancer
--
-- Le nom de la base surveillée, ci-dessous. Il doit correspondre exactement à
-- ce qui s'affiche dans l'écran Supervision.
--
-- ## Rejouable
--
-- Les trois sondes sont supprimées puis recréées à chaque exécution. Leur
-- acquittement et leur dernière valeur repartent donc de zéro — ce qui est
-- voulu ici, puisqu'on les installe.
--
-- ## Les réglages, et d'où ils viennent
--
-- `timeout_ms = 45000` : mesuré. Un `count` sur un an d'`airtel_logs` prend
-- ~14,7 s ; sur une heure, 1,34 ms. Le plafond par défaut de 8 s est ce qui
-- faisait échouer la sonde annuelle.
--
-- `hours = 24` : pour un mode de période, ce champ n'est pas la durée observée
-- mais l'intervalle de rechargement. Un cumul annuel se recompte une fois par
-- jour ; le recompter chaque minute coûterait 14,7 s par minute pour un chiffre
-- qui ne bouge pas.
--
-- `direction = croissant` : les paliers sont des jalons qu'on franchit une
-- fois. Un plancher n'aurait pas de sens sur un cumul de période — le 2 du
-- mois, il est forcément bas.
--
-- ⚠ Les paliers ci-dessous sont des ordres de grandeur, pas des mesures. Je ne
-- connais pas vos volumes. Regardez ce que la sonde rend au premier tour, puis
-- ajustez depuis le dashboard.

do $$
declare
    nom_base constant text := 'BDD Agregateur';
    id_base uuid;
    id_sonde uuid;
    requete constant text := $q$select
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
) as jambes$q$;

    plan constant jsonb := jsonb_build_array(
        jsonb_build_object(
            'titre', 'Montant collecté — depuis toujours',
            'mode',  'totale',
            'paliers', '[100000000, 500000000, 1000000000, 5000000000]'::jsonb),
        jsonb_build_object(
            'titre', 'Montant collecté — cette année',
            'mode',  'annuelle',
            'paliers', '[100000000, 500000000, 1000000000]'::jsonb),
        jsonb_build_object(
            'titre', 'Montant collecté — ce mois-ci',
            'mode',  'mensuelle',
            'paliers', '[10000000, 50000000, 100000000, 250000000]'::jsonb)
    );
    entree jsonb;
begin
    select id into id_base from monitored_databases where name = nom_base;

    if id_base is null then
        raise exception
            'Aucune base surveillée nommée « % ». Corrigez `nom_base` en tête.',
            nom_base;
    end if;

    for entree in select * from jsonb_array_elements(plan) loop
        delete from monitoring_probes
        where database_id = id_base
          and title = entree->>'titre';

        id_sonde := gen_random_uuid();

        insert into monitoring_probes
            (id, database_id, title, unit, query,
             timeout_ms, interval_minutes, enabled, created_at, updated_at)
        values
            (id_sonde, id_base, entree->>'titre', 'F CFA', requete,
             45000, 1, true, now(), now());

        insert into monitoring_probe_windows
            (id, probe_id, hours, mode, direction, tiers, severest_tier)
        values
            (gen_random_uuid(), id_sonde, 24, entree->>'mode', 'croissant',
             entree->'paliers', 0);
    end loop;
end $$;

-- Ce qui vient d'être posé.
select
    p.title,
    p.unit,
    p.timeout_ms,
    w.mode,
    w.hours as rechargement_h,
    w.direction,
    w.tiers
from monitoring_probes p
join monitoring_probe_windows w on w.probe_id = p.id
where p.title like 'Montant collecté%'
order by w.mode;
