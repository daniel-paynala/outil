<?php

namespace Tests\Feature;

use App\Modules\Monitoring\Models\MonitoredDatabase;
use App\Modules\Monitoring\Models\MonitoringProbe;
use App\Modules\Monitoring\Models\MonitoringProbeWindow;
use App\Modules\Monitoring\Services\DatabaseConnector;
use App\Modules\Monitoring\Support\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les routes de la supervision.
 *
 * Ce qui se joue ici est surtout une frontière : qui peut voir, qui peut
 * brancher une base. Une erreur ouvrirait l'accès à des bases de production
 * entières sans que rien ne l'annonce.
 */
class MonitoringApiTest extends TestCase
{
    use RefreshDatabase;

    private function accorder(string $userId, Capability $droit): void
    {
        DB::table('user_capabilities')->insert([
            'user_id' => $userId,
            'capability' => $droit->value,
            'granted_at' => now(),
        ]);
    }

    private function base(): MonitoredDatabase
    {
        return MonitoredDatabase::create([
            'id' => (string) Str::uuid(),
            'name' => 'Airtel Money',
            'host' => 'db.exemple', 'port' => 5432,
            'dbname' => 'paiements', 'username' => 'lecteur',
            'password' => 'motdepasse-secret',
            'read_only_verified_at' => now(),
        ]);
    }

    private function sonde(MonitoredDatabase $base): MonitoringProbe
    {
        $sonde = MonitoringProbe::create([
            'id' => (string) Str::uuid(),
            'database_id' => $base->id,
            'title' => 'Time-outs',
            'query' => 'select count(*) as valeur from payment',
        ]);

        MonitoringProbeWindow::create([
            'id' => (string) Str::uuid(),
            'probe_id' => $sonde->id,
            'hours' => 24,
            'tiers' => [3, 10],
            'highest_tier' => 10,
        ]);

        return $sonde;
    }

    // ── La frontière ────────────────────────────────────────────────────

    public function test_sans_droit_la_supervision_n_existe_pas(): void
    {
        // 404 et non 403 : un 403 confirmerait qu'Arche surveille des bases, à
        // quelqu'un qui n'a pas à le savoir.
        [$_, $entetes] = $this->authenticate();

        $this->getJson('/api/monitoring/probes', $entetes)->assertNotFound();
        $this->getJson('/api/monitoring/databases', $entetes)->assertNotFound();
    }

    public function test_consulter_ne_permet_pas_de_brancher_une_base(): void
    {
        // La distinction qui structure tout : voir qu'une base va mal et
        // pouvoir en ajouter une avec ses identifiants ne demandent pas la même
        // confiance.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);

        $this->getJson('/api/monitoring/probes', $entetes)->assertOk();

        $this->postJson('/api/monitoring/databases', [
            'name' => 'X', 'host' => 'h', 'dbname' => 'd',
            'username' => 'u', 'password' => 'p',
        ], $entetes)->assertNotFound();
    }

    public function test_administrer_permet_tout(): void
    {
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);

        $this->getJson('/api/monitoring/probes', $entetes)->assertOk();
        $this->getJson('/api/monitoring/alerts', $entetes)->assertOk();
    }

    // ── Le mot de passe ─────────────────────────────────────────────────

    public function test_le_mot_de_passe_ne_repart_jamais(): void
    {
        // La liste des bases se consulte depuis l'app. Laisser fuir cette
        // colonne donnerait les clés de toutes les bases de production de
        // l'entreprise en une requête.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);
        $this->base();

        $reponse = $this->getJson('/api/monitoring/databases', $entetes)->assertOk();

        $this->assertArrayNotHasKey('password', $reponse->json()[0]);
        $reponse->assertDontSee('motdepasse-secret');
    }

    public function test_le_mot_de_passe_est_chiffre_en_base(): void
    {
        $base = $this->base();

        $brut = DB::table('monitored_databases')
            ->where('id', $base->id)
            ->value('password');

        $this->assertNotSame('motdepasse-secret', $brut);
        $this->assertSame('motdepasse-secret', $base->fresh()->password);
    }

    // ── L'acquittement ──────────────────────────────────────────────────

    public function test_acquitter_deplace_le_depart_du_comptage(): void
    {
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);
        $sonde = $this->sonde($this->base());

        $this->postJson("/api/monitoring/probes/{$sonde->id}/acknowledge", [], $entetes)
            ->assertOk();

        $sonde->refresh();
        $this->assertNotNull($sonde->counting_from);
        $this->assertSame($user->id, $sonde->acknowledged_by);
    }

    public function test_acquitter_rouvre_tous_les_paliers(): void
    {
        // Un nouveau franchissement doit se signaler, même plus bas que celui
        // qu'on vient de traiter.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);
        $sonde = $this->sonde($this->base());

        $this->postJson("/api/monitoring/probes/{$sonde->id}/acknowledge", [], $entetes);

        $this->assertSame(0, $sonde->windows()->first()->highest_tier);
    }

    public function test_acquitter_est_ouvert_a_qui_consulte(): void
    {
        // Le réserver aux administrateurs laisserait les alertes ouvertes en
        // attendant qu'un seul d'entre eux passe.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);
        $sonde = $this->sonde($this->base());

        $this->postJson("/api/monitoring/probes/{$sonde->id}/acknowledge", [], $entetes)
            ->assertOk();
    }

    // ── Les sondes ──────────────────────────────────────────────────────

    public function test_une_sonde_sans_fenetre_est_refusee(): void
    {
        // Elle ne s'exécuterait jamais et resterait à l'écran comme si elle
        // veillait — le pire des défauts pour un outil de surveillance.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);
        $base = $this->base();

        $this->postJson('/api/monitoring/probes', [
            'database_id' => $base->id,
            'title' => 'Sans fenêtre',
            'query' => 'select 1 as valeur',
            'windows' => [],
        ], $entetes)->assertStatus(422);
    }

    public function test_les_paliers_sont_tries_et_dedoublonnes(): void
    {
        // Une liste saisie à la main arrive rarement en ordre.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);
        $base = $this->base();

        $id = $this->postJson('/api/monitoring/probes', [
            'database_id' => $base->id,
            'title' => 'Time-outs',
            'query' => 'select count(*) as valeur from payment',
            'windows' => [['hours' => 24, 'tiers' => [10, 3, 10, 20]]],
        ], $entetes)->assertCreated()->json('id');

        $this->assertSame(
            [3, 10, 20],
            MonitoringProbe::find($id)->windows->first()->tiers,
        );
    }

    public function test_modifier_les_paliers_remet_l_etat_a_zero(): void
    {
        // Changer l'échelle change le sens de « plus haut palier signalé ».
        // Le garder tairait le premier franchissement de la nouvelle échelle.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);
        $sonde = $this->sonde($this->base());

        $this->patchJson("/api/monitoring/probes/{$sonde->id}", [
            'database_id' => $sonde->database_id,
            'title' => 'Time-outs',
            'query' => 'select count(*) as valeur from payment',
            'windows' => [['hours' => 24, 'tiers' => [5, 50]]],
        ], $entetes)->assertOk();

        $this->assertSame(0, $sonde->windows()->first()->highest_tier);
    }

    public function test_essayer_une_requete_sur_une_base_non_verifiee_est_refuse(): void
    {
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);
        $base = $this->base();
        $base->update(['read_only_verified_at' => null]);

        $this->postJson('/api/monitoring/probes/try', [
            'database_id' => $base->id,
            'query' => 'select 1 as valeur',
        ], $entetes)->assertStatus(422);
    }

    // ── Modifier une base sans la débrancher ────────────────────────────

    public function test_renommer_ne_touche_pas_a_la_connexion(): void
    {
        // Un libellé est une étiquette. Le faire repasser par une tentative de
        // connexion rendrait le renommage impossible quand la base est
        // justement injoignable — c'est-à-dire au moment où on veut le plus
        // écrire « (en panne) » à côté de son nom.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);
        $base = $this->base();

        $this->mock(DatabaseConnector::class)
            ->shouldNotReceive('verifyReadOnly');

        $this->patchJson("/api/monitoring/databases/{$base->id}", [
            'name' => 'Airtel Money — production',
        ], $entetes)->assertOk()->assertJsonPath('name', 'Airtel Money — production');
    }

    public function test_renommer_preserve_les_sondes_et_leur_comptage(): void
    {
        // C'est la raison d'être de cette route. Supprimer puis rebrancher
        // emporterait les sondes par cascade, et avec elles les paliers déjà
        // signalés : renommer rouvrirait tous les incidents déjà traités.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);
        $base = $this->base();
        $sonde = $this->sonde($base);

        $this->patchJson("/api/monitoring/databases/{$base->id}", [
            'name' => 'Autre nom',
        ], $entetes)->assertOk();

        $this->assertDatabaseHas('monitoring_probes', ['id' => $sonde->id]);
        $this->assertSame(10, $sonde->windows()->first()->highest_tier);
    }

    public function test_changer_le_mot_de_passe_repasse_par_la_verification(): void
    {
        // Sans cela, une rotation remplacerait en silence un compte de lecture
        // par un compte d'écriture sur une base de production.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);
        $base = $this->base();

        $this->mock(DatabaseConnector::class)
            ->shouldReceive('verifyReadOnly')
            ->once()
            ->andReturn(['ok' => true, 'error' => null]);

        $this->patchJson("/api/monitoring/databases/{$base->id}", [
            'password' => 'le-nouveau',
        ], $entetes)->assertOk();

        $this->assertSame('le-nouveau', $base->fresh()->password);
    }

    public function test_une_modification_refusee_ne_laisse_aucune_trace(): void
    {
        // Un `update` suivi d'un refus laisserait en base les identifiants
        // qu'on vient précisément de juger inacceptables.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);
        $base = $this->base();

        $this->mock(DatabaseConnector::class)
            ->shouldReceive('verifyReadOnly')
            ->once()
            ->andReturn([
                'ok' => false,
                'error' => 'Ces identifiants permettent d\'écrire.',
            ]);

        $this->patchJson("/api/monitoring/databases/{$base->id}", [
            'name' => 'Renommée au passage',
            'username' => 'compte_admin',
            'password' => 'trop-puissant',
        ], $entetes)->assertStatus(422);

        // Ni les identifiants, ni le nom envoyé dans la même requête : la
        // modification est un tout, elle passe ou elle ne passe pas.
        $apres = $base->fresh();
        $this->assertSame('lecteur', $apres->username);
        $this->assertSame('motdepasse-secret', $apres->password);
        $this->assertSame('Airtel Money', $apres->name);
    }

    public function test_consulter_ne_permet_pas_de_renommer_une_base(): void
    {
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);
        $base = $this->base();

        $this->patchJson("/api/monitoring/databases/{$base->id}", [
            'name' => 'Renommée sans droit',
        ], $entetes)->assertNotFound();

        $this->assertSame('Airtel Money', $base->fresh()->name);
    }
}
