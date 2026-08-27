<?php

namespace Tests\Feature;

use App\Modules\Calls\Models\VoipDevice;
use App\Modules\Calls\Services\ApnsVoipSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que le serveur fait pour les appels.
 *
 * Il ne porte ni la voix ni la signalisation — seulement la sonnerie d'un
 * appareil que l'application ne peut pas atteindre. La surface est donc
 * minuscule, et c'est exactement pour cela qu'elle doit être juste : une
 * erreur ici et personne ne décroche jamais.
 */
class CallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'apns.key_id' => 'J44X53A6YN',
            'apns.team_id' => '549UF4W3FB',
            'apns.bundle_id' => 'com.paynala.arche',
        ]);
    }

    public function test_l_enregistrement_exige_une_authentification(): void
    {
        $this->postJson('/api/calls/devices', [
            'token' => 'abc',
            'platform' => 'ios',
        ])->assertUnauthorized();
    }

    public function test_un_appareil_s_enregistre(): void
    {
        [$user, $entetes] = $this->authenticate();

        $this->postJson('/api/calls/devices', [
            'token' => 'jeton-voip-1',
            'platform' => 'ios',
        ], $entetes)->assertNoContent();

        $this->assertDatabaseHas('voip_devices', [
            'user_id' => $user->id,
            'token' => 'jeton-voip-1',
            'platform' => 'ios',
        ]);
    }

    public function test_reenregistrer_le_meme_jeton_ne_le_duplique_pas(): void
    {
        // L'app réenregistre à chaque démarrage : sans cette idempotence, un
        // appareil sonnerait autant de fois que l'app a été ouverte.
        [$_, $entetes] = $this->authenticate();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/calls/devices', [
                'token' => 'jeton-voip-1',
                'platform' => 'ios',
            ], $entetes)->assertNoContent();
        }

        $this->assertDatabaseCount('voip_devices', 1);
    }

    public function test_un_jeton_change_de_proprietaire_avec_l_appareil(): void
    {
        // Deux comptes sur le même téléphone : le jeton appartient à qui vient
        // de se connecter, sinon les appels du premier continueraient d'y
        // sonner.
        [$a, $entetesA] = $this->authenticate();
        [$b, $entetesB] = $this->authenticate();

        $this->postJson('/api/calls/devices',
            ['token' => 'meme-appareil', 'platform' => 'ios'], $entetesA);
        $this->postJson('/api/calls/devices',
            ['token' => 'meme-appareil', 'platform' => 'ios'], $entetesB);

        $this->assertDatabaseCount('voip_devices', 1);
        $this->assertDatabaseHas('voip_devices', [
            'token' => 'meme-appareil',
            'user_id' => $b->id,
        ]);
    }

    public function test_une_plateforme_inconnue_est_refusee(): void
    {
        [$_, $entetes] = $this->authenticate();

        $this->postJson('/api/calls/devices', [
            'token' => 'jeton',
            'platform' => 'windows',
        ], $entetes)->assertStatus(422);
    }

    public function test_faire_sonner_quelqu_un_sans_appareil_le_dit(): void
    {
        // Zéro appareil atteint signifie que personne ne sonnera : l'appelant
        // peut le dire plutôt que de laisser tourner un appel sans issue.
        [$_, $entetes] = $this->authenticate();
        [$cible, $__] = $this->authenticate();

        $this->postJson('/api/calls/ring', [
            'call_id' => '11111111-1111-4111-8111-111111111111',
            'to_user_id' => $cible->id,
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('devices', 0)
            ->assertJsonPath('reached', 0);
    }

    public function test_on_ne_peut_pas_se_faire_sonner_soi_meme(): void
    {
        // Cela ferait sonner l'appareil qui compose.
        [$moi, $entetes] = $this->authenticate();

        $this->postJson('/api/calls/ring', [
            'call_id' => '11111111-1111-4111-8111-111111111111',
            'to_user_id' => $moi->id,
        ], $entetes)->assertStatus(422);
    }

    public function test_seuls_les_appareils_ios_sont_sonnes(): void
    {
        // Android n'a pas besoin de PushKit : un message de haute priorité
        // suffit, et cette voie n'est pas encore branchée. Tenter un push VoIP
        // vers Apple avec un jeton Android échouerait à chaque appel.
        [$_, $entetes] = $this->authenticate();
        [$cible, $__] = $this->authenticate();

        VoipDevice::create([
            'user_id' => $cible->id,
            'token' => 'jeton-android',
            'platform' => 'android',
        ]);

        $this->postJson('/api/calls/ring', [
            'call_id' => '11111111-1111-4111-8111-111111111111',
            'to_user_id' => $cible->id,
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('devices', 1)
            ->assertJsonPath('reached', 0);
    }

    public function test_sans_relais_configure_la_liste_est_vide(): void
    {
        // Les appels fonctionnent sans relais, simplement pas partout. Rendre
        // une erreur ferait échouer un appel qui aurait pu aboutir.
        config(['calls.turn_host' => null, 'calls.turn_secret' => null]);

        [$_, $entetes] = $this->authenticate();

        $this->getJson('/api/calls/turn', $entetes)
            ->assertOk()
            ->assertJsonPath('servers', []);
    }

    public function test_les_identifiants_de_relais_sont_temporaires(): void
    {
        // Un mot de passe figé serait extractible de l'APK, et le relais
        // deviendrait un service gratuit pour qui l'a trouvé.
        config([
            'calls.turn_host' => 'arche.paynala.com',
            'calls.turn_secret' => 'secret-de-test',
        ]);

        [$user, $entetes] = $this->authenticate();

        $reponse = $this->getJson('/api/calls/turn', $entetes)->assertOk();
        $serveur = $reponse->json('servers.0');

        // Le nom porte l'expiration et l'identité : c'est ce que coturn
        // vérifie, et ce qui rend le couple inutilisable ailleurs.
        [$expiration, $qui] = explode(':', $serveur['username'], 2);
        $this->assertSame($user->id, $qui);
        $this->assertGreaterThan(time(), (int) $expiration);

        // Le mot de passe est la signature du nom : recalculable par le
        // serveur, jamais devinable par le client.
        $this->assertSame(
            base64_encode(hash_hmac('sha1', $serveur['username'], 'secret-de-test', true)),
            $serveur['credential'],
        );
    }

    public function test_le_relais_propose_un_repli_tcp(): void
    {
        // Certains réseaux d'entreprise bloquent l'UDP en totalité : sans
        // repli, l'appel y échoue malgré le relais.
        config([
            'calls.turn_host' => 'arche.paynala.com',
            'calls.turn_secret' => 'secret-de-test',
        ]);

        [$_, $entetes] = $this->authenticate();

        $urls = $this->getJson('/api/calls/turn', $entetes)->json('servers.0.urls');

        $this->assertTrue(collect($urls)->contains(fn ($u) => str_contains($u, 'transport=udp')));
        $this->assertTrue(collect($urls)->contains(fn ($u) => str_contains($u, 'transport=tcp')));
        $this->assertTrue(collect($urls)->contains(fn ($u) => str_starts_with($u, 'turns:')));
    }

    public function test_un_jeton_mort_est_reconnu_comme_tel(): void
    {
        // Ces deux motifs sont définitifs : réessayer à chaque appel serait
        // inutile, et garder le jeton ferait croire l'appareil joignable.
        $envoyeur = app(ApnsVoipSender::class);

        $this->assertTrue($envoyeur->isTokenDead('BadDeviceToken'));
        $this->assertTrue($envoyeur->isTokenDead('Unregistered'));
        // Celui-ci est temporaire : le réessai a du sens.
        $this->assertFalse($envoyeur->isTokenDead('TooManyProviderTokenUpdates'));
    }
}
