<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->string('color', 7)->default('#737375');
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'shipped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
