<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('estimate', 40)->nullable()->after('due_date');
            $table->uuid('parent_card_id')->nullable()->after('column_id');

            $table->foreign('parent_card_id')->references('id')->on('cards')->nullOnDelete();
            $table->index('parent_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropForeign(['parent_card_id']);
            $table->dropIndex(['parent_card_id']);
            $table->dropColumn(['estimate', 'parent_card_id']);
        });
    }
};
