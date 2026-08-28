<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arborescence de dossiers pour les documents de projet.
 *
 * - `project_folders` : self-reference parent_id, cascade au delete
 * - `project_files.folder_id` : nullOnDelete → si dossier supprimé, fichiers
 *   remontent à la racine (au lieu d'être perdus en cascade)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Étape 1 : créer la table sans la FK self-référente
        Schema::create('project_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name', 120);
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['project_id', 'parent_id']);
        });

        // Étape 2 : ajouter la FK self-référente après création de la PK
        Schema::table('project_folders', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('project_folders')->cascadeOnDelete();
        });

        Schema::table('project_files', function (Blueprint $table) {
            $table->uuid('folder_id')->nullable()->after('project_id');
            $table->foreign('folder_id')->references('id')->on('project_folders')->nullOnDelete();
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_files', function (Blueprint $table) {
            $table->dropForeign(['folder_id']);
            $table->dropColumn('folder_id');
        });
        Schema::dropIfExists('project_folders');
    }
};
