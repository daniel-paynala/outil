<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('type', 60);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Sécurité au niveau ligne et publication Realtime : deux notions que
        // seul Postgres connaît. Les migrations servent aussi à bâtir le schéma
        // SQLite des tests, où ces instructions ne se contentent pas d'être
        // sans effet — elles ne s'analysent pas, et font échouer la suite
        // entière avant qu'un seul test ne s'exécute.
        //
        // Ce qui compte en production reste appliqué là où c'est vrai ; ce que
        // les tests vérifient, ce sont les contrôleurs, pas les politiques.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Chaque utilisateur ne voit que ses propres notifications quand elles
        // lui arrivent par Supabase Realtime, qui court-circuite l'API.
        DB::statement('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;');
        DB::statement(<<<'SQL'
            CREATE POLICY "users_select_own_notifications"
            ON notifications FOR SELECT
            TO authenticated
            USING (user_id = auth.uid());
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY "users_update_own_notifications"
            ON notifications FOR UPDATE
            TO authenticated
            USING (user_id = auth.uid());
        SQL);

        DB::statement('ALTER PUBLICATION supabase_realtime ADD TABLE notifications;');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::statement('ALTER PUBLICATION supabase_realtime DROP TABLE notifications;');
            } catch (\Throwable $e) {
                // La publication a pu être retirée à la main, ou ne jamais avoir
                // existé sur une base neuve. Rien à réparer dans les deux cas.
            }
        }
        Schema::dropIfExists('notifications');
    }
};
