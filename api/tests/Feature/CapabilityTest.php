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

    // ── Accorder et retirer ─────────────────────────────────────────────

    public function test_un_administrateur_accorde_un_droit(): void
    {
        [, $entete] = $this->authenticate(['role' => 'admin']);
        [$cible] = $this->authenticate();

        $this->putJson("/api/admin/users/{$cible->id}/capabilities", [
            'capabilities' => [Capability::Monitoring->value],
        ], $entete)->assertOk();

        $this->assertTrue($cible->fresh()->can(Capability::Monitoring));
    }

    public function test_soumettre_une_liste_vide_retire_tout(): void
    {
        // L'écran envoie l'état voulu, pas une différence : décocher la
        // dernière case doit refermer la porte.
        [, $entete] = $this->authenticate(['role' => 'admin']);
        [$cible] = $this->authenticate();
        $this->accorder($cible->id, Capability::MonitoringAdmin);

        $this->putJson("/api/admin/users/{$cible->id}/capabilities", [
            'capabilities' => [],
        ], $entete)->assertOk();

        $this->assertSame([], $cible->fresh()->capabilities());
    }

    public function test_reenvoyer_les_memes_droits_preserve_la_trace(): void
    {
        // Réécrire des lignes identiques effacerait `granted_by` et
        // `granted_at` — la seule trace de qui a ouvert cette porte, et le jour
        // où on la cherche, on la cherche vraiment.
        [$admin, $entete] = $this->authenticate(['role' => 'admin']);
        [$cible] = $this->authenticate();

        $this->putJson("/api/admin/users/{$cible->id}/capabilities", [
            'capabilities' => [Capability::Monitoring->value],
        ], $entete)->assertOk();

        $pose = DB::table('user_capabilities')
            ->where('user_id', $cible->id)
            ->first();

        $this->travel(1)->hours();

        $this->putJson("/api/admin/users/{$cible->id}/capabilities", [
            'capabilities' => [Capability::Monitoring->value],
        ], $entete)->assertOk();

        $apres = DB::table('user_capabilities')
            ->where('user_id', $cible->id)
            ->first();

        $this->assertSame($pose->granted_at, $apres->granted_at);
        $this->assertSame($admin->id, $apres->granted_by);
    }

    public function test_un_droit_inconnu_est_refuse(): void
    {
        // Sans cette validation, une faute de frappe s'enregistrerait sans rien
        // accorder : l'écran montrerait le droit comme donné, et il ne le
        // serait pas.
        [, $entete] = $this->authenticate(['role' => 'admin']);
        [$cible] = $this->authenticate();

        $this->putJson("/api/admin/users/{$cible->id}/capabilities", [
            'capabilities' => ['monitoring.superviseur'],
        ], $entete)->assertStatus(422);
    }

    public function test_un_porteur_du_droit_ne_peut_pas_le_transmettre(): void
    {
        // Un droit qui se propage de son porteur à qui il veut n'en est plus
        // un : seul un administrateur accorde.
        [$porteur, $entete] = $this->authenticate();
        $this->accorder($porteur->id, Capability::MonitoringAdmin);
        [$cible] = $this->authenticate();

        $this->putJson("/api/admin/users/{$cible->id}/capabilities", [
            'capabilities' => [Capability::Monitoring->value],
        ], $entete)->assertForbidden();

        $this->assertSame([], $cible->fresh()->capabilities());
    }

    public function test_un_droit_retire_cesse_d_agir_tout_de_suite(): void
    {
        // L'authentification garde un instantané court de chaque compte, mais
        // il ne porte que la ligne `users` : les droits sont relus dans leur
        // table à chaque requête. Ce test verrouille cette propriété — le jour
        // où quelqu'un voudra les mettre en cache eux aussi « pour une requête
        // de moins », il apprendra ici ce que ça coûte : une porte qu'on ferme
        // et qui reste ouverte une minute, soit très exactement le temps qu'il
        // faut pour lire ce qu'on vient d'interdire.
        [, $adminEntete] = $this->authenticate(['role' => 'admin']);
        [$cible, $cibleEntete] = $this->authenticate();
        $this->accorder($cible->id, Capability::Monitoring);

        $this->getJson('/api/_test/protegee', $cibleEntete)->assertOk();

        $this->putJson("/api/admin/users/{$cible->id}/capabilities", [
            'capabilities' => [],
        ], $adminEntete)->assertOk();

        $this->getJson('/api/_test/protegee', $cibleEntete)->assertNotFound();
    }
}
