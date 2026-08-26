-- ─────────────────────────────────────────────────────────────────────────
--  Arche — préférences de notification, par catégorie
--  À exécuter dans l'éditeur SQL Supabase.
--
--  `users` porte déjà `notify_project_document_email` et
--  `notify_task_assignment_email`, qui gouvernent l'**email** et sont à
--  `false` par défaut. Les colonnes ci-dessous gouvernent les notifications
--  **push et in-app**, et sont à `true` : une messagerie dont les alertes
--  sont éteintes par défaut n'alerte personne, et l'utilisateur ne pense pas
--  à aller les allumer.
--
--  Une colonne par catégorie plutôt qu'un `jsonb` de préférences : les
--  requêtes qui filtrent les destinataires deviennent de simples `where`,
--  indexables, et un ajout de catégorie reste une migration lisible.
-- ─────────────────────────────────────────────────────────────────────────

alter table users add column if not exists
    notify_messages boolean not null default true;

alter table users add column if not exists
    notify_projects boolean not null default true;

alter table users add column if not exists
    notify_tasks boolean not null default true;

alter table users add column if not exists
    notify_task_assignment boolean not null default true;

comment on column users.notify_messages is
    'Nouveau message dans une conversation dont l''utilisateur est membre.';
comment on column users.notify_projects is
    'Vie du projet : membre ajouté, document déposé.';
comment on column users.notify_tasks is
    'Création et évolution des tâches d''un projet.';
comment on column users.notify_task_assignment is
    'Une tâche est assignée à l''utilisateur, ou lui est retirée.';
