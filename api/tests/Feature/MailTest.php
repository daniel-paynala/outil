<?php

namespace Tests\Feature;

use App\Modules\Mail\Jobs\PollGmailInboxes;
use App\Modules\Mail\Models\GoogleAccount;
use App\Modules\Mail\Services\GmailReader;
use App\Modules\Mail\Services\GoogleOAuth;
use App\Modules\Messagerie\Services\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rattachement d'une boîte Google Workspace, et relève de ce qui y arrive.
 *
 * Deux zones à risque.
 *
 * Le **jeton de rafraîchissement** donne un accès permanent à la boîte de
 * quelqu'un. Qu'il ne sorte jamais par une réponse JSON et qu'il soit chiffré
 * au repos n'est pas une précaution de forme.
 *
 * La **relève** tourne toutes les deux minutes sans que personne ne la
 * regarde. Un compte en échec ne doit pas bloquer les autres, une autorisation
 * révoquée ne doit pas être réessayée sept cents fois par jour, et une boîte
 * tout juste rattachée ne doit pas notifier tout son historique.
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

    public function test_le_statut_distingue_une_releve_arretee_d_une_absence_de_compte(): void
    {
        // Confondre les deux ferait dire à l'app que tout va bien alors
        // qu'aucune notification n'arrivera plus.
        [$user, $entetes] = $this->authenticate();

        GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton',
            'last_polled_at' => now()->subHour(),
        ]);

        $this->getJson('/api/mail/status', $entetes)
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('polling_healthy', false);
    }

    public function test_une_releve_recente_est_consideree_saine(): void
    {
        // Le seuil est large au regard de l'intervalle de deux minutes : un
        // planificateur redémarré ne doit pas faire clignoter l'écran.
        [$user, $entetes] = $this->authenticate();

        GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton',
            'last_polled_at' => now()->subMinutes(5),
        ]);

        $this->getJson('/api/mail/status', $entetes)
            ->assertOk()
            ->assertJsonPath('polling_healthy', true);
    }

    public function test_le_jeton_de_rafraichissement_ne_sort_jamais_par_l_api(): void
    {
        [$user, $entetes] = $this->authenticate();

        GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'CE-JETON-NE-DOIT-JAMAIS-SORTIR',
            'last_polled_at' => now(),
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

        $brut = DB::table('google_accounts')->value('refresh_token');

        $this->assertNotSame('jeton-en-clair', $brut);
        $this->assertSame('jeton-en-clair', GoogleAccount::first()->refresh_token);
    }

    // ── Rattachement ────────────────────────────────────────────────────

    public function test_une_adresse_hors_du_domaine_est_refusee(): void
    {
        // Sans ce garde-fou, quelqu'un ferait relever sa boîte personnelle par
        // le serveur de l'entreprise.
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
        // Google n'en rend un qu'à la première autorisation. Accepter sans lui
        // donnerait une relève qui meurt une heure plus tard.
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

    public function test_un_rattachement_reussi_prend_le_curseur_sans_notifier(): void
    {
        // Sans cette première lecture, la connexion notifierait chaque message
        // déjà présent dans la boîte.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'refresh_token' => 'jeton-long',
                'access_token' => 'jeton-court',
                'scope' => 'https://www.googleapis.com/auth/gmail.modify',
            ], 200),
            'gmail.googleapis.com/*/profile' => Http::response([
                'emailAddress' => 'daniel@paynala.com',
                'historyId' => '987654',
            ], 200),
        ]);

        [$_, $entetes] = $this->authenticate();

        $this->postJson('/api/mail/connect', [
            'server_auth_code' => 'code',
            'email' => 'Daniel@Paynala.com',
        ], $entetes)
            ->assertCreated()
            ->assertJsonPath('polling_healthy', true);

        $compte = GoogleAccount::first();
        // L'adresse est normalisée : les comparaisons ultérieures échoueraient
        // sinon sur une différence de casse.
        $this->assertSame('daniel@paynala.com', $compte->email);
        $this->assertSame('987654', $compte->history_id);
        $this->assertNotNull($compte->last_polled_at);
    }

    public function test_un_refus_de_gmail_n_annule_pas_le_rattachement(): void
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
                'error' => ['message' => 'Insufficient Permission'],
            ], 403),
        ]);

        [$_, $entetes] = $this->authenticate();

        $reponse = $this->postJson('/api/mail/connect', [
            'server_auth_code' => 'code',
            'email' => 'daniel@paynala.com',
        ], $entetes)->assertCreated();

        $this->assertFalse($reponse->json('polling_healthy'));
        $this->assertNotNull($reponse->json('warning'));
        $this->assertDatabaseCount('google_accounts', 1);
        $this->assertNotNull(GoogleAccount::first()->last_error);
    }

    // ── Relève ──────────────────────────────────────────────────────────

    public function test_la_releve_fait_avancer_le_curseur(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x'], 200),
            'gmail.googleapis.com/*/history*' => Http::response([
                'historyId' => '200',
                'history' => [],
            ], 200),
        ]);

        [$user, $_] = $this->authenticate();
        $compte = GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton',
            'history_id' => '100',
        ]);

        (new PollGmailInboxes)->handle(
            app(GmailReader::class),
            app(GoogleOAuth::class),
            app(PushSender::class),
        );

        // Sans cet avancement, le même historique serait redemandé
        // indéfiniment, deux minutes après deux minutes.
        $this->assertSame('200', $compte->fresh()->history_id);
        $this->assertNotNull($compte->fresh()->last_polled_at);
    }

    public function test_un_curseur_trop_ancien_fait_repartir_du_present(): void
    {
        // Google ne garde qu'une fenêtre glissante d'historique. Sans cette
        // reprise, la relève resterait bloquée sur un curseur mort.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x'], 200),
            'gmail.googleapis.com/*/history*' => Http::response([], 404),
            'gmail.googleapis.com/*/profile' => Http::response(['historyId' => '999'], 200),
        ]);

        [$user, $_] = $this->authenticate();
        $compte = GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton',
            'history_id' => '1',
        ]);

        (new PollGmailInboxes)->handle(
            app(GmailReader::class),
            app(GoogleOAuth::class),
            app(PushSender::class),
        );

        $this->assertSame('999', $compte->fresh()->history_id);
    }

    public function test_un_compte_en_echec_n_empeche_pas_les_autres(): void
    {
        // Chacun dans son propre `try` : une autorisation cassée chez l'un ne
        // doit pas priver les quatre autres de notifications.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x'], 200),
            'gmail.googleapis.com/*/history*' => Http::sequence()
                ->push(['error' => ['message' => 'Invalid Credentials']], 401)
                ->push(['historyId' => '300', 'history' => []], 200),
        ]);

        [$a, $_] = $this->authenticate();
        [$b, $__] = $this->authenticate();

        $casse = GoogleAccount::create([
            'user_id' => $a->id, 'email' => 'a@paynala.com',
            'refresh_token' => 'jeton-a', 'history_id' => '10',
        ]);
        $sain = GoogleAccount::create([
            'user_id' => $b->id, 'email' => 'b@paynala.com',
            'refresh_token' => 'jeton-b', 'history_id' => '20',
        ]);

        (new PollGmailInboxes)->handle(
            app(GmailReader::class),
            app(GoogleOAuth::class),
            app(PushSender::class),
        );

        $this->assertNotNull($casse->fresh()->last_error);
        $this->assertSame('300', $sain->fresh()->history_id);
    }

    public function test_une_autorisation_perdue_est_mise_en_quarantaine(): void
    {
        // Sans quarantaine, un compte révoqué serait réessayé sept cents fois
        // par jour, pour échouer identiquement à chaque fois.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x'], 200),
            'gmail.googleapis.com/*' => Http::response(['historyId' => '500'], 200),
        ]);

        [$user, $_] = $this->authenticate();
        $compte = GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton',
            'history_id' => '10',
            'last_error' => 'invalid_grant — Token has been expired or revoked.',
            'last_error_at' => now()->subMinutes(5),
        ]);

        (new PollGmailInboxes)->handle(
            app(GmailReader::class),
            app(GoogleOAuth::class),
            app(PushSender::class),
        );

        // Curseur inchangé : le compte n'a pas été relevé.
        $this->assertSame('10', $compte->fresh()->history_id);
        Http::assertNothingSent();
    }

    public function test_une_erreur_passagere_n_est_pas_mise_en_quarantaine(): void
    {
        // Une coupure réseau doit être réessayée deux minutes plus tard, pas
        // une heure plus tard.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'x'], 200),
            'gmail.googleapis.com/*/history*' => Http::response([
                'historyId' => '400', 'history' => [],
            ], 200),
        ]);

        [$user, $_] = $this->authenticate();
        $compte = GoogleAccount::create([
            'user_id' => $user->id,
            'email' => 'daniel@paynala.com',
            'refresh_token' => 'jeton',
            'history_id' => '10',
            'last_error' => 'cURL error 28: Connection timed out',
            'last_error_at' => now()->subMinutes(2),
        ]);

        (new PollGmailInboxes)->handle(
            app(GmailReader::class),
            app(GoogleOAuth::class),
            app(PushSender::class),
        );

        $this->assertSame('400', $compte->fresh()->history_id);
        $this->assertNull($compte->fresh()->last_error);
    }
}
