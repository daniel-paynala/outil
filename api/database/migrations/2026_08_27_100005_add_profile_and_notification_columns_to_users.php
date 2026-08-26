<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonnes de `users` que le code lit sans qu'aucune migration ne les crée.
 *
 * `avatar_path` a été ajoutée directement en production ; les quatre
 * `notify_*` par un fichier SQL appliqué à la main
 * (`database/sql/2026_08_26_preferences_notification.sql`). Le modèle `User`
 * les déclare toutes dans `$fillable` et `casts()` — une base construite depuis
 * le dépôt seul plantait donc dès la première synchronisation de compte.
 *
 * La garde est ici par colonne, et non par table : `users` existe partout, ce
 * sont les colonnes qui manquent selon l'environnement. Chacune est donc testée
 * séparément, ce qui rend la migration rejouable sans dommage.
 */
return new class extends Migration
{
    /** Les quatre catégories de notification, avec leur valeur par défaut. */
    private const PREFERENCES = [
        'notify_messages',
        'notify_projects',
        'notify_tasks',
        'notify_task_assignment',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'avatar_path')) {
                // Chemin dans le bucket public `avatars` — jamais une URL :
                // celle-ci se compose côté client à partir de l'hôte Supabase.
                $table->string('avatar_path', 500)->nullable();
            }

            foreach (self::PREFERENCES as $column) {
                if (! Schema::hasColumn('users', $column)) {
                    // Activées par défaut : quelqu'un qui n'a jamais ouvert les
                    // réglages doit recevoir ce qui le concerne. Le silence ne
                    // se choisit pas par omission.
                    $table->boolean($column)->default(true);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['avatar_path', ...self::PREFERENCES] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
