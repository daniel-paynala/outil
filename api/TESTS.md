# Tests de l'API

```bash
composer test
```

## Pourquoi cette commande et pas `php artisan test`

Les deux exécutent la même suite. Mais `artisan test` rapporte les
dépréciations quelle que soit leur origine, et le squelette de Laravel en émet
une à chaque test sur PHP 8.5 (`PDO::MYSQL_ATTR_SSL_CA`, dans un fichier de
configuration du framework qu'on ne peut pas modifier). Le résultat est une
suite qui affiche « 10 deprecated » alors que les dix tests passent — une
alerte qui se déclenche toujours cesse d'être une alerte, et masque celles qui
comptent.

`phpunit` respecte `ignoreIndirectDeprecations` dans `phpunit.xml` : seules les
dépréciations venant de `app/`, `routes/` et `database/migrations/` sont
rapportées. C'est ce qu'on veut voir.

## La base de test

SQLite en mémoire (`phpunit.xml`). Ni Docker ni serveur PostgreSQL ne sont
installés sur les postes de l'équipe, et pointer les tests vers Supabase serait
dangereux autant que lent : `RefreshDatabase` y viderait la production, et
chaque requête coûte 160 ms depuis Libreville.

**Limite à garder en tête.** SQLite ne reproduit pas les comportements propres à
PostgreSQL. Le bug de liaison des booléens qui a cassé la création de projet en
production — `is_done` reçu comme entier sur une colonne `boolean`, à cause de
`PDO::ATTR_EMULATE_PREPARES` imposé par le pooler Supabase — **ne se rejouerait
pas ici**. La suite couvre les routes, les autorisations, la validation et la
logique métier ; elle ne couvre pas le dialecte SQL.

Pour couvrir aussi ce terrain il faudrait un PostgreSQL local
(`brew install postgresql@17`) et une seconde configuration de connexion. Cela
reste à faire, et c'est la seule chose qui manque pour que la suite soit
représentative.

## Migrations et SQL : pourquoi les deux existent

`database/migrations/` ne sert **qu'à monter le schéma de cette suite** sur
SQLite. La production, elle, est du PostgreSQL chez Supabase, et son schéma
s'applique à la main depuis `database/sql/`.

Ce n'est pas de la duplication gratuite : sans migrations, aucun test ne peut
démarrer ; sans SQL, la production ne peut pas être mise à jour de la façon dont
elle l'est réellement. Les deux décrivent la même chose, et **si l'une change,
l'autre doit suivre** — la sonde `/api/monitoring/integrity` signale d'ailleurs
toute table présente en base sans migration correspondante.

## L'authentification dans les tests

`Tests\TestCase::authenticate()` crée un compte et forge un vrai JWT signé avec
le secret de `phpunit.xml`. Le middleware `EnsureSupabaseAuth` est donc exercé
tel quel — vérification de signature, synchronisation du compte, mise en cache
de l'instantané. Le neutraliser aurait laissé sans couverture la couche que
traverse *chaque* requête de l'app, et donc celle où une régression touche tous
les écrans d'un coup.
