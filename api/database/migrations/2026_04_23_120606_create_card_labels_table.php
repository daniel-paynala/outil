<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_labels', function (Blueprint $table) {
            $table->uuid('card_id');
            $table->uuid('label_id');
            $table->timestamps();

            $table->primary(['card_id', 'label_id']);
            $table->foreign('card_id')->references('id')->on('cards')->cascadeOnDelete();
            $table->foreign('label_id')->references('id')->on('labels')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_labels');
    }
};
