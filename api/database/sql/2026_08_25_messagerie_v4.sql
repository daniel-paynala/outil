-- ─────────────────────────────────────────────────────────────────────────
--  Arche — messagerie v4 : réponse à un message
--  À exécuter dans l'éditeur SQL Supabase, après le v3.
--
--  Le « en train d'écrire » n'apparaît pas ici : il passe par la diffusion
--  Realtime, sans jamais toucher la base. Une frappe n'a aucune valeur une
--  seconde plus tard — la stocker ne servirait qu'à faire grossir la base.
-- ─────────────────────────────────────────────────────────────────────────

alter table messages add column if not exists reply_to_id uuid null;

do $$
begin
    if not exists (
        select 1 from pg_constraint where conname = 'messages_reply_to_id_foreign'
    ) then
        -- SET NULL et non CASCADE : supprimer un message cité ne doit pas
        -- emporter la réponse, qui garde son sens propre. La citation
        -- devient simplement orpheline, et l'app affiche « message
        -- supprimé ».
        alter table messages
            add constraint messages_reply_to_id_foreign
            foreign key (reply_to_id) references messages(id) on delete set null;
    end if;
end $$;

-- Sert à retrouver les réponses à un message donné. L'accès dominant reste
-- l'inverse (charger le message cité depuis la réponse), déjà couvert par la
-- clé primaire.
create index if not exists messages_reply_to_id_index
    on messages (reply_to_id)
    where reply_to_id is not null;
