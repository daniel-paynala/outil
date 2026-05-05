<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('columns', function (Blueprint $table) {
            $table->boolean('is_done')->default(false)->after('color');
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('due_date');
            $table->index('completed_at');
        });

        // Backfill : colonnes "Terminé"/"Done"/"Livré" existantes → is_done=true,
        // puis marquer les cartes qui s'y trouvent comme terminées (completed_at = updated_at).
        DB::statement(<<<'SQL'
            UPDATE columns
            SET is_done = TRUE
            WHERE name ILIKE 'terminé%'
               OR name ILIKE 'termine%'
               OR name ILIKE 'done%'
               OR name ILIKE 'livré%'
               OR name ILIKE 'closed%'
        SQL);

        DB::statement(<<<'SQL'
            UPDATE cards
            SET completed_at = COALESCE(updated_at, NOW())
            WHERE completed_at IS NULL
              AND column_id IN (SELECT id FROM columns WHERE is_done = TRUE)
        SQL);
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['completed_at']);
            $table->dropColumn('completed_at');
        });

        Schema::table('columns', function (Blueprint $table) {
            $table->dropColumn('is_done');
        });
    }
};
