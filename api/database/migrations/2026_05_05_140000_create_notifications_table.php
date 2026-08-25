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

        // RLS : chaque user ne voit que ses propres notifications via Supabase Realtime
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

        // Active la publication Realtime sur la table
        DB::statement('ALTER PUBLICATION supabase_realtime ADD TABLE notifications;');
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER PUBLICATION supabase_realtime DROP TABLE notifications;');
        } catch (\Throwable $e) {
            // ignore
        }
        Schema::dropIfExists('notifications');
    }
};
