<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Droits accordés au cas par cas.
 *
 * Le schéma de production est appliqué en SQL — voir
 * `database/sql/2026_09_01_capabilities.sql`. Cette migration ne sert qu'à
 * bâtir la base des tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_capabilities', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->string('capability', 64);

            // Nullable : un droit posé à la main dans l'éditeur SQL n'a pas
            // d'auteur applicatif.
            $table->uuid('granted_by')->nullable();
            $table->timestamp('granted_at')->useCurrent();

            $table->primary(['user_id', 'capability']);
            $table->index('capability');

            $table->foreign('user_id')->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('granted_by')->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_capabilities');
    }
};
