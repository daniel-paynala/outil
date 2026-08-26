<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications in-app.
 *
 * Cas le plus net de dérive entre le dépôt et la production : cette table
 * porte plusieurs centaines de lignes, l'app mobile appelle `/api/notifications`
 * — et ni la table, ni les routes, ni le code qui les produit ne figurent au
 * dépôt. La migration rattrape au moins la table ; les routes restent à
 * rapatrier depuis le serveur.
 *
 * Voir aussi `database/sql/2026_08_27_preferences_trigger.sql`, qui applique
 * les préférences de l'utilisateur à l'insertion — précisément parce que le
 * producteur n'est pas versionné.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('project_id')->nullable();

            // Qui a provoqué la notification. Null pour un événement système.
            $table->uuid('actor_id')->nullable();

            // `task.assigned`, `document.uploaded`, `project.member.added`…
            // Le préfixe porte la catégorie, ce dont dépend le déclencheur de
            // préférences.
            $table->string('type', 60);

            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')
                ->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')
                ->nullOnDelete();

            // Les deux lectures réelles : la pastille de non-lus, et le fil
            // antichronologique.
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
