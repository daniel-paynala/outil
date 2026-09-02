-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Appels internes : appareils joignables par push VoIP.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- ## Ce que cette table ne contient pas
--
-- Ni appel, ni durée, ni journal. La voix va d'un téléphone à l'autre en
-- direct, la signalisation passe par le socket Supabase, et le serveur ne sert
-- qu'à **faire sonner un appareil que l'application ne peut pas atteindre** —
-- écran verrouillé, application fermée.
--
-- Cette table est donc un simple annuaire de jetons, rien de plus.

create table if not exists voip_devices (
    id            uuid primary key,
    user_id       uuid not null,

    -- Jeton PushKit de l'appareil. Distinct du jeton de notification
    -- ordinaire, et il change : réinstallation, restauration de sauvegarde,
    -- parfois mise à jour d'iOS. Unique, parce qu'un même appareil ne doit
    -- jamais sonner deux fois.
    token         varchar(255) not null unique,

    platform      varchar(10) not null,

    -- Dernier envoi réussi. Sert à repérer un appareil devenu injoignable sans
    -- qu'Apple l'ait explicitement déclaré mort.
    last_used_at  timestamp null,

    created_at    timestamp null,
    updated_at    timestamp null,

    constraint voip_devices_user_id_foreign
        foreign key (user_id) references users(id) on delete cascade
);

-- On fait sonner tous les appareils d'une personne : plusieurs par compte est
-- normal — un iPhone et un iPad, ou une réinstallation dont l'ancien jeton
-- n'est pas encore invalidé.
create index if not exists voip_devices_user_id_index
    on voip_devices (user_id);
