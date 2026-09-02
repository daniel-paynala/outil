-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  BASE : ARCHE  —  éditeur SQL du Supabase du projet Arche         ║
-- ║  Tables : users, projects, monitoring_*, user_capabilities…       ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Rapatrie en UTC les horodatages écrits à l'heure de Paris.
--
-- ## Pourquoi ils y sont
--
-- `deploy/api.env.example` portait `APP_TIMEZONE=Europe/Paris`. Le `.env` du
-- serveur en descend, et `now()` a donc écrit l'heure de Paris dans des
-- colonnes `timestamp` **sans** fuseau — qui, par définition, ne disent pas
-- laquelle elles portent.
--
-- ## Pourquoi cette conversion est obligatoire, et pas facultative
--
-- Tant que l'application tourne à Paris, elle relit ces valeurs comme de
-- l'heure de Paris : c'est cohérent, et l'API les sérialise correctement avec
-- leur décalage. Le jour où l'on bascule le serveur en UTC, la même valeur est
-- relue comme de l'UTC, et deux heures apparaissent sur tout l'historique.
--
-- Autrement dit : basculer sans convertir ne corrige pas un défaut, il en
-- crée un. Les deux opérations vont ensemble.
--
-- ## Quand l'exécuter
--
-- **Conteneur API arrêté.** Une fois converti, une écriture encore en heure de
-- Paris redeviendrait fausse, et rien ne permettrait plus de la distinguer des
-- autres — les anciennes valeurs et les nouvelles ont exactement la même
-- forme.
--
--     docker compose -f deploy/docker-compose.prod.yml stop api queue-worker scheduler
--     -- exécuter ce fichier dans l'éditeur SQL Supabase
--     -- puis remettre APP_TIMEZONE=UTC et relancer
--
-- ## Pourquoi `at time zone 'Europe/Paris'` et pas « moins deux heures »
--
-- Paris est à +02:00 en été et +01:00 en hiver. Un décalage fixe corrigerait
-- l'été et casserait tout ce qui a été écrit avant le 29 mars. Postgres connaît
-- les règles de changement d'heure ; on le laisse faire.

begin;

-- Journal des correctifs appliqués à la main. Il n'existait pas : le schéma
-- vit dans cet éditeur, sans rien pour dire ce qui y est déjà passé.
create table if not exists schema_fixes (
    name       text primary key,
    applied_at timestamptz not null default now()
);

-- Un second passage décalerait tout une seconde fois, sans que rien ne le
-- signale — le pire des résultats possibles pour un correctif de dates.
do $$
begin
    if exists (select 1 from schema_fixes where name = 'timestamps_paris_to_utc') then
        raise exception
            'Déjà appliqué le %. Ne pas rejouer : les horodatages reculeraient de deux heures de plus.',
            (select applied_at from schema_fixes where name = 'timestamps_paris_to_utc');
    end if;
end $$;

-- Toutes les colonnes sans fuseau du schéma public, découvertes plutôt
-- qu'énumérées : la liste écrite à la main aurait vieilli à la première table
-- ajoutée, et une colonne oubliée ne se voit pas — elle se contente d'être
-- fausse de deux heures.
do $$
declare
    colonne record;
    lignes  bigint;
    total   bigint := 0;
begin
    for colonne in
        select c.table_name, c.column_name
        from information_schema.columns c
        join information_schema.tables t
          on t.table_schema = c.table_schema
         and t.table_name   = c.table_name
        where c.table_schema = 'public'
          and t.table_type   = 'BASE TABLE'
          and c.data_type    = 'timestamp without time zone'
          -- Tables d'infrastructure : contenu transitoire, régénéré seul, et
          -- susceptible d'être écrit pendant l'opération.
          and c.table_name not in (
              'cache', 'cache_locks', 'jobs', 'job_batches',
              'failed_jobs', 'sessions', 'migrations', 'schema_fixes'
          )
        order by c.table_name, c.column_name
    loop
        execute format(
            'update %I set %I = (%I at time zone %L) at time zone %L where %I is not null',
            colonne.table_name, colonne.column_name, colonne.column_name,
            'Europe/Paris', 'UTC', colonne.column_name
        );

        get diagnostics lignes = row_count;
        total := total + lignes;

        raise notice '  %.% → % ligne(s)', colonne.table_name, colonne.column_name, lignes;
    end loop;

    raise notice 'Total : % valeur(s) converties de Europe/Paris vers UTC.', total;
end $$;

insert into schema_fixes (name) values ('timestamps_paris_to_utc');

commit;
