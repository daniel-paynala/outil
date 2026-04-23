<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_repos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('full_name'); // owner/repo
            $table->string('platform', 40)->default('other'); // mobile, web, api, other, or free-form
            $table->string('default_branch', 120)->default('main');
            $table->string('description')->nullable();
            $table->text('access_token'); // encrypted via Laravel cast
            $table->uuid('linked_by');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('linked_by')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['project_id', 'full_name']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_repos');
    }
};
