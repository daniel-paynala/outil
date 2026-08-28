<?php

namespace Tests\Feature;

use App\Modules\Calls\Models\CallLog;
use App\Modules\Calls\Models\VoipDevice;
use App\Modules\Calls\Services\ApnsVoipSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    public function test_un_jeton_android_ne_part_jamais_chez_apple(): void
    {
        // Chaque plateforme a sa voie : PushKit pour iOS, une notification de
        // haute priorité pour Android. Présenter un jeton Android à Apple
        // échouerait à chaque appel, et l'échec ressemblerait à une panne
        // d'APNs plutôt qu'à une erreur d'aiguillage.
        [$_, $entetes] = $this->authenticate();
        [$cible, $__] = $this->authenticate();

        VoipDevice::create([
            'user_id' => $cible->id,
            'token' => 'jeton-android',
            'platform' => 'android',
        ]);

        $this->mock(ApnsVoipSender::class)
            ->shouldNotReceive('ring');

        Http::fake(['*' => Http::response(['id' => 'n1'], 200)]);

        $this->postJson('/api/calls/ring', [
            'call_id' => '11111111-1111-4111-8111-111111111111',
            'to_user_id' => $cible->id,
        ], $entetes)
            ->assertOk()
            ->assertJsonPath('devices', 1)
            // Atteint, mais par l'autre voie.
            ->assertJsonPath('reached', 1);
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

    // ── Historique ──────────────────────────────────────────────────────

    public function test_l_historique_montre_les_appels_passes_et_recus(): void
    {
        // Une seule ligne par appel, écrite par l'appelant : les deux doivent
        // pourtant la voir, chacun de son côté.
        [$a, $entetesA] = $this->authenticate();
        [$b, $entetesB] = $this->authenticate();

        $this->postJson('/api/calls', [
            'callee_id' => $b->id,
            'duration' => 134,
            'end_reason' => 'hungUp',
            'connected_at' => now()->toIso8601String(),
            'route' => 'direct',
        ], $entetesA)->assertCreated();

        $this->getJson('/api/calls', $entetesA)->assertOk()->assertJsonCount(1);
        $this->getJson('/api/calls', $entetesB)->assertOk()->assertJsonCount(1);
    }

    public function test_un_appel_sans_reponse_est_consigne_aussi(): void
    {
        // C'est même la ligne la plus utile : celle qui rappelle qu'on doit
        // rappeler.
        [$_, $entetes] = $this->authenticate();
        [$cible, $__] = $this->authenticate();

        $this->postJson('/api/calls', [
            'callee_id' => $cible->id,
            'duration' => 0,
            'end_reason' => 'unanswered',
        ], $entetes)->assertCreated();

        $this->assertDatabaseHas('call_logs', [
            'callee_id' => $cible->id,
            'end_reason' => 'unanswered',
            'duration' => 0,
        ]);
    }

    public function test_l_historique_ignore_les_appels_des_autres(): void
    {
        [$a, $_] = $this->authenticate();
        [$b, $__] = $this->authenticate();
        [$c, $entetesC] = $this->authenticate();

        CallLog::create([
            'caller_id' => $a->id,
            'callee_id' => $b->id,
            'duration' => 10,
            'end_reason' => 'hungUp',
        ]);

        $this->getJson('/api/calls', $entetesC)->assertOk()->assertJsonCount(0);
    }

    public function test_l_historique_exige_une_authentification(): void
    {
        $this->getJson('/api/calls')->assertUnauthorized();
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

    // ── La sonnerie Android ─────────────────────────────────────────────

    public function test_un_appareil_android_est_sonne_par_notification(): void
    {
        [$appelant, $entetes] = $this->authenticate();
        [$appele] = $this->authenticate();

        VoipDevice::create([
            'id' => (string) Str::uuid(),
            'user_id' => $appele->id,
            'token' => 'jeton-android',
            'platform' => 'android',
        ]);

        Http::fake(['*' => Http::response(['id' => 'n1'], 200)]);

        $this->postJson('/api/calls/ring', [
            'call_id' => (string) Str::uuid(),
            'to_user_id' => $appele->id,
        ], $entetes)->assertOk()->assertJson(['devices' => 1, 'reached' => 1]);

        Http::assertSent(function ($request) use ($appele) {
            $corps = $request->data();

            // Le filtre de plateforme est la seule chose qui empêche un
            // destinataire équipé des deux de sonner deux fois pour un appel.
            return $corps['isIos'] === false
                && $corps['priority'] === 10
                && $corps['data']['type'] === 'call'
                && $corps['include_aliases']['external_id'] === [$appele->id];
        });
    }

    public function test_la_notification_d_appel_expire_avec_la_sonnerie(): void
    {
        [$appelant, $entetes] = $this->authenticate();
        [$appele] = $this->authenticate();

        VoipDevice::create([
            'id' => (string) Str::uuid(),
            'user_id' => $appele->id,
            'token' => 'jeton-android',
            'platform' => 'android',
        ]);

        Http::fake(['*' => Http::response(['id' => 'n1'], 200)]);

        $this->postJson('/api/calls/ring', [
            'call_id' => (string) Str::uuid(),
            'to_user_id' => $appele->id,
        ], $entetes)->assertOk();

        // Une notification livrée après la sonnerie annonce un appel qui
        // n'existe plus : pire que pas de notification du tout.
        Http::assertSent(fn ($request) => $request->data()['ttl'] === 45);
    }

    public function test_un_appareil_ios_ne_recoit_pas_la_notification(): void
    {
        [$appelant, $entetes] = $this->authenticate();
        [$appele] = $this->authenticate();

        VoipDevice::create([
            'id' => (string) Str::uuid(),
            'user_id' => $appele->id,
            'token' => str_repeat('a', 64),
            'platform' => 'ios',
        ]);

        Http::fake();

        $this->postJson('/api/calls/ring', [
            'call_id' => (string) Str::uuid(),
            'to_user_id' => $appele->id,
        ], $entetes)->assertOk();

        // Un iPhone est sonné par PushKit. Lui envoyer en plus une
        // notification le ferait sonner deux fois pour un seul appel.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'onesignal'));
    }
}
