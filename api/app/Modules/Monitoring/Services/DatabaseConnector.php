<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Monitoring\Models\MonitoredDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * L'accès aux bases surveillées.
 *
 * ## Ce qui protège la base surveillée
 *
 * L'outil ne fait que lire, mais une lecture suffit à mettre une base à genoux :
 * une jointure mal écrite sur une table d'un million de lignes consomme le
 * processeur, immobilise des connexions et ralentit la production — celle-là
 * même qu'on surveille.
 *
 * Trois garde-fous, tous imposés par nous et non par la requête :
 *
 *  - `statement_timeout` court, appliqué par Postgres lui-même ;
 *  - une transaction en lecture seule, qui refuse toute écriture au niveau du
 *    serveur même si les droits l'autorisaient ;
 *  - une connexion jetable par exécution, pour qu'une requête abandonnée
 *    n'immobilise rien.
 */
class DatabaseConnector
{
    /** Au-delà, une sonde n'est plus une sonde : c'est un rapport. */
    public const TIMEOUT_MS = 8000;

    /**
     * Exécute [$sql] en lecture seule et rend le nombre attendu.
     *
     * @param  array<string, mixed>  $bindings
     *
     * @throws Throwable
     */
    public function readValue(
        MonitoredDatabase $base,
        string $sql,
        array $bindings = [],
    ): int {
        return $this->on($base, function (Connection $cx) use ($sql, $bindings) {
            $lignes = $cx->select($sql, $bindings);

            if ($lignes === []) {
                // Une sonde qui ne rend rien vaut zéro, pas une panne : un
                // `count(*)` filtré rend toujours une ligne, mais un `sum` sur
                // un ensemble vide peut n'en rendre aucune.
                return 0;
            }

            $premiere = (array) $lignes[0];
            $valeur = $premiere['valeur'] ?? null;

            if ($valeur === null) {
                // `sum()` sans ligne rend NULL. C'est zéro, pas une erreur.
                return array_key_exists('valeur', $premiere)
                    ? 0
                    : throw new \RuntimeException(
                        'La requête doit rendre une colonne nommée « valeur ».',
                    );
            }

            return (int) $valeur;
        });
    }

    /**
     * Vérifie qu'on peut se connecter, et **qu'on ne peut pas écrire**.
     *
     * ## Pourquoi tenter l'écriture plutôt que faire confiance
     *
     * « Les accès sont en lecture seule » est une intention, pas un fait
     * constatable. On la constate : on tente une écriture, et si elle réussit
     * la base est refusée.
     *
     * La table créée porte un nom improbable et la transaction est annulée dans
     * tous les cas — mais si l'annulation échouait, il resterait une table vide
     * au nom explicite plutôt qu'une modification silencieuse.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function verifyReadOnly(MonitoredDatabase $base): array
    {
        try {
            $ecritureRefusee = false;

            $this->on($base, function (Connection $cx) use (&$ecritureRefusee) {
                $cx->select('select 1');

                try {
                    $cx->statement(
                        'create table arche_verification_lecture_seule (x int)',
                    );
                } catch (Throwable) {
                    $ecritureRefusee = true;
                }
            });

            return $ecritureRefusee
                ? ['ok' => true, 'error' => null]
                : [
                    'ok' => false,
                    'error' => 'Ces identifiants permettent d\'écrire. '
                        .'La supervision exige un accès en lecture seule.',
                ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ouvre une connexion jetable et y exécute [$travail].
     *
     * La connexion est nommée d'après la base et purgée ensuite : deux sondes
     * sur deux bases ne doivent jamais se partager un pool, sous peine qu'une
     * base lente affame les autres.
     */
    private function on(MonitoredDatabase $base, callable $travail): mixed
    {
        $nom = 'monitoring_'.$base->id;

        Config::set("database.connections.{$nom}", [
            'driver' => 'pgsql',
            'host' => $base->host,
            'port' => $base->port,
            'database' => $base->dbname,
            'username' => $base->username,
            'password' => $base->password,
            'charset' => 'utf8',
            'sslmode' => 'require',
            // Le pooler de Supabase ne supporte pas les requêtes préparées
            // nommées : sans cela, la seconde exécution d'une même sonde échoue
            // sur un nom déjà pris.
            'options' => [\PDO::ATTR_EMULATE_PREPARES => true],
        ]);

        try {
            $cx = DB::connection($nom);
            $cx->statement('set statement_timeout = '.self::TIMEOUT_MS);

            // En lecture seule au niveau du serveur : Postgres refusera toute
            // écriture, quels que soient les droits du compte.
            $cx->beginTransaction();
            $cx->statement('set transaction read only');

            try {
                return $travail($cx);
            } finally {
                // Toujours annulée : une sonde ne valide jamais rien.
                $cx->rollBack();
            }
        } finally {
            DB::purge($nom);
            Config::set("database.connections.{$nom}", null);
        }
    }
}
