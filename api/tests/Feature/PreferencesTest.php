<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Préférences de notification.
 *
 * Trois de ces quatre interrupteurs sont restés sans effet pendant des jours :
 * réglables dans l'app, lus par personne. Ces tests couvrent la moitié qui nous
 * appartient — la lecture et l'écriture — pour qu'au moins celle-là ne
 * régresse plus.
 */
class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_preferences_sont_toutes_actives_au_depart(): void
    {
        [$_, $entetes] = $this->authenticate();

        $reponse = $this->getJson('/api/me/preferences', $entetes)->assertOk();

        foreach (User::NOTIFICATION_PREFERENCES as $preference) {
            $this->assertTrue(
                $reponse->json($preference),
                "{$preference} devrait être activée par défaut.",
            );
        }
    }

    public function test_on_peut_couper_une_categorie_sans_toucher_aux_autres(): void
    {
        [$user, $entetes] = $this->authenticate();

        $this->patchJson('/api/me/preferences',
            ['notify_tasks' => false], $entetes)->assertOk();

        $user->refresh();
        $this->assertFalse((bool) $user->notify_tasks);
        $this->assertTrue((bool) $user->notify_messages);
        $this->assertTrue((bool) $user->notify_projects);
        $this->assertTrue((bool) $user->notify_task_assignment);
    }

    public function test_une_cle_inconnue_est_ignoree(): void
    {
        // L'app d'une version antérieure ou postérieure ne doit pas pouvoir
        // écrire n'importe quelle colonne de `users` par ce point d'entrée.
        [$user, $entetes] = $this->authenticate(['role' => 'member']);

        $this->patchJson('/api/me/preferences',
            ['role' => 'admin', 'notify_messages' => false], $entetes)->assertOk();

        $user->refresh();
        $this->assertSame('member', $user->role);
        $this->assertFalse((bool) $user->notify_messages);
    }

    public function test_la_modification_est_visible_immediatement(): void
    {
        // L'authentification met le compte en cache : sans invalidation, on
        // relirait son propre réglage à l'ancienne valeur pendant une minute.
        [$_, $entetes] = $this->authenticate();

        $this->getJson('/api/me/preferences', $entetes)->assertOk();
        $this->patchJson('/api/me/preferences',
            ['notify_projects' => false], $entetes)->assertOk();

        $this->getJson('/api/me/preferences', $entetes)
            ->assertOk()
            ->assertJsonPath('notify_projects', false);
    }

    public function test_les_preferences_exigent_une_authentification(): void
    {
        $this->getJson('/api/me/preferences')->assertUnauthorized();
        $this->patchJson('/api/me/preferences', ['notify_tasks' => false])
            ->assertUnauthorized();
    }
}
