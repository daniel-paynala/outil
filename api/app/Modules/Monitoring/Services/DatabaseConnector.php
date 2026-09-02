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
    /**
     * Le plafond par défaut, quand la sonde n'en fixe pas.
     *
     * Huit secondes est le bon ordre de grandeur pour compter des événements
     * sur une table indexée. Ce ne l'est pas pour croiser des centaines de
     * milliers de lignes de journal : le tableau de bord Paynala accorde
     * 45 secondes à ces requêtes-là. Une sonde peut donc relever son propre
     * plafond — mais il en faut un, sinon une requête abandonnée immobilise
     * une connexion jusqu'à ce que quelqu'un s'en aperçoive.
     */
    public const TIMEOUT_MS = 8000;

    /**
     * Exécute [$sql] en lecture seule et rend le nombre attendu.
     *
     * @param  array<string, mixed>  $bindings
     *
     * @throws Throwable
     */
    public function read(
        MonitoredDatabase $base,
        string $sql,
        array $bindings = [],
        ?int $timeoutMs = null,
    ): array {
        return $this->on($base, function (Connection $cx) use ($sql, $bindings) {
            $lignes = $cx->select($sql, $bindings);

            if ($lignes === []) {
                // Une sonde qui ne rend rien vaut zéro, pas une panne : un
                // `count(*)` filtré rend toujours une ligne, mais un `sum` sur
                // un ensemble vide peut n'en rendre aucune.
                return ['valeur' => 0, 'detail' => []];
            }

            $premiere = (array) $lignes[0];

            if (! array_key_exists('valeur', $premiere)) {
                throw new \RuntimeException(
                    'La requête doit rendre une colonne nommée « valeur ».',
                );
            }

            // Tout ce que la requête rend en plus est conservé tel quel : c'est
            // la décomposition qu'on affichera sous le chiffre. Une somme
            // totale sans son détail oblige à créer une sonde par
            // portefeuille, et à en payer quatre fois le coût.
            $detail = [];
            foreach ($premiere as $colonne => $brut) {
                if ($colonne === 'valeur' || $brut === null) {
                    continue;
                }

                $detail[(string) $colonne] = is_numeric($brut)
                    ? (int) $brut
                    : (string) $brut;
            }

            return [
                // `sum()` sur un ensemble vide rend NULL. C'est zéro, pas une
                // erreur.
                'valeur' => (int) ($premiere['valeur'] ?? 0),
                'detail' => $detail,
            ];
        }, $timeoutMs);
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
    private function on(
        MonitoredDatabase $base,
        callable $travail,
        ?int $timeoutMs = null,
    ): mixed {
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
            $cx->statement(
                'set statement_timeout = '.($timeoutMs ?? self::TIMEOUT_MS),
            );

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
