<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La notification email pour les nouveaux documents de projet doit être
 * activée par défaut. On change le default de la colonne et on met à jour
 * les utilisateurs existants.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Met à jour les rows existants — SQL raw pour contourner le bug
        // PDO_PGSQL bool→int de PHP 8.4 (cf. Column::isDone, User::notify*).
        DB::statement('UPDATE users SET notify_project_document_email = true');

        // 2) Change le default pour les futurs users
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_project_document_email')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_project_document_email')->default(false)->change();
        });
        // Pas de revert sur les rows : on n'a pas l'historique des prefs.
    }
};
