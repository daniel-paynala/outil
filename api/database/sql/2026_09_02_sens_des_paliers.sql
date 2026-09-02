-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Le sens d'une fenêtre, et le renommage qui va avec.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- ## Ce qui manquait
--
-- Toutes les sondes alertaient vers le haut. Une sonde de paiements réussis
-- réglée ainsi prévient quand la journée est bonne, et se tait quand la
-- production tombe à zéro — l'inverse exact de ce qu'on lui demande. Mesuré :
-- avec un palier à 100, la séquence 140 → 120 → 95 → 40 → 3 → 0 ne produit
-- qu'une seule notification, à 140.
--
-- ## Pourquoi `highest_tier` devient `severest_tier`
--
-- En croissant, le palier le plus grave est le plus haut franchi : 100 est
-- pire que 20. En décroissant, c'est le plus bas : tomber sous 20 est pire que
-- tomber sous 100. Une colonne nommée « le plus haut » qui contient le plus
-- bas est un piège posé pour la prochaine personne qui lira ce schéma.
--
-- Le renommage est fait maintenant parce que c'est le dernier moment où il ne
-- coûte rien : la supervision n'a pas encore d'historique.

alter table monitoring_probe_windows
    add column if not exists direction varchar(16) not null default 'croissant';

alter table monitoring_probe_windows
    drop constraint if exists monitoring_probe_windows_direction_check;

alter table monitoring_probe_windows
    add constraint monitoring_probe_windows_direction_check
    check (direction in ('croissant', 'decroissant'));

-- Renommage idempotent : ne fait rien si la colonne porte déjà le bon nom.
do $$
begin
    if exists (
        select 1 from information_schema.columns
        where table_name = 'monitoring_probe_windows'
          and column_name = 'highest_tier'
    ) and not exists (
        select 1 from information_schema.columns
        where table_name = 'monitoring_probe_windows'
          and column_name = 'severest_tier'
    ) then
        alter table monitoring_probe_windows
            rename column highest_tier to severest_tier;
    end if;
end $$;

comment on column monitoring_probe_windows.direction is
    'croissant : le danger est en haut, un compte d''erreurs qui grimpe. '
    'decroissant : le danger est en bas, une mesure de santé qui s''effondre.';

comment on column monitoring_probe_windows.severest_tier is
    'Le palier le plus grave signalé depuis le dernier acquittement. Le plus '
    'haut en croissant, le plus bas en décroissant. Zéro : aucun.';
