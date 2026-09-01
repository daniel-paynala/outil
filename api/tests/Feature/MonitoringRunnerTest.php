<?php

namespace Tests\Feature;

use App\Modules\Monitoring\Models\MonitoredDatabase;
use App\Modules\Monitoring\Models\MonitoringAlert;
use App\Modules\Monitoring\Models\MonitoringProbe;
use App\Modules\Monitoring\Models\MonitoringProbeWindow;
use App\Modules\Monitoring\Services\DatabaseConnector;
use App\Modules\Monitoring\Services\ProbeRunner;
use App\Modules\Monitoring\Support\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L'exécution des sondes et le signalement.
 *
 * Le connecteur est remplacé : ce qu'on vérifie n'est pas Postgres mais la
 * décision — quand signaler, à qui, et surtout quand se taire. Une erreur ici
 * se paie en notifications, trop ou pas du tout, et les deux sont graves : la
 * première fait désinstaller l'application, la seconde fait rater l'incident.
 */
class MonitoringRunnerTest extends TestCase
{
    use RefreshDatabase;

    private MonitoringProbe $sonde;

    private MonitoringProbeWindow $fenetre;

    /** Valeurs que le faux connecteur rendra, dans l'ordre. */
    private array $valeurs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $base = MonitoredDatabase::create([
            'id' => (string) Str::uuid(),
            'name' => 'Airtel Money',
            'host' => 'db.exemple', 'port' => 5432,
            'dbname' => 'paiements', 'username' => 'lecteur',
            'password' => 'secret',
            'read_only_verified_at' => now(),
        ]);

        $this->sonde = MonitoringProbe::create([
            'id' => (string) Str::uuid(),
            'database_id' => $base->id,
            'title' => 'Time-outs de paiement',
            'unit' => 'time-outs',
            'query' => 'select count(*) as valeur from payment where created_at >= :depuis',
        ]);

        $this->fenetre = MonitoringProbeWindow::create([
            'id' => (string) Str::uuid(),
            'probe_id' => $this->sonde->id,
            'hours' => 24,
            'tiers' => [3, 10, 20, 40, 60, 100],
        ]);

        $faux = new class($this) extends DatabaseConnector
        {
            public function __construct(private $test) {}

            public function readValue(
                MonitoredDatabase $base,
                string $sql,
                array $bindings = [],
            ): int {
                return $this->test->prochaineValeur();
            }
        };

        $this->app->instance(DatabaseConnector::class, $faux);
    }

    public function prochaineValeur(): int
    {
        return array_shift($this->valeurs) ?? 0;
    }

    private function tourner(int ...$valeurs): void
    {
        $this->valeurs = $valeurs;
        foreach ($valeurs as $_) {
            app(ProbeRunner::class)->runAll();
            $this->sonde->refresh()->load('windows');
            $this->fenetre->refresh();
        }
    }

    private function alertes(): array
    {
        return MonitoringAlert::orderBy('raised_at')->pluck('tier')->all();
    }

    // ── Le signalement ──────────────────────────────────────────────────

    public function test_sous_le_premier_palier_rien_n_est_signale(): void
    {
        $this->tourner(2);

        $this->assertSame([], $this->alertes());
        $this->assertSame(0, $this->fenetre->highest_tier);
    }

    public function test_le_franchissement_est_consigne(): void
    {
        $this->tourner(5);

        $this->assertSame([3], $this->alertes());
        $this->assertSame(3, $this->fenetre->highest_tier);
        $this->assertSame(5, $this->fenetre->last_value);
    }

    public function test_une_fenetre_glissante_qui_redescend_ne_renotifie_pas(): void
    {
        // Le cas qui a dicté toute la conception. Le compte monte à 12, puis
        // redescend à 9 parce que de vieux événements sortent de la fenêtre,
        // puis repasse à 11. Une seule notification, pas trois.
        $this->tourner(12, 9, 11);

        $this->assertSame([10], $this->alertes());
    }

    public function test_chaque_palier_superieur_est_signale(): void
    {
        $this->tourner(5, 15, 25);

        $this->assertSame([3, 10, 20], $this->alertes());
    }

    public function test_un_bond_saute_les_paliers_intermediaires(): void
    {
        // Trois notifications simultanées disent moins qu'une seule qui annonce
        // le bon chiffre.
        $this->tourner(45);

        $this->assertSame([40], $this->alertes());
    }

    // ── L'acquittement ──────────────────────────────────────────────────

    public function test_acquitter_reouvre_les_paliers(): void
    {
        $this->tourner(12);
        $this->assertSame([10], $this->alertes());

        // Le geste explicite : l'incident est traité, on recompte à partir de
        // maintenant.
        $this->sonde->update([
            'counting_from' => now(),
            'acknowledged_by' => null,
        ]);
        $this->fenetre->update(['highest_tier' => 0]);

        $this->tourner(4);

        $this->assertSame([10, 3], $this->alertes());
    }

    public function test_sans_acquittement_un_incident_reste_silencieux(): void
    {
        // Un problème qui stagne à 12 pendant des jours notifie une fois, puis
        // se tait. C'est voulu : rien de nouveau n'arrive, et le redire toutes
        // les minutes ferait ignorer toutes les alertes.
        $this->tourner(12, 12, 12, 12);

        $this->assertSame([10], $this->alertes());
    }

    // ── Qui est prévenu ─────────────────────────────────────────────────

    public function test_seuls_les_superviseurs_sont_prevenus(): void
    {
        // L'alerte nomme une base de production et un volume d'incidents. La
        // diffuser plus largement divulguerait par la notification ce que le
        // menu masque.
        [$superviseur] = $this->authenticate();
        [$ordinaire] = $this->authenticate();

        DB::table('user_capabilities')->insert([
            'user_id' => $superviseur->id,
            'capability' => Capability::Monitoring->value,
            'granted_at' => now(),
        ]);

        $this->tourner(5);

        $prevenus = DB::table('notifications')
            ->where('type', 'monitoring.alert')
            ->pluck('user_id')
            ->all();

        $this->assertContains($superviseur->id, $prevenus);
        $this->assertNotContains($ordinaire->id, $prevenus);
    }

    public function test_les_administrateurs_sont_prevenus_sans_droit_explicite(): void
    {
        [$patron] = $this->authenticate(['role' => 'admin']);

        $this->tourner(5);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $patron->id,
            'type' => 'monitoring.alert',
        ]);
    }

    // ── Les bases inutilisables ─────────────────────────────────────────

    public function test_une_base_dont_la_lecture_seule_n_est_pas_constatee_est_ignoree(): void
    {
        // Une base ajoutée avec des identifiants trop puissants reste inerte
        // plutôt que d'être surveillée « en attendant ».
        $this->sonde->database->update(['read_only_verified_at' => null]);

        $this->tourner(50);

        $this->assertSame([], $this->alertes());
    }

    public function test_une_sonde_desactivee_ne_tourne_pas(): void
    {
        $this->sonde->update(['enabled' => false]);

        $this->tourner(50);

        $this->assertSame([], $this->alertes());
    }
}
