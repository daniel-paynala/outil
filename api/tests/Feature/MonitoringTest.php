<?php

namespace Tests\Feature;

use App\Modules\Messagerie\Services\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Les sondes elles-mêmes.
 *
 * Une sonde qui tombe en panne est le pire des cas : elle annonce que tout va
 * bien, et c'est précisément à elle qu'on fait confiance quand on cherche une
 * panne. Ces tests vérifient qu'elle survit à ce qu'elle est censée détecter —
 * moteur injoignable, cache vide, journal absent — au lieu de propager
 * l'erreur.
 */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_sonde_de_file_reste_publique(): void
    {
        // Volontairement hors authentification : elle doit rester consultable
        // quand c'est justement l'authentification qui est en panne.
        $this->getJson('/api/monitoring/queue')
            ->assertOk()
            ->assertJsonStructure(['connection', 'pending', 'failed', 'status']);
    }

    public function test_la_sonde_de_recherche_survit_a_un_moteur_injoignable(): void
    {
        config([
            'scout.driver' => 'meilisearch',
            'scout.meilisearch.host' => 'http://127.0.0.1:1',
        ]);

        $this->getJson('/api/monitoring/search')
            ->assertOk()
            ->assertJsonPath('reachable', false)
            ->assertJsonPath('status', 'down')
            // Le conseil compte autant que le constat : sans lui on relance le
            // mauvais service.
            ->assertJsonStructure(['hint']);
    }

    public function test_la_sonde_de_recherche_ne_cherche_pas_d_index_hors_meilisearch(): void
    {
        // Scout en mode `collection` interroge la base : il n'y a pas d'index à
        // comparer, et annoncer une panne enverrait chercher un moteur qui
        // n'existe pas.
        config(['scout.driver' => 'collection']);

        $this->getJson('/api/monitoring/search')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('indexes', []);
    }

    public function test_la_sonde_de_push_distingue_l_absence_d_envoi_d_une_panne(): void
    {
        Cache::forget(PushSender::LAST_ATTEMPT_KEY);
        config(['onesignal.app_id' => 'test', 'onesignal.rest_key' => 'test']);

        // À cinq personnes, une journée sans message est banale : « aucun
        // envoi » est un état neutre, pas une alerte.
        $this->getJson('/api/monitoring/push')
            ->assertOk()
            ->assertJsonPath('status', 'unknown');
    }

    public function test_la_sonde_de_push_rapporte_le_motif_d_un_refus(): void
    {
        config(['onesignal.app_id' => 'test', 'onesignal.rest_key' => 'test']);
        Cache::put(PushSender::LAST_ATTEMPT_KEY, [
            'at' => now()->toIso8601String(),
            'ok' => false,
            'recipients' => 2,
            'status' => 400,
            'error' => '{"invalid_aliases":{"external_id":["abc"]}}',
        ], 60);

        $reponse = $this->getJson('/api/monitoring/push')->assertOk()
            ->assertJsonPath('status', 'warn');

        // Le cas de loin le plus fréquent, et le seul qui ne soit pas un bug :
        // le destinataire a refusé les notifications sur son appareil. Le dire
        // évite de chercher une panne serveur inexistante.
        $this->assertStringContainsString(
            'appareil',
            $reponse->json('hint'),
        );
    }

    public function test_la_sonde_de_push_signale_une_configuration_absente(): void
    {
        config(['onesignal.app_id' => null, 'onesignal.rest_key' => null]);

        $this->getJson('/api/monitoring/push')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('status', 'down');
    }

    public function test_l_integrite_exige_une_authentification(): void
    {
        $this->getJson('/api/monitoring/integrity')->assertUnauthorized();
    }

    public function test_l_integrite_rapporte_le_schema_et_la_configuration(): void
    {
        [$_, $entetes] = $this->authenticate();

        $reponse = $this->getJson('/api/monitoring/integrity', $entetes)->assertOk();

        $groupes = collect($reponse->json('checks'))->pluck('key');
        foreach (['runtime', 'configuration', 'schema', 'storage', 'services'] as $attendu) {
            $this->assertTrue($groupes->contains($attendu), "Groupe {$attendu} absent.");
        }
    }

    public function test_les_erreurs_serveur_sont_reservees_aux_administrateurs(): void
    {
        // Même expurgé, un journal applicatif reste la partie la plus
        // indiscrète d'une installation.
        [$_, $entetes] = $this->authenticate(['role' => 'member']);
        $this->getJson('/api/monitoring/errors', $entetes)->assertForbidden();

        [$__, $entetesAdmin] = $this->authenticate(['role' => 'admin']);
        $this->getJson('/api/monitoring/errors', $entetesAdmin)
            ->assertOk()
            ->assertJsonStructure(['failed_jobs', 'log', 'status']);
    }
}
