-- Signature de courrier, propre à chaque personne.
--
-- À appliquer dans l'éditeur SQL de Supabase.
--
-- ## Pourquoi côté serveur et non sur l'appareil
--
-- Une signature suit la personne, pas le téléphone. La garder localement la
-- ferait disparaître à la réinstallation, et différer entre l'iPhone et l'iPad
-- du même compte — deux réponses au même correspondant, signées autrement.
--
-- ## Pourquoi nullable plutôt qu'un défaut en base
--
-- `null` veut dire « jamais réglée » et se distingue d'une chaîne vide, qui
-- veut dire « je n'en veux pas ». L'application n'ajoute « Envoyé depuis
-- Arche » que dans le premier cas : imposer un défaut ici rendrait les deux
-- indiscernables, et on ne pourrait plus retirer la mention.

alter table users
    add column if not exists mail_signature text null;

comment on column users.mail_signature is
    'Signature ajoutée aux courriers envoyés depuis Arche. NULL = jamais '
    'réglée, l''app propose alors « Envoyé depuis Arche ». Chaîne vide = '
    'refusée explicitement, rien n''est ajouté.';
