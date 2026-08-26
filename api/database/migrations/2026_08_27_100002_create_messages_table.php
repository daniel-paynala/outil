<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Voir la note de `create_conversations_table` sur la garde `hasTable`. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages')) {
            return;
        }

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');

            // Null après suppression du compte : le fil reste lisible, le
            // message est simplement attribué à un auteur inconnu.
            $table->uuid('user_id')->nullable();

            $table->text('body');

            // Suppression douce : effacer réellement la ligne trouerait la
            // pagination par curseur et les réponses qui la citent.
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('edited_at')->nullable();

            $table->uuid('reply_to_id')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')
                ->on('conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')
                ->nullOnDelete();
            $table->foreign('reply_to_id')->references('id')->on('messages')
                ->nullOnDelete();

            // L'index qui porte la pagination : on lit toujours une
            // conversation du plus récent au plus ancien.
            $table->index(['conversation_id', 'created_at']);
            $table->index('reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
