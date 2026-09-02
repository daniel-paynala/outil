-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Application des préférences de notification à la source de la donnée.
--
-- ## Pourquoi un déclencheur plutôt qu'un `if` dans le code
--
-- Les notifications d'Arche — `task.created`, `document.uploaded`,
-- `project.member.added` — sont produites par du code qui **ne figure pas dans
-- ce dépôt** : il tourne en production sans avoir jamais été versionné, et les
-- routes `/api/notifications` que l'app mobile appelle n'existent nulle part
-- dans `routes/api.php`.
--
-- Conséquence concrète : les colonnes `notify_projects`, `notify_tasks` et
-- `notify_task_assignment`, ajoutées le 26/08/2026, ne sont lues par personne.
-- L'écran de réglages de l'app propose donc trois interrupteurs qui ne coupent
-- rien — ce qui est pire qu'une case absente, puisqu'on croit avoir choisi.
--
-- Poser le filtre sur la table ferme le trou quel que soit le producteur :
-- code versionné, code de production, script d'administration, insertion à la
-- main. La préférence cesse d'être une politesse que chaque appelant doit
-- penser à respecter.
--
-- ## Principe : on ne laisse tomber que ce qu'on a positivement reconnu
--
-- Un type inconnu passe toujours. Une notification perdue par excès de zèle
-- serait invisible et indébogable — exactement la classe de panne qu'on passe
-- ses journées à fermer. Le silence n'est acceptable que lorsqu'il a été
-- demandé.

create or replace function notifications_respect_preferences()
returns trigger
language plpgsql
as $$
declare
    preference text;
    wanted     boolean;
begin
    -- Correspondance type → préférence. Le préfixe suffit : `task.created`,
    -- `task.assigned` et tout futur `task.*` relèvent de la même case, sans
    -- qu'il faille revenir ici à chaque nouveau type.
    preference := case
        -- Une mention passe toujours, quelles que soient les préférences.
        --
        -- Ce n'est pas un oubli : être nommé dans un commentaire est une
        -- demande adressée, pas une information de plus sur un projet. La
        -- taire parce que « notifications de tâches » est décoché reviendrait
        -- à casser la seule chose qui distingue une mention d'un commentaire
        -- ordinaire.
        --
        -- Explicite plutôt que laissée au repli des types inconnus : le
        -- comportement est le même, la différence est qu'il est décidé.
        -- Une alerte de supervision passe toujours. Elle signale qu'une base
        -- de production va mal, à quelqu'un dont c'est précisément le rôle. La
        -- taire parce qu'une case de préférence est décochée reviendrait à
        -- désarmer le seul dispositif qui prévienne.
        when new.type = 'monitoring.alert'     then null
        when new.type = 'card.mentioned'       then null
        when new.type like 'message.%'         then 'notify_messages'
        when new.type like 'task.assigned'     then 'notify_task_assignment'
        when new.type like 'task.unassigned'   then 'notify_task_assignment'
        when new.type like 'task.%'            then 'notify_tasks'
        when new.type like 'project.%'         then 'notify_projects'
        when new.type like 'document.%'        then 'notify_projects'
        when new.type like 'decision.%'        then 'notify_projects'
        else null
    end;

    -- Type non reconnu : on laisse passer. Voir la note d'en-tête.
    if preference is null then
        return new;
    end if;

    execute format('select %I from users where id = $1', preference)
        into wanted
        using new.user_id;

    -- Destinataire inconnu, ou colonne nulle : on laisse passer plutôt que de
    -- perdre l'information sur une donnée incomplète.
    if wanted is null or wanted then
        return new;
    end if;

    -- Refusée : l'insertion est abandonnée sans erreur. L'appelant n'a rien à
    -- gérer, et rien ne casse chez le producteur non versionné.
    return null;
end;
$$;

drop trigger if exists notifications_respect_preferences on notifications;

create trigger notifications_respect_preferences
    before insert on notifications
    for each row
    execute function notifications_respect_preferences();

comment on function notifications_respect_preferences() is
    'Applique les colonnes notify_* de users aux insertions dans notifications. '
    'Posé sur la table parce que le code producteur n''est pas versionné — voir '
    'database/sql/2026_08_27_preferences_trigger.sql.';
