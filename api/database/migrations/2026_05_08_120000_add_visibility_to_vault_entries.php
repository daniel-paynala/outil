<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visibilité des entrées du coffre :
 * - 'all' (défaut) : tout membre du projet peut lire/modifier
 * - 'restricted' : seulement le créateur + utilisateurs explicitement autorisés
 *
 * Pivot `vault_entry_users` pour la liste blanche en mode 'restricted'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vault_entries', function (Blueprint $table) {
            $table->string('visibility', 16)->default('all');
        });

        Schema::create('vault_entry_users', function (Blueprint $table) {
            $table->uuid('vault_entry_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->primary(['vault_entry_id', 'user_id']);
            $table->foreign('vault_entry_id')->references('id')->on('vault_entries')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_entry_users');
        Schema::table('vault_entries', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
