<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattachement d'une boîte Google Workspace à un compte Arche.
 *
 * Une ligne par personne — `user_id` est unique. Gérer plusieurs boîtes par
 * compte multiplierait les surveillances Gmail et les cas limites (laquelle
 * notifie ? laquelle répond ?) pour un besoin que personne n'a exprimé.
 *
 * ## Ce que cette table contient, et ce qu'elle ne contient pas
 *
 * Elle porte un jeton de rafraîchissement, c'est-à-dire un accès permanent à
 * la boîte de quelqu'un. C'est la donnée la plus sensible de l'installation, et
 * la raison pour laquelle elle est chiffrée au repos et jamais rendue par
 * l'API.
 *
 * En revanche, **aucun courrier n'y est stocké** : l'app parle à Gmail
 * directement pour lire et écrire. Le serveur ne se sert de son jeton que pour
 * renouveler la surveillance et composer le titre d'une notification, sans rien
 * conserver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('google_accounts')) {
            return;
        }

        Schema::create('google_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();

            // Adresse rattachée. Conservée en clair : elle s'affiche dans
            // l'app pour qu'on sache quelle boîte est connectée.
            $table->string('email', 255);

            // Chiffré par le modèle (cast `encrypted`). Une fuite de la base
            // seule ne donne donc rien sans l'`APP_KEY`.
            $table->text('refresh_token');

            // Portées réellement accordées, qui peuvent être plus étroites que
            // celles demandées si la personne en a décoché.
            $table->text('scopes')->nullable();

            // Curseur de l'historique Gmail. Les notifications Pub/Sub ne
            // portent qu'un `historyId` : c'est en le comparant au précédent
            // qu'on sait ce qui est arrivé depuis.
            $table->string('history_id', 40)->nullable();

            // La surveillance Gmail expire au bout de 7 jours et doit être
            // renouvelée. Sans cette date, on ne saurait pas qu'elle s'est
            // éteinte — et les notifications cesseraient en silence.
            $table->timestamp('watch_expires_at')->nullable();

            // Dernier échec de renouvellement ou de rafraîchissement. Rendu
            // par `/api/mail/status` : un jeton révoqué côté Google ne se voit
            // autrement que par l'absence de notifications.
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')
                ->cascadeOnDelete();

            // Le renouvellement quotidien balaie par cette colonne.
            $table->index('watch_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
