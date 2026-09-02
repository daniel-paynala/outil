-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Onglet Mail : rattachement des boîtes Google Workspace.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- ## Ce que cette table contient, et ce qu'elle ne contient pas
--
-- Elle porte un jeton de rafraîchissement, c'est-à-dire un accès permanent à la
-- boîte de quelqu'un. C'est la donnée la plus sensible de l'installation : elle
-- est chiffrée par l'application avant écriture (cast `encrypted`, clé
-- `APP_KEY`), et jamais rendue par l'API.
--
-- En revanche **aucun courrier n'y est stocké**. L'app parle à Gmail
-- directement pour lire et écrire ; le serveur ne se sert de son jeton que pour
-- relever les arrivées toutes les deux minutes et composer le titre d'une
-- notification, sans rien conserver.
--
-- ## Attention à l'APP_KEY
--
-- Changer l'`APP_KEY` de Laravel rend tous les jetons de cette table
-- illisibles : la relève s'arrêtera et chacun devra rattacher sa boîte à
-- nouveau. Rien n'est perdu — aucun courrier n'est ici — mais c'est à savoir
-- avant, pas après.

create table if not exists google_accounts (
    id                uuid primary key,

    -- Une boîte par personne. Gérer plusieurs comptes multiplierait les relèves
    -- et les cas limites (laquelle notifie ? laquelle répond ?) pour un besoin
    -- que personne n'a exprimé.
    user_id           uuid not null unique,

    -- Adresse rattachée, en clair : elle s'affiche dans l'app pour qu'on sache
    -- quelle boîte est connectée. Toujours en minuscules — l'application
    -- normalise avant écriture, et les comparaisons en dépendent.
    email             varchar(255) not null,

    -- Chiffré par l'application. Une fuite de la base seule ne donne rien.
    refresh_token     text not null,

    -- Portées réellement accordées, qui peuvent être plus étroites que celles
    -- demandées si la personne en a décoché.
    scopes            text null,

    -- Curseur de l'historique Gmail : un numéro de version de la boîte. On
    -- demande à Google ce qui a changé depuis, puis on le fait avancer.
    history_id        varchar(40) null,

    -- Heure de la dernière relève réussie.
    --
    -- C'est le seul témoin que la boucle tourne. La veille poussée de Gmail,
    -- écartée ici, aurait eu un état caché : elle expire au bout de sept jours
    -- et s'éteint sans rien dire. Cette colonne, elle, cesse simplement
    -- d'avancer — et l'écran de réglages le montre.
    last_polled_at    timestamp null,

    -- Dernier échec de relève. Rendu par `/api/mail/status` : une autorisation
    -- révoquée côté Google ne se remarquerait autrement que par l'absence de
    -- notifications, des jours plus tard.
    last_error        text null,
    last_error_at     timestamp null,

    created_at        timestamp null,
    updated_at        timestamp null,

    constraint google_accounts_user_id_foreign
        foreign key (user_id) references users(id) on delete cascade
);

-- La relève balaie par cette colonne.
create index if not exists google_accounts_last_polled_at_index
    on google_accounts (last_polled_at);

-- Cinquième catégorie de notification.
--
-- Séparée des quatre autres parce que son volume est sans commune mesure : une
-- boîte professionnelle reçoit en une matinée ce qu'un projet Arche produit en
-- une semaine. La couper doit être possible sans renoncer aux notifications de
-- tâches.
--
-- Activée par défaut, comme les autres : quelqu'un qui rattache sa boîte le
-- fait pour être prévenu. Le silence ne se choisit pas par omission.
alter table users add column if not exists notify_mail boolean not null default true;
