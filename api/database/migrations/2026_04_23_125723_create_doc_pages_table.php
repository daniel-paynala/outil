<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('parent_id')->nullable();
            $table->string('title');
            $table->string('slug');
            $table->longText('content')->nullable();
            $table->integer('position')->default(0);
            $table->uuid('created_by');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['project_id', 'slug']);
            $table->index(['project_id', 'parent_id', 'position']);
        });

        // Self-reference FK added after table creation (PG quirk).
        Schema::table('doc_pages', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('doc_pages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_pages');
    }
};
