<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cinquième catégorie de notification : le courrier.
 *
 * Séparée des quatre autres parce qu'elle a un volume et un rythme sans commune
 * mesure — une boîte professionnelle reçoit en une matinée ce qu'un projet
 * Arche produit en une semaine. La couper doit être possible sans renoncer aux
 * notifications de tâches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'notify_mail')) {
                // Activée par défaut, comme les autres : quelqu'un qui rattache
                // sa boîte le fait pour être prévenu.
                $table->boolean('notify_mail')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'notify_mail')) {
                $table->dropColumn('notify_mail');
            }
        });
    }
};
