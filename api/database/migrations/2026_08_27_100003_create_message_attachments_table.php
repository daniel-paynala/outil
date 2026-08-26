<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Voir la note de `create_conversations_table` sur la garde `hasTable`. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('message_attachments')) {
            return;
        }

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('message_id');

            // Chemin dans le bucket privé `messagerie` — jamais une URL : les
            // liens de téléchargement sont signés à la demande et expirent.
            $table->string('path', 500);
            $table->string('name', 255);
            $table->bigInteger('size_bytes')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('message_id')->references('id')->on('messages')
                ->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')
                ->nullOnDelete();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
