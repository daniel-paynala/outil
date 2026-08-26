<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Services\SupabaseUserSync;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le chemin traversé par **chaque** requête de l'app.
 *
 * C'est la couche la moins visible et la plus coûteuse à casser : une
 * régression ici ne touche pas un écran, elle les touche tous. Elle porte en
 * plus, depuis peu, un cache d'instantané de compte — donc un risque de servir
 * des données périmées qu'aucun autre test ne verrait.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_requete_sans_jeton_est_refusee(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_un_jeton_signe_avec_un_autre_secret_est_refuse(): void
    {
        $token = JWT::encode(
            ['sub' => (string) Str::uuid(), 'email' => 'intrus@arche.test',
                'exp' => now()->addHour()->timestamp],
            'un-autre-secret-de-32-octets-au-moins-pour-hs256',
            'HS256',
        );

        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])
            ->assertUnauthorized();
    }

    public function test_un_jeton_expire_est_refuse(): void
    {
        $token = JWT::encode(
            ['sub' => (string) Str::uuid(), 'email' => 'ancien@arche.test',
                'iat' => now()->subDay()->timestamp,
                'exp' => now()->subHour()->timestamp],
            config('supabase.jwt_secret'),
            'HS256',
        );

        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])
            ->assertUnauthorized();
    }

    public function test_un_jeton_valide_cree_le_compte_local_absent(): void
    {
        // Supabase tient l'authentification, mais toutes les clés étrangères
        // d'Arche pointent vers `users` : la ligne doit exister au premier
        // appel, sans quoi la première assignation de tâche échouerait.
        $id = (string) Str::uuid();
        $token = JWT::encode(
            ['sub' => $id, 'email' => 'nouveau@arche.test',
                'exp' => now()->addHour()->timestamp],
            config('supabase.jwt_secret'),
            'HS256',
        );

        $this->assertDatabaseCount('users', 0);

        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $id, 'email' => 'nouveau@arche.test']);
    }

    public function test_le_cache_evite_de_relire_le_compte_a_chaque_requete(): void
    {
        [$user, $headers] = $this->authenticate();

        // Premier appel : le compte est lu (et écrit) en base.
        $this->getJson('/api/me', $headers)->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/me', $headers)->assertOk();
        $requetes = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'users'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(
            0,
            $requetes,
            "L'authentification doit servir l'instantané mis en cache : chaque "
            .'requête à la base coûte 160 ms depuis Libreville.',
        );
        $this->assertNotNull($user->fresh());
    }

    public function test_une_modification_du_compte_invalide_immediatement_le_cache(): void
    {
        // Le pendant indispensable du test précédent : un cache qui ne
        // s'invalide pas ferait ignorer son propre changement à l'utilisateur
        // pendant une minute, ce qui se lit comme un bug.
        [$user, $headers] = $this->authenticate(['name' => 'Avant']);
        $this->getJson('/api/me', $headers)->assertOk();

        $user->update(['name' => 'Après']);

        $this->getJson('/api/me', $headers)
            ->assertOk()
            ->assertJsonPath('user.name', 'Après');
    }

    public function test_forget_supprime_l_instantane(): void
    {
        [$user, $headers] = $this->authenticate();
        $this->getJson('/api/me', $headers)->assertOk();

        SupabaseUserSync::forget($user->id);

        DB::enableQueryLog();
        $this->getJson('/api/me', $headers)->assertOk();
        $relu = collect(DB::getQueryLog())
            ->contains(fn ($q) => str_contains($q['query'], 'users'));
        DB::disableQueryLog();

        $this->assertTrue($relu, 'Après oubli, le compte doit être relu en base.');
    }

    public function test_un_email_modifie_cote_supabase_est_repercute(): void
    {
        [$user, $_] = $this->authenticate(['email' => 'ancien@arche.test']);

        $token = JWT::encode(
            ['sub' => $user->id, 'email' => 'nouveau@arche.test',
                'exp' => now()->addHour()->timestamp],
            config('supabase.jwt_secret'),
            'HS256',
        );

        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->assertSame('nouveau@arche.test', $user->fresh()->email);
    }

    public function test_le_role_n_est_jamais_ecrase_par_la_synchronisation(): void
    {
        // `role` appartient à Arche, pas à Supabase : une resynchronisation ne
        // doit jamais rétrograder un administrateur.
        [$user, $headers] = $this->authenticate(['role' => 'admin']);

        $this->getJson('/api/me', $headers)->assertOk();

        $this->assertSame('admin', $user->fresh()->role);
    }

    public function test_les_preferences_de_notification_sont_actives_par_defaut(): void
    {
        // Le silence ne se choisit pas par omission : quelqu'un qui n'a jamais
        // ouvert les réglages doit recevoir ce qui le concerne.
        [$user, $_] = $this->authenticate();

        foreach (User::NOTIFICATION_PREFERENCES as $preference) {
            $this->assertTrue(
                (bool) $user->fresh()->{$preference},
                "{$preference} devrait être activée par défaut.",
            );
        }
    }
}
