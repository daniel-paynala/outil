<?php

namespace Tests\Feature;

use App\Modules\Mail\Jobs\NotifyNewMail;
use App\Modules\Mail\Models\GoogleAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rattachement d'une boîte Google Workspace.
 *
 * Deux zones à risque et une seule vraiment dangereuse.
 *
 * Le point d'entrée Pub/Sub est **public par nécessité** — c'est Google qui
 * appelle, aucun jeton Supabase ne peut accompagner la requête — et il
 * déclenche du travail. Sa protection est un secret partagé, et c'est
 * exactement le genre de garde qu'on casse sans s'en apercevoir.
 *
 * Le jeton de rafraîchissement, lui, donne un accès permanent à la boîte de
 * quelqu'un. Qu'il ne sorte jamais par une réponse JSON n'est pas une
 * précaution de forme.
 */
class MailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'google.client_id' => 'client-de-test.apps.googleusercontent.com',
            'google.client_secret' => 'secret-de-test',
            'google.workspace_domain' => 'paynala.com',
            'google.topic' => 'projects/arche/topics/gmail',
            'google.pubsub_token' => 'jeton-partage-de-test',
        ]);
    }

    // ── État de la connexion ────────────────────────────────────────────

    public function test_le_statut_exige_une_authentification(): void
    {
        $this->getJson('/api/mail/status')->assertUnauthorized();
    }

    public function test_le_statut_dit_qu_aucune_boite_n_est_rattachee(): void
    {
        [$_, $entetes] = $this->authenticate();

        $this->getJson('/api/mail/status', $entetes)
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('email', null)
            ->assertJsonPath('configured', true);
    }

    public function test_le_statut_distingue_une_surveillance_eteinte_d_une_absence_de_compte(): void
    {
        // Confondre les deux ferait dire à l'app que tout va bien alors
        // qu'aucune notification n'arrivera plus.
        [$user, $entetes] = $this->authenticate();

        GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton',
            'watch_expires_at' => now()->subDay(),
        ]);

        $this->getJson('/api/mail/status', $entetes)
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('watch_healthy', false);
    }

    public function test_le_jeton_de_rafraichissement_ne_sort_jamais_par_l_api(): void
    {
        [$user, $entetes] = $this->authenticate();

        GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'CE-JETON-NE-DOIT-JAMAIS-SORTIR',
            'watch_expires_at' => now()->addDays(6),
        ]);

        $reponse = $this->getJson('/api/mail/status', $entetes)->assertOk();

        $this->assertStringNotContainsString(
            'CE-JETON-NE-DOIT-JAMAIS-SORTIR',
            $reponse->getContent(),
        );
    }

    public function test_le_jeton_est_chiffre_au_repos(): void
    {
        // Une fuite de la base seule ne doit rien donner sans l'APP_KEY.
        [$user, $_] = $this->authenticate();

        GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton-en-clair',
        ]);

        $brut = DB::table('google_accounts')
            ->value('refresh_token');

        $this->assertNotSame('jeton-en-clair', $brut);
        $this->assertSame(
            'jeton-en-clair',
            GoogleAccount::first()->refresh_token,
        );
    }

    // ── Rattachement ────────────────────────────────────────────────────

    public function test_une_adresse_hors_du_domaine_est_refusee(): void
    {
        // Sans ce garde-fou, quelqu'un ferait surveiller sa boîte personnelle
        // par le serveur de l'entreprise.
        [$_, $entetes] = $this->authenticate();

        $this->postJson('/api/mail/connect', [
            'server_auth_code' => 'code',
            'email' => 'quelquun@gmail.com',
        ], $entetes)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'paynala.com'));

        $this->assertDatabaseCount('google_accounts', 0);
    }

    public function test_un_echange_sans_jeton_de_rafraichissement_est_refuse(): void
    {
        // Google n'en rend qu'à la première autorisation. Accepter sans lui
        // donnerait une surveillance qui meurt une heure plus tard.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'jeton-court',
            ], 200),
        ]);

        [$_, $entetes] = $this->authenticate();

        $this->postJson('/api/mail/connect', [
            'server_auth_code' => 'code',
            'email' => 'daniel@paynala.com',
        ], $entetes)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'révoquer'));
    }

    public function test_un_rattachement_reussi_demarre_la_surveillance(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'refresh_token' => 'jeton-long',
                'access_token' => 'jeton-court',
                'scope' => 'https://www.googleapis.com/auth/gmail.modify',
            ], 200),
            'gmail.googleapis.com/*/watch' => Http::response([
                'historyId' => '987654',
                'expiration' => (string) (now()->addDays(7)->timestamp * 1000),
            ], 200),
        ]);

        [$user, $entetes] = $this->authenticate();

        $this->postJson('/api/mail/connect', [
            'server_auth_code' => 'code',
            'email' => 'Daniel@Paynala.com',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('watch_healthy', true);

        $compte = GoogleAccount::first();
        // L'adresse est normalisée : Pub/Sub la rendra en minuscules, et la
        // recherche du compte échouerait sinon.
        $this->assertSame('daniel@paynala.com', $compte->email);
        $this->assertSame('987654', $compte->history_id);
        $this->assertTrue($compte->watch_expires_at->isFuture());
    }

    public function test_une_surveillance_qui_ne_demarre_pas_n_annule_pas_le_rattachement(): void
    {
        // Lire et écrire fonctionneront depuis l'app : seules les
        // notifications manquent, et le dire vaut mieux que d'annuler une
        // connexion par ailleurs réussie.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'refresh_token' => 'jeton-long',
                'access_token' => 'jeton-court',
            ], 200),
            'gmail.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Sujet Pub/Sub introuvable'],
            ], 400),
        ]);

        [$_, $entetes] = $this->authenticate();

        $reponse = $this->postJson('/api/mail/connect', [
            'server_auth_code' => 'code',
            'email' => 'daniel@paynala.com',
        ], $entetes)->assertCreated();

        $this->assertFalse($reponse->json('watch_healthy'));
        $this->assertNotNull($reponse->json('warning'));
        $this->assertDatabaseCount('google_accounts', 1);
        $this->assertNotNull(GoogleAccount::first()->last_error);
    }

    // ── Réception Pub/Sub ───────────────────────────────────────────────

    public function test_la_reception_refuse_un_appel_sans_secret(): void
    {
        Bus::fake();

        $this->postJson('/api/mail/pubsub', ['message' => []])
            ->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_la_reception_refuse_un_mauvais_secret(): void
    {
        Bus::fake();

        $this->postJson('/api/mail/pubsub?token=pas-le-bon', ['message' => []])
            ->assertForbidden();

        Bus::assertNothingDispatched();
    }

    public function test_la_reception_declenche_le_travail_pour_le_bon_compte(): void
    {
        Bus::fake();

        [$user, $_] = $this->authenticate();
        GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton',
        ]);

        $this->postJson(
            '/api/mail/pubsub?token=jeton-partage-de-test',
            $this->avis('daniel@paynala.com', 'avis-1'),
        )->assertNoContent();

        Bus::assertDispatched(NotifyNewMail::class);
    }

    public function test_un_avis_deja_traite_est_acquitte_sans_retravail(): void
    {
        // Pub/Sub garantit « au moins une fois » : sans déduplication, une
        // seule arrivée produirait plusieurs notifications identiques.
        Bus::fake();
        Cache::flush();

        [$user, $_] = $this->authenticate();
        GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton',
        ]);

        $avis = $this->avis('daniel@paynala.com', 'avis-repete');

        $this->postJson('/api/mail/pubsub?token=jeton-partage-de-test', $avis)
            ->assertNoContent();
        $this->postJson('/api/mail/pubsub?token=jeton-partage-de-test', $avis)
            ->assertNoContent();

        Bus::assertDispatchedTimes(NotifyNewMail::class, 1);
    }

    public function test_une_charge_illisible_est_acquittee_et_non_retentee(): void
    {
        // Répondre en erreur ferait insister Google pendant sept jours sur un
        // message qui ne deviendra jamais lisible.
        Bus::fake();

        $this->postJson('/api/mail/pubsub?token=jeton-partage-de-test', [
            'message' => ['data' => 'pas-du-base64-valide!!', 'messageId' => 'x'],
        ])->assertNoContent();

        Bus::assertNothingDispatched();
    }

    public function test_un_avis_pour_une_boite_inconnue_est_acquitte(): void
    {
        Bus::fake();

        $this->postJson(
            '/api/mail/pubsub?token=jeton-partage-de-test',
            $this->avis('inconnu@paynala.com', 'avis-orphelin'),
        )->assertNoContent();

        Bus::assertNothingDispatched();
    }

    // ── Renouvellement ──────────────────────────────────────────────────

    public function test_une_surveillance_proche_de_l_expiration_doit_etre_renouvelee(): void
    {
        $compte = new GoogleAccount(['watch_expires_at' => now()->addHours(12)]);
        $this->assertTrue($compte->watchNeedsRenewal());

        $compte = new GoogleAccount(['watch_expires_at' => now()->addDays(5)]);
        $this->assertFalse($compte->watchNeedsRenewal());

        // Jamais démarrée : à renouveler, évidemment.
        $compte = new GoogleAccount(['watch_expires_at' => null]);
        $this->assertTrue($compte->watchNeedsRenewal());
    }

    /**
     * Enveloppe Pub/Sub telle que Google la publie.
     *
     * @return array<string, mixed>
     */
    private function avis(string $email, string $messageId): array
    {
        return [
            'message' => [
                'data' => base64_encode(json_encode([
                    'emailAddress' => $email,
                    'historyId' => 123456,
                ])),
                'messageId' => $messageId,
            ],
            'subscription' => 'projects/arche/subscriptions/gmail-push',
        ];
    }
}
