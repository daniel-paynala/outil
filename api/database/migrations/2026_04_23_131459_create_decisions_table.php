<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->integer('number');
            $table->string('title');
            $table->string('status')->default('proposed'); // proposed, accepted, deprecated, superseded
            $table->longText('context')->nullable();
            $table->longText('decision')->nullable();
            $table->longText('consequences')->nullable();
            $table->longText('alternatives')->nullable();
            $table->longText('references')->nullable();
            $table->date('decided_at')->nullable();
            $table->uuid('created_by');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
