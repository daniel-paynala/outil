<?php

namespace Tests;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Coupe l'indexation pendant les tests.
     *
     * ## Pourquoi c'est le défaut plutôt qu'une option
     *
     * Plusieurs modèles sont `Searchable` : les créer déclenche un appel HTTP
     * vers Meilisearch, qui ne tourne pas ici. La suite échouait donc sur une
     * `CommunicationException` levée à la sauvegarde — une panne réseau
     * déguisée en test rouge, très loin de ce qu'on croyait vérifier.
     *
     * L'indexation n'est de toute façon pas ce que ces tests contrôlent : ils
     * portent sur les règles d'accès et les réponses de l'API. Les tests qui
     * parlent réellement du moteur — la sonde de `MonitoringTest` — remettent
     * le pilote en place eux-mêmes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => null]);
    }

    /**
     * Crée un compte et retourne l'en-tête d'authentification correspondant.
     *
     * ## Pourquoi forger un vrai jeton
     *
     * Il aurait été plus simple de neutraliser le middleware en test. Ce
     * raccourci coûte cher : `EnsureSupabaseAuth` fait la vérification de
     * signature, la synchronisation du compte et — depuis peu — la mise en
     * cache de l'instantané. Le contourner laisserait sans couverture
     * exactement la couche traversée par chaque requête de l'app, et donc celle
     * où une régression toucherait tout d'un coup.
     *
     * Le jeton est signé en HS256 avec le secret de `phpunit.xml`, comme le
     * font les projets Supabase à clé symétrique.
     *
     * @param  array<string, mixed>  $attributes  colonnes à forcer sur le compte
     * @return array{0: User, 1: array<string, string>}
     */
    protected function authenticate(array $attributes = []): array
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'email' => $attributes['email'] ?? Str::uuid().'@arche.test',
            'name' => $attributes['name'] ?? 'Compte de test',
            'role' => $attributes['role'] ?? 'member',
            ...$attributes,
        ]);

        return [$user, $this->tokenHeaderFor($user)];
    }

    /**
     * En-tête porteur pour un compte donné.
     *
     * @return array<string, string>
     */
    protected function tokenHeaderFor(User $user): array
    {
        $token = JWT::encode(
            [
                'sub' => $user->id,
                'email' => $user->email,
                'aud' => 'authenticated',
                'iss' => config('supabase.url').'/auth/v1',
                'iat' => now()->timestamp,
                'exp' => now()->addHour()->timestamp,
            ],
            config('supabase.jwt_secret'),
            'HS256',
        );

        return ['Authorization' => "Bearer {$token}"];
    }
}
