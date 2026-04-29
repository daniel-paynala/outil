<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('release_id')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('horizon', 16)->default('later'); // now / next / later / done
            $table->integer('position')->default(0);
            $table->string('effort', 4)->nullable(); // S / M / L / XL
            $table->date('target_date')->nullable();
            $table->jsonb('tags')->nullable(); // array of strings
            $table->uuid('created_by');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('release_id')->references('id')->on('releases')->nullOnDelete();
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['project_id', 'horizon', 'position']);
            $table->index(['project_id', 'target_date']);
            $table->index(['project_id', 'release_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_items');
    }
};
