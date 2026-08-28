<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('card_id');
            $table->string('path', 500);
            $table->string('name', 255);
            $table->integer('size_bytes')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->uuid('uploaded_by');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('card_id')->references('id')->on('cards')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_attachments');
    }
};
