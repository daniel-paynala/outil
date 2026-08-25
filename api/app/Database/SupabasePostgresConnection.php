<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection;

/**
 * Connexion Postgres corrigeant la liaison des booléens.
 *
 * Le pooler Supabase du port 6543 fonctionne en mode transaction, qui ne
 * supporte pas les requêtes préparées côté serveur : `config/database.php`
 * force donc `PDO::ATTR_EMULATE_PREPARES`. PHP interpole alors lui-même les
 * paramètres.
 *
 * Or `Connection::prepareBindings()` convertit tout booléen en entier — un
 * comportement pensé pour le `tinyint` de MySQL. Interpolé, ça produit
 * `values (1)` : Postgres reçoit un entier là où il attend un booléen et
 * refuse la requête (`SQLSTATE[42804]`). Avec de vraies requêtes préparées le
 * problème ne se voit pas, Postgres déduisant le type du paramètre depuis la
 * colonne — d'où un bug qui n'apparaît qu'à travers le pooler.
 *
 * On convertit donc les booléens en littéraux `'true'` / `'false'`, que
 * Postgres accepte partout où un booléen est attendu.
 *
 * Portée : toute l'application. Sans ça, `columns.is_done` et
 * `conversations.is_group` sont inécrivables — donc créer un projet, qui
 * sème ses colonnes par défaut, échoue.
 */
class SupabasePostgresConnection extends PostgresConnection
{
    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    public function prepareBindings(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                // Avant l'appel au parent : lui transformerait en entier.
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return parent::prepareBindings($bindings);
    }
}
