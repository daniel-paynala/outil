<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_roadmap_items', function (Blueprint $table) {
            $table->uuid('card_id');
            $table->uuid('roadmap_item_id');
            $table->timestamps();

            $table->primary(['card_id', 'roadmap_item_id']);
            $table->foreign('card_id')->references('id')->on('cards')->cascadeOnDelete();
            $table->foreign('roadmap_item_id')->references('id')->on('roadmap_items')->cascadeOnDelete();
            $table->index('roadmap_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_roadmap_items');
    }
};
