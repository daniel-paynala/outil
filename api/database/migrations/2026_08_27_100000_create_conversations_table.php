<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations de la messagerie interne.
 *
 * ## Pourquoi cette migration arrive après coup
 *
 * La messagerie a été créée directement en SQL (`database/sql/2026_08_25_*`)
 * et appliquée à la main. La table existe donc en production depuis des jours
 * sans qu'aucune migration ne la décrive — au point que la sonde d'intégrité la
 * signale. Deux conséquences concrètes : reconstruire le serveur depuis le
 * dépôt donnerait une base amputée de toute la messagerie, et aucun test ne
 * peut monter un schéma complet.
 *
 * ## Pourquoi la garde `hasTable`
 *
 * La table préexiste en production. Sans cette garde, le prochain
 * `php artisan migrate` sur le serveur échouerait sur un « relation already
 * exists » — et la migration suivante ne passerait jamais. Avec elle, la
 * production ne bouge pas et les environnements neufs obtiennent le schéma
 * complet. C'est le seul moyen de rattraper l'écart sans casser l'existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversations')) {
            return;
        }

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Null = conversation transverse, non rattachée à un projet.
            $table->uuid('project_id')->nullable();

            // Null pour un échange direct : le titre est alors l'autre personne.
            $table->string('name', 120)->nullable();
            $table->string('topic', 255)->nullable();
            $table->boolean('is_group')->default(true);

            // ÉCART assumé : la convention veut CASCADE sur `created_by`. Ici,
            // supprimer le compte du créateur effacerait la conversation et
            // tout son historique pour les autres membres.
            $table->uuid('created_by')->nullable();

            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')
                ->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')
                ->nullOnDelete();

            $table->index('project_id');
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
