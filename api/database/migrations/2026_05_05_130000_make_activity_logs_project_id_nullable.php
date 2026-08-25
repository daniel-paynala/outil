<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permet d'avoir des logs globaux (actions admin sans projet associé).
 * Et conserve les logs même si un projet est supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop FK existant pour pouvoir le recréer en nullOnDelete
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->uuid('project_id')->nullable()->change();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->uuid('project_id')->nullable(false)->change();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
};
