<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('card_id');
            $table->uuid('depends_on_card_id');
            $table->timestamps();

            $table->foreign('card_id')->references('id')->on('cards')->cascadeOnDelete();
            $table->foreign('depends_on_card_id')->references('id')->on('cards')->cascadeOnDelete();
            $table->unique(['card_id', 'depends_on_card_id']);
            $table->index('depends_on_card_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_dependencies');
    }
};
