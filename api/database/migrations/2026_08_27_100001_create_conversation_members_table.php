<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Voir la note de `create_conversations_table` sur la garde `hasTable`. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_members')) {
            return;
        }

        Schema::create('conversation_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('user_id');

            // `owner` peut renommer le groupe et gérer les membres.
            $table->string('role', 20)->default('member');

            // Position de lecture : tout message postérieur est non lu. Une
            // date plutôt qu'un compteur — elle survit à la suppression d'un
            // message et se compare sans état à maintenir.
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')
                ->on('conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')
                ->cascadeOnDelete();

            $table->unique(['conversation_id', 'user_id'], 'conversation_members_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_members');
    }
};
