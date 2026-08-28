<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Digest des notifications de documents : on accumule les fichiers uploadés
 * dans une file d'attente par (project_id, user_id) et on n'envoie le mail
 * qu'après 5 minutes sans nouvel upload (debounce).
 *
 * Aussi : on repasse `notify_project_document_email` à FALSE par défaut
 * (le user a explicitement demandé "désactivé par défaut").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_document_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('user_id');
            $table->jsonb('file_ids'); // liste des project_files.id à inclure
            $table->timestamp('last_upload_at');
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['project_id', 'user_id']);
            $table->index('last_upload_at');
        });

        // Default: désactivé
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_project_document_email')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_document_notifications');
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_project_document_email')->default(true)->change();
        });
    }
};
