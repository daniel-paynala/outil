<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_commits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('github_repo_id');
            $table->string('sha', 40);
            $table->text('message');
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->string('author_login')->nullable();
            $table->string('author_avatar_url')->nullable();
            $table->string('html_url')->nullable();
            $table->timestamp('authored_at')->nullable();
            $table->timestamps();

            $table->foreign('github_repo_id')->references('id')->on('github_repos')->cascadeOnDelete();
            $table->unique(['github_repo_id', 'sha']);
            $table->index(['github_repo_id', 'authored_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_commits');
    }
};
