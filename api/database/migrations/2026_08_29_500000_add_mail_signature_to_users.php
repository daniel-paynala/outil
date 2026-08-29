<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signature de courrier.
 *
 * Le schéma de production est appliqué en SQL — voir
 * `database/sql/2026_08_29_mail_signature.sql`. Cette migration ne sert qu'à
 * bâtir la base des tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable sans défaut : « jamais réglée » se distingue de « refusée
            // explicitement », et l'application n'ajoute sa mention que dans le
            // premier cas.
            $table->text('mail_signature')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mail_signature');
        });
    }
};
