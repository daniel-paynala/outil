<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('card_id');
            $table->uuid('user_id');
            $table->text('content');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('card_id')->references('id')->on('cards')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_comments');
    }
};
