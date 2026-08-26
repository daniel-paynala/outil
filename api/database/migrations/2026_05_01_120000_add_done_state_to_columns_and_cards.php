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

        $this->backfill();
    }

    /**
     * Reprise des données existantes : colonnes « Terminé »/« Done »/« Livré »
     * → `is_done`, puis les cartes qui s'y trouvent → `completed_at`.
     *
     * `ILIKE` n'existe qu'en PostgreSQL. Ce n'était pas un problème tant que
     * les migrations ne tournaient que contre Supabase — ça l'est devenu quand
     * la suite de tests a commencé à monter le schéma sur SQLite : la migration
     * échouait, et aucun test ne pouvait démarrer.
     *
     * On garde `ILIKE` là où il existe, plutôt que d'aligner tout le monde sur
     * `LIKE` : en PostgreSQL, `LIKE` est sensible à la casse, et « Terminé »
     * cesserait d'être reconnu sur les vraies données. La reprise elle-même est
     * sans objet sur une base neuve — il n'y a rien à reprendre — mais elle
     * doit rester exacte là où elle sert.
     */
    private function backfill(): void
    {
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        DB::statement(<<<SQL
            UPDATE columns
            SET is_done = TRUE
            WHERE name {$like} 'terminé%'
               OR name {$like} 'termine%'
               OR name {$like} 'done%'
               OR name {$like} 'livré%'
               OR name {$like} 'closed%'
        SQL);

        DB::statement(<<<'SQL'
            UPDATE cards
            SET completed_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
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
