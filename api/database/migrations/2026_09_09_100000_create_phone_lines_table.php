<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Le registre des lignes téléphoniques de l'entreprise.
 *
 * ## Pourquoi une table plutôt qu'un tableur
 *
 * Ce registre existait déjà, dans un tableur. Ce qu'il ne disait pas, c'est
 * *quand* une ligne avait changé de main ni *qui* l'avait écrite — et c'est
 * précisément ce qu'on cherche quand une facture Airtel Money arrive sur un
 * numéro dont personne ne se souvient.
 *
 * ## Ce que le numéro n'est pas
 *
 * Il n'est pas un entier. Un numéro gabonais commence par 0 dans la moitié des
 * façons de l'écrire, et `77050946` additionné à quoi que ce soit n'a aucun
 * sens. On le range en chaîne, et son unicité est celle du couple
 * opérateur + numéro : deux opérateurs peuvent parfaitement attribuer la même
 * suite de chiffres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('operator', 40);
            $table->string('number', 32);

            // Le nom au guichet de l'opérateur. Ce n'est pas forcément
            // quelqu'un d'Arche — d'où une chaîne libre et non une clé vers
            // `users`.
            $table->string('registered_name', 120);

            // À quoi la ligne sert. Un projet, un produit, une démarche
            // administrative : rien d'assez stable pour une table à part.
            $table->string('purpose', 200)->nullable();

            $table->boolean('mobile_money')->default(false);
            $table->boolean('whatsapp')->default(false);

            // « Oui (Bot WhatsApp) » du registre d'origine. Le fait d'avoir un
            // compte et la façon dont il sert sont deux informations : les
            // fondre dans un seul champ obligeait à lire une case à cocher
            // comme du texte.
            $table->string('whatsapp_note', 120)->nullable();

            $table->text('notes')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['operator', 'number']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // Les quatre lignes du registre d'origine.
        //
        // Dans la migration et non dans un seeder : un seeder ne tourne pas au
        // déploiement, et le registre serait arrivé vide sur un écran dont
        // c'est tout le contenu. Elles ne sont posées que si la table est
        // vraiment neuve — rejouer la migration ailleurs ne doit rien écraser.
        $maintenant = now();
        $lignes = [
            ['Airtel', '76520000', 'LOKOSSOU Fidèle', 'Rengus Digital (Permis de conduire)', false, true, null],
            ['Airtel', '77050946', 'Daniel DOVI', 'PAYNALA', false, true, null],
            ['Airtel', '77106367', 'LOKOSSOU Fidèle', 'Déploiement Assurpay', true, true, null],
            ['Airtel', '77607752', 'Daniel DOVI', 'Tonji', false, true, 'Bot WhatsApp'],
        ];

        DB::table('phone_lines')->insert(array_map(
            fn (array $l) => [
                'id' => (string) Str::uuid(),
                'operator' => $l[0],
                'number' => $l[1],
                'registered_name' => $l[2],
                'purpose' => $l[3],
                'mobile_money' => $l[4],
                'whatsapp' => $l[5],
                'whatsapp_note' => $l[6],
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ],
            $lignes,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_lines');
    }
};
