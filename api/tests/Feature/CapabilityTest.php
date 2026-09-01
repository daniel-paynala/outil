<?php

namespace Tests\Feature;

use App\Modules\Monitoring\Support\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Les droits accordés au cas par cas.
 *
 * ## Ce qui se joue
 *
 * Arche n'avait que deux niveaux : membre et administrateur. La supervision
 * rompt cette règle — elle donne à voir des bases de production entières, ce
 * qui n'est ni « pour tout le monde » ni « réservé aux administrateurs ».
 *
 * Une erreur ici ne se voit pas : elle ouvre une porte, ou en ferme une, sans
 * que rien ne l'annonce.
 */
class CapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Une route de test plutôt qu'une vraie : ce qu'on vérifie est le
        // middleware, et l'attacher à une route métier ferait dépendre ces
        // tests de ce que cette route devient.
        Route::middleware(['supabase.auth', 'capability:monitoring'])
            ->get('/api/_test/protegee', fn () => response()->json(['ok' => true]));
    }

    private function accorder(string $userId, Capability $droit): void
    {
        DB::table('user_capabilities')->insert([
            'user_id' => $userId,
            'capability' => $droit->value,
            'granted_at' => now(),
        ]);
    }

    // ── Le modèle ───────────────────────────────────────────────────────

    public function test_un_membre_ordinaire_n_a_aucun_droit(): void
    {
        [$user] = $this->authenticate();

        $this->assertSame([], $user->capabilities());
        $this->assertFalse($user->can(Capability::Monitoring));
    }

    public function test_un_droit_accorde_est_rendu(): void
    {
        [$user] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);

        $this->assertTrue($user->can(Capability::Monitoring));
    }

    public function test_administrer_implique_consulter(): void
    {
        // Administrer la supervision sans pouvoir la consulter n'aurait aucun
        // sens ; l'exiger séparément se solderait par un oubli.
        [$user] = $this->authenticate();
        $this->accorder($user->id, Capability::MonitoringAdmin);

        $this->assertTrue($user->can(Capability::Monitoring));
        $this->assertTrue($user->can(Capability::MonitoringAdmin));
    }

    public function test_consulter_n_implique_pas_administrer(): void
    {
        // L'implication ne va que dans un sens : voir les bases ne donne pas le
        // droit d'en ajouter une.
        [$user] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);

        $this->assertFalse($user->can(Capability::MonitoringAdmin));
    }

    public function test_un_administrateur_a_tous_les_droits(): void
    {
        // Sans cette règle, accorder un droit exigerait déjà de l'avoir, et
        // personne ne pourrait accorder le premier.
        [$user] = $this->authenticate(['role' => 'admin']);

        $this->assertTrue($user->can(Capability::Monitoring));
        $this->assertTrue($user->can(Capability::MonitoringAdmin));
    }

    public function test_un_droit_inconnu_en_base_est_ignore(): void
    {
        // Un droit retiré du code laisse ses lignes derrière lui. Les lire sans
        // les reconnaître ne doit rien accorder — et surtout rien casser.
        [$user] = $this->authenticate();
        DB::table('user_capabilities')->insert([
            'user_id' => $user->id,
            'capability' => 'droit.disparu',
            'granted_at' => now(),
        ]);

        $this->assertSame([], $user->capabilities());
    }

    // ── Le middleware ───────────────────────────────────────────────────

    public function test_sans_le_droit_la_route_est_introuvable(): void
    {
        // 404 et non 403 : un 403 confirmerait qu'Arche surveille des bases, à
        // quelqu'un qui n'a pas à le savoir.
        [$_, $entetes] = $this->authenticate();

        $this->getJson('/api/_test/protegee', $entetes)->assertNotFound();
    }

    public function test_avec_le_droit_la_route_repond(): void
    {
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);

        $this->getJson('/api/_test/protegee', $entetes)
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_sans_authentification_c_est_un_401(): void
    {
        // L'ordre des middlewares compte : l'authentification passe d'abord,
        // sans quoi une requête anonyme recevrait « introuvable » et personne
        // ne comprendrait qu'il fallait se connecter.
        $this->getJson('/api/_test/protegee')->assertUnauthorized();
    }

    // ── Ce que l'app reçoit ─────────────────────────────────────────────

    public function test_me_annonce_les_droits(): void
    {
        // Sans eux, l'app afficherait un menu qui n'échoue qu'au tap — une
        // porte qu'on voit et qui ne s'ouvre pas est pire qu'une porte absente.
        [$user, $entetes] = $this->authenticate();
        $this->accorder($user->id, Capability::Monitoring);

        $this->getJson('/api/me', $entetes)
            ->assertOk()
            ->assertJsonPath('capabilities', ['monitoring']);
    }

    public function test_me_n_annonce_rien_a_un_membre_ordinaire(): void
    {
        [$_, $entetes] = $this->authenticate();

        $this->getJson('/api/me', $entetes)
            ->assertOk()
            ->assertJsonPath('capabilities', []);
    }
}
