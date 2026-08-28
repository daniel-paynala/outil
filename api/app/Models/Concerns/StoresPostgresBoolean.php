<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Un booléen que Postgres accepte, et que les autres pilotes n'abîment pas.
 *
 * ## Le problème d'origine
 *
 * `PDO_PGSQL` en PHP 8.4 lie les booléens sous forme d'entiers (0/1). Postgres
 * 16, strict sur les types, refuse un entier pour une colonne `boolean`. Le
 * contournement retenu était d'écrire les littéraux natifs `'t'` et `'f'`.
 *
 * ## Le problème que ce contournement a créé
 *
 * `'f'` est une chaîne non vide. Partout ailleurs qu'en Postgres — la base
 * SQLite des tests, au premier chef — elle se relit `true` : un booléen faux
 * devenait vrai en traversant la base.
 *
 * Rien ne le signalait, et l'effet était pire qu'une panne franche. La colonne
 * « En cours » d'un tableau passait pour une colonne « Terminé », et toute la
 * logique d'achèvement devenait invérifiable. C'est précisément ce qui
 * manquait tant que personne n'écrivait de test sur le module des tâches : le
 * défaut n'existait qu'en test, donc seul un test pouvait le révéler.
 *
 * Le littéral n'est donc posé que là où il est nécessaire.
 */
trait StoresPostgresBoolean
{
    protected function postgresBoolean(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => (bool) $value,
            set: fn ($value) => $this->getConnection()->getDriverName() === 'pgsql'
                ? ($value ? 't' : 'f')
                : (bool) $value,
        );
    }
}
