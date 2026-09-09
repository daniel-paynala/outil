<?php

namespace Tests\Feature;

use App\Modules\Phones\Models\PhoneLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le registre des lignes de l'entreprise.
 *
 * ## Ce que ces tests protègent
 *
 * Un registre ouvert à toute l'équipe se dégrade par les doublons, pas par la
 * malveillance : le même numéro saisi deux fois, une fois avec des espaces,
 * une fois sans, et plus personne ne sait laquelle des deux lignes fait foi.
 * C'est donc la normalisation et l'unicité qui sont vérifiées ici.
 *
 * Le reste — tri, pagination, longueur des champs — se corrige à la première
 * plainte.
 */
class PhoneLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_quatre_lignes_du_registre_sont_presentes(): void
    {
        // La migration les pose. Sans elles l'écran serait arrivé vide sur les
        // postes de l'équipe, pour un registre qui existait déjà.
        [, $entetes] = $this->authenticate();

        $reponse = $this->getJson('/api/phone-lines', $entetes)->assertOk();

        $this->assertCount(4, $reponse->json());
        $this->assertSame(
            ['76520000', '77050946', '77106367', '77607752'],
            array_column($reponse->json(), 'number'),
        );
    }

    public function test_le_numero_est_range_sans_separateurs(): void
    {
        [, $entetes] = $this->authenticate();

        $this->postJson('/api/phone-lines', [
            'operator' => 'Moov',
            'number' => '06 12 34 56',
            'registered_name' => 'Daniel DOVI',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('number', '06123456');
    }

    public function test_le_meme_numero_ne_rentre_pas_deux_fois(): void
    {
        [, $entetes] = $this->authenticate();

        // Écrit autrement, mais c'est la même ligne — et c'est exactement
        // ainsi qu'un doublon entre dans un registre partagé.
        $this->postJson('/api/phone-lines', [
            'operator' => 'Airtel',
            'number' => '77 05 09 46',
            'registered_name' => 'Daniel DOVI',
        ], $entetes)->assertStatus(422);
    }

    public function test_le_meme_numero_chez_un_autre_operateur_est_accepte(): void
    {
        // Deux opérateurs attribuent librement la même suite de chiffres :
        // l'unicité porte sur le couple, jamais sur le numéro seul.
        [, $entetes] = $this->authenticate();

        $this->postJson('/api/phone-lines', [
            'operator' => 'Moov',
            'number' => '77050946',
            'registered_name' => 'Daniel DOVI',
        ], $entetes)->assertCreated();
    }

    public function test_une_note_whatsapp_ne_survit_pas_au_decochage(): void
    {
        // Sinon elle se lirait comme un fait : « Bot WhatsApp » affiché sous
        // une ligne qui n'a plus de compte WhatsApp.
        [, $entetes] = $this->authenticate();

        $ligne = PhoneLine::where('number', '77607752')->firstOrFail();
        $this->assertSame('Bot WhatsApp', $ligne->whatsapp_note);

        $this->patchJson("/api/phone-lines/{$ligne->id}", [
            'operator' => 'Airtel',
            'number' => '77607752',
            'registered_name' => 'Daniel DOVI',
            'whatsapp' => false,
            'whatsapp_note' => 'Bot WhatsApp',
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('whatsapp_note', null);
    }

    public function test_toute_l_equipe_ecrit_et_la_trace_dit_qui(): void
    {
        // L'arbitrage assumé : pas de garde a priori, mais chaque ligne sait
        // qui l'a touchée en dernier.
        [$auteur, $entetesAuteur] = $this->authenticate();
        [$autre, $entetesAutre] = $this->authenticate();

        $cree = $this->postJson('/api/phone-lines', [
            'operator' => 'Airtel',
            'number' => '74000000',
            'registered_name' => 'LOKOSSOU Fidèle',
        ], $entetesAuteur)->assertCreated()->json();

        $this->assertSame($auteur->id, $cree['created_by']);

        $this->patchJson("/api/phone-lines/{$cree['id']}", [
            'operator' => 'Airtel',
            'number' => '74000000',
            'registered_name' => 'LOKOSSOU Fidèle',
            'purpose' => 'Tonji',
        ], $entetesAutre)
            ->assertOk()
            ->assertJsonPath('created_by', $auteur->id)
            ->assertJsonPath('updated_by', $autre->id);
    }

    public function test_sans_jeton_le_registre_reste_ferme(): void
    {
        $this->getJson('/api/phone-lines')->assertStatus(401);
    }
}
