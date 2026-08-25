-- ─────────────────────────────────────────────────────────────────────────
--  Arche — messagerie v2 : pièces jointes et temps réel
--  À exécuter dans l'éditeur SQL Supabase, après 2026_08_25_messagerie.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ── Pièces jointes ───────────────────────────────────────────────────────
-- Calquée sur `card_attachments`, déjà en place : mêmes colonnes, mêmes noms.
-- Le fichier lui-même vit dans Supabase Storage ; `path` y pointe.
create table if not exists message_attachments (
    id          uuid primary key,
    message_id  uuid not null,

    -- Chemin dans le bucket, pas une URL : les URL sont signées à la demande
    -- et expirent, les stocker n'aurait aucun sens.
    path        varchar(500) not null,

    -- Nom d'origine, pour l'affichage et le téléchargement.
    name        varchar(255) not null,
    size_bytes  bigint null,
    mime_type   varchar(120) null,

    -- Cohérent avec messages.user_id : un compte supprimé ne fait pas
    -- disparaître la pièce jointe d'un fil partagé.
    uploaded_by uuid null,
    created_at  timestamp null,

    constraint message_attachments_message_id_foreign
        foreign key (message_id) references messages(id) on delete cascade,
    constraint message_attachments_uploaded_by_foreign
        foreign key (uploaded_by) references users(id) on delete set null
);

create index if not exists message_attachments_message_id_index
    on message_attachments (message_id);

alter table message_attachments enable row level security;

-- ── Temps réel ───────────────────────────────────────────────────────────
-- Jusqu'ici, RLS était activée sans politique : un mur, et Laravel passait
-- outre en `postgres`. Pour que l'app reçoive les messages par websocket, il
-- faut au contraire que le rôle `authenticated` puisse LIRE — et seulement
-- ce à quoi il a droit.
--
-- Realtime applique ces politiques : un message n'est diffusé qu'aux membres
-- de sa conversation. La lecture directe est donc ouverte, mais l'écriture
-- reste interdite : elle continue de passer par l'API, qui tient les règles
-- métier (remontée de last_message_at, position de lecture, quotas).

drop policy if exists "membres lisent les messages" on messages;
create policy "membres lisent les messages" on messages
    for select to authenticated
    using (
        exists (
            select 1 from conversation_members cm
            where cm.conversation_id = messages.conversation_id
              and cm.user_id = auth.uid()
        )
    );

drop policy if exists "membres lisent leurs conversations" on conversations;
create policy "membres lisent leurs conversations" on conversations
    for select to authenticated
    using (
        exists (
            select 1 from conversation_members cm
            where cm.conversation_id = conversations.id
              and cm.user_id = auth.uid()
        )
    );

drop policy if exists "membres lisent la composition" on conversation_members;
create policy "membres lisent la composition" on conversation_members
    for select to authenticated
    using (
        exists (
            select 1 from conversation_members mine
            where mine.conversation_id = conversation_members.conversation_id
              and mine.user_id = auth.uid()
        )
    );

drop policy if exists "membres lisent les pieces jointes" on message_attachments;
create policy "membres lisent les pieces jointes" on message_attachments
    for select to authenticated
    using (
        exists (
            select 1
            from messages m
            join conversation_members cm on cm.conversation_id = m.conversation_id
            where m.id = message_attachments.message_id
              and cm.user_id = auth.uid()
        )
    );

-- Diffusion des changements. Sans cette ligne, les politiques ci-dessus ne
-- servent qu'aux lectures REST : Realtime ne publie que les tables inscrites.
alter publication supabase_realtime add table messages;

-- ── Bucket des pièces jointes ────────────────────────────────────────────
-- Privé, plafonné à 10 MB, et surtout : liste blanche de types. La vidéo est
-- exclue par construction, pas seulement par la validation Laravel — un
-- client qui contournerait l'API se heurterait quand même au bucket.
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
    'messagerie', 'messagerie', false, 10485760,
    array[
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic',
        'application/pdf',
        'text/plain', 'text/csv', 'text/markdown',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip'
    ]
)
on conflict (id) do update set
    file_size_limit = excluded.file_size_limit,
    allowed_mime_types = excluded.allowed_mime_types;
