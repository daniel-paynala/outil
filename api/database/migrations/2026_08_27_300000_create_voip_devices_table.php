<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appareils joignables par push VoIP.
 *
 * Voir `database/sql/2026_08_27_calls.sql` : la production s'applique en SQL
 * direct, cette migration ne sert qu'à monter le schéma de la suite de tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('voip_devices')) {
            return;
        }

        Schema::create('voip_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('token', 255)->unique();
            $table->string('platform', 10);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')
                ->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voip_devices');
    }
};
