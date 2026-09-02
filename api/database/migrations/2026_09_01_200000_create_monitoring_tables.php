<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supervision des bases de l'entreprise.
 *
 * Le schéma de production est appliqué en SQL — voir
 * `database/sql/2026_09_01_monitoring.sql`, qui porte les explications de
 * conception. Cette migration ne sert qu'à bâtir la base des tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_databases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('host', 255);
            $table->integer('port')->default(5432);
            $table->string('dbname', 120);
            $table->string('username', 120);
            // Chiffré applicativement : la colonne est du texte parce que le
            // chiffré l'est.
            $table->text('password');
            $table->timestamp('read_only_verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')
                ->nullOnDelete();
        });

        Schema::create('monitoring_probes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('database_id');
            $table->string('title', 120);
            $table->string('unit', 40)->default('événements');
            $table->text('query');
            $table->timestamp('counting_from')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->boolean('enabled')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index('enabled');
            $table->foreign('database_id')->references('id')
                ->on('monitored_databases')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')
                ->nullOnDelete();
            $table->foreign('acknowledged_by')->references('id')->on('users')
                ->nullOnDelete();
        });

        Schema::create('monitoring_probe_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('probe_id');
            $table->integer('hours');

            // 'glissante' — les N dernières heures, à tout instant.
            // 'calendaire' — depuis minuit, heure de Libreville.
            $table->string('mode', 16)->default('glissante');

            // 'croissant' — le danger est en haut (des erreurs qui grimpent).
            // 'decroissant' — le danger est en bas (une santé qui s'effondre).
            $table->string('direction', 16)->default('croissant');

            $table->json('tiers');
            $table->integer('severest_tier')->default(0);
            $table->integer('last_value')->nullable();
            $table->timestamp('last_run_at')->nullable();

            $table->unique(['probe_id', 'hours']);
            $table->foreign('probe_id')->references('id')
                ->on('monitoring_probes')->cascadeOnDelete();
        });

        Schema::create('monitoring_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('probe_id');
            $table->integer('window_hours');
            $table->integer('tier');
            $table->integer('value');
            $table->timestamp('raised_at');

            $table->index(['probe_id', 'raised_at']);
            $table->foreign('probe_id')->references('id')
                ->on('monitoring_probes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alerts');
        Schema::dropIfExists('monitoring_probe_windows');
        Schema::dropIfExists('monitoring_probes');
        Schema::dropIfExists('monitored_databases');
    }
};
