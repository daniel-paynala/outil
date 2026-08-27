<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des appels. Voir `database/sql/2026_08_27_call_log.sql` : la
 * production s'applique en SQL direct, cette migration monte le schéma des
 * tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('call_logs')) {
            return;
        }

        Schema::create('call_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('caller_id');
            $table->uuid('callee_id');
            $table->timestamp('connected_at')->nullable();
            $table->integer('duration')->default(0);
            $table->string('end_reason', 20);
            $table->string('route', 10)->nullable();
            $table->timestamps();

            $table->foreign('caller_id')->references('id')->on('users')
                ->cascadeOnDelete();
            $table->foreign('callee_id')->references('id')->on('users')
                ->cascadeOnDelete();

            $table->index(['caller_id', 'created_at']);
            $table->index(['callee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
