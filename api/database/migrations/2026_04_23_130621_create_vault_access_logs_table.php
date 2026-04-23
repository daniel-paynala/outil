<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_access_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('entry_id');
            $table->uuid('user_id');
            $table->string('action', 24); // viewed, revealed, created, updated, deleted, restored
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('entry_id')->references('id')->on('vault_entries')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['entry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_access_logs');
    }
};
