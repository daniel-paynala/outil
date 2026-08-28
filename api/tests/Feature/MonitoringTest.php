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

    // ── Réparations ─────────────────────────────────────────────────────

    public function test_les_reparations_sont_reservees_aux_administrateurs(): void
    {
        // Elles modifient l'état du serveur : synchroniser des réglages,
        // repeupler un index, effacer des échecs.
        [$_, $entetes] = $this->authenticate(['role' => 'member']);

        $this->postJson('/api/monitoring/search/repair', [], $entetes)
            ->assertForbidden();
        $this->postJson('/api/monitoring/queue/flush', [], $entetes)
            ->assertForbidden();
    }

    public function test_reparer_la_recherche_hors_meilisearch_est_refuse(): void
    {
        // Scout en mode `collection` interroge la base : il n'y a aucun réglage
        // à pousser, et prétendre avoir réparé serait mensonger.
        config(['scout.driver' => 'collection']);

        [$_, $entetes] = $this->authenticate(['role' => 'admin']);

        $this->postJson('/api/monitoring/search/repair', [], $entetes)
            ->assertStatus(422);
    }

    public function test_vider_les_echecs_rend_le_compte_efface(): void
    {
        // Savoir ce qu'on vient de perdre : un « c'est fait » sans chiffre
        // laisse ignorer qu'on a effacé vingt échecs jamais lus.
        [$_, $entetes] = $this->authenticate(['role' => 'admin']);

        $this->postJson('/api/monitoring/queue/flush', [], $entetes)
            ->assertOk()
            ->assertJsonPath('cleared', 0);
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

    /**
     * La sonde de recherche est ouverte à tous, et c'est voulu : on doit
     * pouvoir la lire quand l'authentification elle-même est en panne. Ce
     * qu'elle rend alors ne doit pour autant rien apprendre sur l'entreprise.
     */
    public function test_la_sonde_de_recherche_repond_sans_jeton(): void
    {
        $this->getJson('/api/monitoring/search')
            ->assertOk()
            ->assertJsonStructure(['engine', 'reachable', 'status', 'indexes']);
    }

    public function test_la_sonde_ne_chiffre_rien_pour_un_inconnu(): void
    {
        $reponse = $this->getJson('/api/monitoring/search')->assertOk();

        foreach ($reponse->json('indexes') as $index) {
            // `documents` et `rows` disent combien l'entreprise produit et
            // stocke — dont le nombre de secrets au coffre.
            $this->assertArrayNotHasKey('documents', $index);
            $this->assertArrayNotHasKey('rows', $index);
            // `missing_filters` décrit le réglage du moteur, `index` porte le
            // préfixe de déploiement.
            $this->assertArrayNotHasKey('missing_filters', $index);
            $this->assertArrayNotHasKey('index', $index);
            // L'état reste : c'est lui qui permet de constater la panne.
            $this->assertArrayHasKey('label', $index);
            $this->assertArrayHasKey('status', $index);
        }
    }

    public function test_la_sonde_ne_nomme_ni_l_hote_ni_ses_exceptions(): void
    {
        $reponse = $this->getJson('/api/monitoring/search')->assertOk();

        // Le message d'exception nomme l'hôte, le port, parfois l'entête
        // d'authentification refusé.
        $this->assertNull($reponse->json('error'));

        // Une indication reste permise — il en faut bien une pour dire que le
        // moteur ne répond pas. Ce qu'elle n'a pas le droit de contenir, c'est
        // l'adresse du service ou la commande à lancer, qui décrivent nos
        // rouages à quelqu'un qui ne peut de toute façon rien réparer.
        $indication = (string) $reponse->json('hint');
        $hote = (string) config('scout.meilisearch.host');

        $this->assertStringNotContainsString($hote, $indication);
        $this->assertStringNotContainsString('artisan', $indication);
        $this->assertStringNotContainsString('scout:', $indication);
    }

    public function test_la_sonde_rend_le_detail_a_qui_se_presente(): void
    {
        [$user, $entetes] = $this->authenticate();

        $reponse = $this->getJson('/api/monitoring/search', $entetes)->assertOk();

        // Sans ces chiffres, l'écran Monitoring ne peut plus dire « 19 indexés
        // pour 20 en base », qui est tout l'intérêt de la sonde.
        foreach ($reponse->json('indexes') as $index) {
            $this->assertArrayHasKey('documents', $index);
            $this->assertArrayHasKey('rows', $index);
            $this->assertArrayHasKey('missing_filters', $index);
        }
    }

    public function test_un_jeton_invalide_ne_fait_pas_echouer_la_sonde(): void
    {
        // `supabase.maybe` reconnaît sans refuser : un jeton expiré doit
        // dégrader vers la réponse anonyme, et non produire un 401 sur une
        // route dont l'intérêt est de survivre à la panne d'authentification.
        $this->getJson('/api/monitoring/search', [
            'Authorization' => 'Bearer jeton.parfaitement.faux',
        ])->assertOk();
    }
}
