<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passe owner_id (1) -> owners (N) pour les items de roadmap.
 * 1. Crée la table pivot roadmap_item_owners
 * 2. Migre les owner_id existants dans la pivot
 * 3. Supprime la colonne owner_id
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_item_owners', function (Blueprint $table) {
            $table->uuid('roadmap_item_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->primary(['roadmap_item_id', 'user_id']);
            $table->foreign('roadmap_item_id')
                ->references('id')->on('roadmap_items')->cascadeOnDelete();
            $table->foreign('user_id')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });

        // Migration des owner_id existants
        DB::statement("
            INSERT INTO roadmap_item_owners (roadmap_item_id, user_id, created_at, updated_at)
            SELECT id, owner_id, NOW(), NOW()
            FROM roadmap_items
            WHERE owner_id IS NOT NULL
        ");

        Schema::table('roadmap_items', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropColumn('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_items', function (Blueprint $table) {
            $table->uuid('owner_id')->nullable()->after('release_id');
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
        });

        // Restaure le 1er owner trouvé
        DB::statement("
            UPDATE roadmap_items r
            SET owner_id = (
                SELECT user_id FROM roadmap_item_owners
                WHERE roadmap_item_id = r.id
                ORDER BY created_at ASC LIMIT 1
            )
        ");

        Schema::dropIfExists('roadmap_item_owners');
    }
};
