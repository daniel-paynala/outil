<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Monitoring\Models\MonitoredDatabase;
use App\Modules\Monitoring\Models\MonitoringAlert;
use App\Modules\Monitoring\Models\MonitoringProbe;
use App\Modules\Monitoring\Models\MonitoringProbeWindow;
use App\Modules\Monitoring\Services\DatabaseConnector;
use App\Modules\Monitoring\Services\ProbeRunner;
use App\Modules\Monitoring\Support\Capability;
use App\Modules\Monitoring\Support\Direction;
use App\Modules\Monitoring\Support\Tiers;
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

            public function read(
                MonitoredDatabase $base,
                string $sql,
                array $bindings = [],
                ?int $timeoutMs = null,
            ): array {
                $this->test->dernierDepuis = $bindings['depuis'] ?? null;
                $this->test->dernierTimeout = $timeoutMs;

                return [
                    'valeur' => $this->test->prochaineValeur(),
                    'detail' => $this->test->detail,
                ];
            }
        };

        $this->app->instance(DatabaseConnector::class, $faux);
    }

    /** Le `:depuis` envoyé à la dernière exécution — voir les tests de fenêtre. */
    public ?string $dernierDepuis = null;

    /** Le plafond transmis au connecteur à la dernière exécution. */
    public ?int $dernierTimeout = null;

    /** Ce que la fausse requête rendra en plus de `valeur`. */
    public array $detail = [];

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
        $this->assertSame(0, $this->fenetre->severest_tier);
    }

    public function test_le_franchissement_est_consigne(): void
    {
        $this->tourner(5);

        $this->assertSame([3], $this->alertes());
        $this->assertSame(3, $this->fenetre->severest_tier);
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
        $this->fenetre->update(['severest_tier' => 0]);

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

    // ── De quand à quand compte-t-on ? ──────────────────────────────────

    public function test_une_fenetre_glissante_part_de_n_heures_avant_maintenant(): void
    {
        $this->travelTo('2026-09-01 15:13:00');

        $this->tourner(1);

        $this->assertSame('2026-08-31 15:13:00', $this->dernierDepuis);
    }

    public function test_une_fenetre_calendaire_part_de_minuit_a_libreville(): void
    {
        // Minuit à Libreville, pas minuit UTC. Le Gabon est à UTC+1 : minuit
        // local vaut 23 h la veille en temps universel, et c'est cette
        // valeur-là qui doit partir dans la requête.
        config(['monitoring.timezone' => 'Africa/Libreville']);
        $this->fenetre->update(['mode' => 'calendaire']);
        $this->travelTo('2026-09-01 15:13:00');

        $this->tourner(1);

        $this->assertSame('2026-08-31 23:00:00', $this->dernierDepuis);
    }

    public function test_minuit_utc_couperait_la_nuit_gabonaise_en_deux(): void
    {
        // À 00h30 UTC, il est 1h30 à Libreville : la nuit est en cours. Une
        // fenêtre calendaire réglée sur UTC viendrait de repartir à zéro au
        // milieu de la période la plus creuse, coupant en deux les incidents
        // nocturnes qu'on veut justement voir d'un bloc.
        config(['monitoring.timezone' => 'Africa/Libreville']);
        $this->fenetre->update(['mode' => 'calendaire']);
        $this->travelTo('2026-09-01 00:30:00');

        $this->tourner(1);

        // Minuit local, c'est-à-dire 23 h la veille en UTC — la journée
        // gabonaise a commencé il y a une heure et demie, pas trente minutes.
        $this->assertSame('2026-08-31 23:00:00', $this->dernierDepuis);
    }

    public function test_une_fenetre_calendaire_de_48h_remonte_a_avant_hier(): void
    {
        config(['monitoring.timezone' => 'Africa/Libreville']);
        $this->fenetre->update(['hours' => 48, 'mode' => 'calendaire']);
        $this->travelTo('2026-09-01 15:13:00');

        $this->tourner(1);

        // Deux journées entières : hier et aujourd'hui.
        $this->assertSame('2026-08-30 23:00:00', $this->dernierDepuis);
    }

    public function test_l_acquittement_l_emporte_sur_le_debut_de_journee(): void
    {
        // Acquitter à 10 h doit faire repartir le comptage de 10 h, pas de
        // minuit : sinon les événements qu'on vient de déclarer traités
        // seraient recomptés jusqu'au lendemain.
        config(['monitoring.timezone' => 'Africa/Libreville']);
        $this->fenetre->update(['mode' => 'calendaire']);
        $this->sonde->update(['counting_from' => '2026-09-01 10:00:00']);
        $this->travelTo('2026-09-01 15:13:00');

        $this->tourner(1);

        $this->assertSame('2026-09-01 10:00:00', $this->dernierDepuis);
    }

    public function test_une_rafale_a_cheval_sur_minuit_echappe_au_calendaire(): void
    {
        // Le compromis, écrit noir sur blanc. Quatre incidents entre 22 h et
        // 1 h : la fenêtre glissante en voit quatre et signale, la calendaire
        // n'en voit que deux et se tait. Aucune des deux n'a tort — c'est
        // pourquoi le mode est un choix par fenêtre et non un arbitrage imposé.
        config(['monitoring.timezone' => 'UTC']);
        $this->travelTo('2026-09-01 08:00:00');

        $this->fenetre->update(['mode' => 'glissante']);
        $this->tourner(4);
        $this->assertSame(3, $this->fenetre->severest_tier);

        $this->fenetre->update(['mode' => 'calendaire', 'severest_tier' => 0]);
        $this->tourner(2);
        $this->assertSame(0, $this->fenetre->fresh()->severest_tier);
    }

    public function test_une_fenetre_calendaire_de_six_heures_est_refusee(): void
    {
        // « Six heures depuis minuit » changerait de longueur au fil de la
        // journée : le chiffre rendu ne voudrait rien dire, et personne ne le
        // verrait.
        [$user, $entetes] = $this->authenticate();
        DB::table('user_capabilities')->insert([
            'user_id' => $user->id,
            'capability' => Capability::MonitoringAdmin->value,
            'granted_at' => now(),
        ]);

        $this->postJson('/api/monitoring/probes', [
            'database_id' => $this->sonde->database_id,
            'title' => 'Six heures',
            'query' => 'select count(*) as valeur from t where d >= :depuis',
            'windows' => [['hours' => 6, 'mode' => 'calendaire', 'tiers' => [3]]],
        ], $entetes)
            ->assertStatus(422)
            ->assertJsonValidationErrors('windows.0.hours');
    }

    public function test_le_defaut_reste_glissant(): void
    {
        // Les fenêtres déjà enregistrées ne doivent pas changer de sens sous
        // les pieds de qui a réglé leurs paliers.
        $this->assertSame('glissante', $this->fenetre->mode);
        $this->assertFalse($this->fenetre->isCalendar());
    }

    // ── Le sens : le danger en haut, ou en bas ──────────────────────────

    public function test_le_sens_par_defaut_reste_croissant(): void
    {
        // Toutes les sondes existantes comptent des choses qui ne devraient pas
        // arriver. Une fenêtre déjà réglée ne doit pas changer de sens sous les
        // pieds de qui a posé ses paliers.
        $this->assertSame(Direction::Croissant, $this->fenetre->direction());
    }

    public function test_en_decroissant_le_plus_grave_est_le_plus_bas(): void
    {
        // Tomber sous 20 est pire que tomber sous 100 — l'inverse exact du
        // sens croissant, et toute la logique de signalement en dépend.
        $paliers = [20, 50, 100];
        $bas = Direction::Decroissant;

        $this->assertSame(100, Tiers::reached(90, $paliers, $bas));
        $this->assertSame(50, Tiers::reached(45, $paliers, $bas));
        $this->assertSame(20, Tiers::reached(3, $paliers, $bas));
        $this->assertSame(0, Tiers::reached(140, $paliers, $bas));
    }

    public function test_une_production_qui_s_effondre_alerte_a_chaque_palier(): void
    {
        // Le cas qui a motivé tout ceci. En croissant, cette même séquence ne
        // produisait qu'une notification — à 140, quand tout allait bien.
        $this->fenetre->update([
            'direction' => 'decroissant',
            'tiers' => [20, 50, 100],
        ]);

        $this->tourner(140, 120, 95, 45, 15);

        $this->assertSame([100, 50, 20], $this->alertes());
    }

    public function test_en_decroissant_une_remontee_ne_reparle_pas(): void
    {
        // Même règle qu'en croissant : on ne signale qu'un palier strictement
        // plus grave. Sans elle, 55 → 45 → 55 → 45 produirait deux alertes
        // pour un seul incident.
        $this->fenetre->update([
            'direction' => 'decroissant',
            'tiers' => [20, 50, 100],
        ]);

        $this->tourner(45, 90, 45, 60, 45);

        $this->assertCount(1, $this->alertes());
    }

    public function test_en_decroissant_les_paliers_intermediaires_sont_sautes(): void
    {
        // Passer de 140 à 3 d'un coup signale le plancher 20, pas 100 puis 50
        // puis 20 : trois notifications simultanées disent moins qu'une seule
        // qui annonce le bon chiffre.
        $this->fenetre->update([
            'direction' => 'decroissant',
            'tiers' => [20, 50, 100],
        ]);

        $this->tourner(140, 3);

        $this->assertSame([20], $this->alertes());
    }

    public function test_zero_est_signale_et_non_ignore(): void
    {
        // Zéro veut dire « aucun palier atteint » dans le stockage, mais une
        // valeur de zéro est le pire cas possible d'une sonde décroissante :
        // plus rien n'arrive. Confondre les deux ferait taire la sonde au
        // moment exact où elle doit parler.
        $this->fenetre->update([
            'direction' => 'decroissant',
            'tiers' => [20, 50, 100],
        ]);

        $this->tourner(0);

        $this->assertSame([20], $this->alertes());
        $this->assertSame(20, $this->fenetre->fresh()->severest_tier);
    }

    public function test_la_notification_dit_plancher_et_non_palier(): void
    {
        // « palier 50 » à quelqu'un dont la production vient de s'effondrer se
        // comprend à l'envers.
        $this->fenetre->update([
            'direction' => 'decroissant',
            'tiers' => [50],
        ]);
        [$user] = $this->authenticate();
        DB::table('user_capabilities')->insert([
            'user_id' => $user->id,
            'capability' => Capability::Monitoring->value,
            'granted_at' => now(),
        ]);

        $this->tourner(45);

        $notification = DB::table('notifications')
            ->where('type', 'monitoring.alert')
            ->first();

        $this->assertStringContainsString('plancher 50', $notification->body);
        $this->assertStringNotContainsString('palier', $notification->body);
    }

    public function test_le_premier_palier_franchi_depend_du_sens(): void
    {
        // Sert à l'affichage : c'est lui qui distingue l'orange du rouge.
        $paliers = [20, 50, 100];

        $this->assertSame(20, Tiers::first($paliers, Direction::Croissant));
        $this->assertSame(100, Tiers::first($paliers, Direction::Decroissant));
    }

    public function test_le_prochain_seuil_depend_du_sens(): void
    {
        // « Encore 5 avant 50 » en croissant ; « encore 5 avant de tomber sous
        // 50 » en décroissant. Dans les deux cas on voit venir.
        $paliers = [20, 50, 100];

        $this->assertSame(50, Tiers::next(45, $paliers, Direction::Croissant));
        $this->assertSame(20, Tiers::next(45, $paliers, Direction::Decroissant));
        $this->assertNull(Tiers::next(140, $paliers, Direction::Croissant));
        $this->assertNull(Tiers::next(3, $paliers, Direction::Decroissant));
    }

    // ── Une sonde restreinte ne notifie pas les autres ──────────────────

    private function avecDroit(Capability $droit): User
    {
        [$user] = $this->authenticate();
        DB::table('user_capabilities')->insert([
            'user_id' => $user->id,
            'capability' => $droit->value,
            'granted_at' => now(),
        ]);

        return $user;
    }

    private function prevenus(): array
    {
        return DB::table('notifications')
            ->where('type', 'monitoring.alert')
            ->pluck('user_id')
            ->sort()
            ->values()
            ->all();
    }

    public function test_sans_restriction_tous_les_superviseurs_sont_prevenus(): void
    {
        $a = $this->avecDroit(Capability::Monitoring);
        $b = $this->avecDroit(Capability::Monitoring);

        $this->tourner(5);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->prevenus());
    }

    public function test_une_sonde_restreinte_ne_notifie_que_les_siens(): void
    {
        // Le point le plus important de toute la restriction. Une notification
        // arrive sur un écran verrouillé — là où on ne contrôle plus rien — et
        // porte le nom de la base, le volume et l'heure. La laisser partir à
        // tout le monde annulerait la restriction tout en donnant l'illusion
        // qu'elle existe.
        $autorise = $this->avecDroit(Capability::Monitoring);
        $exclu = $this->avecDroit(Capability::Monitoring);
        $this->sonde->viewers()->sync([$autorise->id]);

        $this->tourner(5);

        $this->assertSame([$autorise->id], $this->prevenus());
        $this->assertNotContains($exclu->id, $this->prevenus());
    }

    public function test_un_administrateur_de_la_supervision_reste_prevenu(): void
    {
        // Il peut de toute façon lire la sonde, et une alerte qu'il ne
        // recevrait pas serait un incident que personne ne traite un jour de
        // congé.
        $autorise = $this->avecDroit(Capability::Monitoring);
        $patron = $this->avecDroit(Capability::MonitoringAdmin);
        $this->sonde->viewers()->sync([$autorise->id]);

        $this->tourner(5);

        $this->assertEqualsCanonicalizing(
            [$autorise->id, $patron->id],
            $this->prevenus(),
        );
    }

    public function test_retirer_quelqu_un_de_la_liste_le_coupe_tout_de_suite(): void
    {
        // La liste est relue à chaque alerte : un accès retiré ce matin ne doit
        // pas continuer à faire sonner un téléphone cet après-midi.
        $a = $this->avecDroit(Capability::Monitoring);
        $b = $this->avecDroit(Capability::Monitoring);
        $this->sonde->viewers()->sync([$a->id, $b->id]);

        $this->tourner(5);
        $this->assertCount(2, $this->prevenus());

        DB::table('notifications')->delete();
        $this->sonde->viewers()->sync([$a->id]);
        $this->fenetre->update(['severest_tier' => 0]);

        $this->tourner(15);

        $this->assertSame([$a->id], $this->prevenus());
    }

    // ── Le mois, et le cumul de toujours ────────────────────────────────

    public function test_une_fenetre_mensuelle_part_du_premier_du_mois(): void
    {
        // Un mois n'a pas une durée fixe : 720 heures ne sont pas février, et
        // aucun nombre d'heures ne dit « depuis le 1er ».
        config(['monitoring.timezone' => 'Africa/Libreville']);
        $this->fenetre->update(['mode' => 'mensuelle']);
        $this->travelTo('2026-09-17 15:13:00');

        $this->tourner(1);

        // 1er septembre à minuit à Libreville, soit le 31 août 23 h UTC.
        $this->assertSame('2026-08-31 23:00:00', $this->dernierDepuis);
    }

    public function test_le_mois_suit_le_calendrier_et_non_trente_jours(): void
    {
        // Le 1er mars, une fenêtre de « 30 jours » remonterait au 30 janvier.
        config(['monitoring.timezone' => 'Africa/Libreville']);
        $this->fenetre->update(['mode' => 'mensuelle']);
        $this->travelTo('2026-03-01 08:00:00');

        $this->tourner(1);

        $this->assertSame('2026-02-28 23:00:00', $this->dernierDepuis);
    }

    public function test_acquitter_deplace_bien_les_autres_fenetres(): void
    {
        // Le mensuel garde le comportement de tous les autres modes.
        config(['monitoring.timezone' => 'Africa/Libreville']);
        $this->fenetre->update(['mode' => 'mensuelle']);
        $this->sonde->update(['counting_from' => '2026-09-10 10:00:00']);
        $this->travelTo('2026-09-17 15:13:00');

        $this->tourner(1);

        $this->assertSame('2026-09-10 10:00:00', $this->dernierDepuis);
    }

    public function test_la_notification_nomme_la_periode_et_espace_les_montants(): void
    {
        // « 45000000 F CFA sur 720 h » ne se lit pas sur un écran verrouillé.
        $this->sonde->update(['unit' => 'F CFA']);
        $this->fenetre->update([
            'mode' => 'mensuelle',
            'direction' => 'decroissant',
            'tiers' => [50000000],
        ]);
        [$user] = $this->authenticate();
        DB::table('user_capabilities')->insert([
            'user_id' => $user->id,
            'capability' => Capability::Monitoring->value,
            'granted_at' => now(),
        ]);

        $this->tourner(45000000);

        $corps = DB::table('notifications')
            ->where('type', 'monitoring.alert')
            ->value('body');

        $this->assertSame(
            '45 000 000 F CFA ce mois-ci (plancher 50 000 000).',
            $corps,
        );
    }

    public function test_une_fenetre_annuelle_part_du_premier_janvier(): void
    {
        // Inexprimable en heures : une fenêtre plafonne à 720 heures, une
        // année en compte 8 760.
        config(['monitoring.timezone' => 'Africa/Libreville']);
        $this->fenetre->update(['mode' => 'annuelle']);
        $this->travelTo('2026-09-17 15:13:00');

        $this->tourner(1);

        $this->assertSame('2025-12-31 23:00:00', $this->dernierDepuis);
    }

    public function test_le_mois_et_l_annee_ignorent_les_heures(): void
    {
        // La colonne reste renseignée — elle est obligatoire — mais ne décide
        // plus rien. L'écran doit donc afficher la période, pas la durée.
        $this->fenetre->update(['mode' => 'mensuelle', 'hours' => 6]);
        $this->assertTrue($this->fenetre->fresh()->isPeriod());
        $this->assertSame('ce mois-ci', $this->fenetre->fresh()->periodLabel());

        $this->fenetre->update(['mode' => 'annuelle']);
        $this->assertSame('cette année', $this->fenetre->fresh()->periodLabel());
    }

    public function test_une_fenetre_totale_remonte_avant_la_production(): void
    {
        $this->fenetre->update(['mode' => 'totale']);
        $this->travelTo('2026-09-17 15:13:00');

        $this->tourner(1);

        $this->assertSame('1970-01-01 00:00:00', $this->dernierDepuis);
    }

    public function test_acquitter_ne_deplace_pas_un_cumul_de_toujours(): void
    {
        // La seule fenêtre où l'acquittement n'agit pas sur la valeur : un
        // cumul qui repartirait de zéro à chaque accusé de réception ne
        // mesurerait plus rien. Il rouvre en revanche les paliers, pour que le
        // jalon suivant se signale.
        $this->fenetre->update(['mode' => 'totale']);
        $this->sonde->update(['counting_from' => '2026-09-01 10:00:00']);
        $this->travelTo('2026-09-17 15:13:00');

        $this->tourner(1);

        $this->assertSame('1970-01-01 00:00:00', $this->dernierDepuis);
    }

    // ── Cadence, délai, détail ──────────────────────────────────────────

    public function test_le_delai_de_la_sonde_est_transmis_a_postgres(): void
    {
        // Huit secondes suffisent à compter sur une table indexée. Croiser des
        // centaines de milliers de lignes de journal en demande davantage : le
        // tableau de bord Paynala accorde 45 s aux mêmes requêtes.
        $this->sonde->update(['timeout_ms' => 45000]);

        $this->tourner(1);

        $this->assertSame(45000, $this->dernierTimeout);
    }

    public function test_le_delai_par_defaut_reste_de_huit_secondes(): void
    {
        $this->tourner(1);

        $this->assertSame(8000, $this->dernierTimeout);
    }

    public function test_une_sonde_a_cadence_lente_ne_tourne_pas_a_chaque_minute(): void
    {
        // Onze sondes à quarante-cinq secondes, toutes les minutes, ne
        // tiennent pas : elles se chevauchent, la garde les empêche de partir,
        // et la supervision décroche sans rien dire.
        $this->sonde->update(['interval_minutes' => 60]);
        $this->travelTo('2026-09-02 10:00:00');

        $this->tourner(7);
        $this->assertSame(7, $this->fenetre->fresh()->last_value);

        $this->travelTo('2026-09-02 10:30:00');
        $this->tourner(99);

        // Une demi-heure plus tard : trop tôt, la valeur n'a pas bougé.
        $this->assertSame(7, $this->fenetre->fresh()->last_value);

        $this->travelTo('2026-09-02 11:05:00');
        $this->tourner(99);

        $this->assertSame(99, $this->fenetre->fresh()->last_value);
    }

    public function test_la_cadence_par_defaut_laisse_tourner_chaque_minute(): void
    {
        // Aucune sonde existante ne change de rythme.
        $this->travelTo('2026-09-02 10:00:00');
        $this->tourner(7);

        $this->travelTo('2026-09-02 10:01:00');
        $this->tourner(9);

        $this->assertSame(9, $this->fenetre->fresh()->last_value);
    }

    public function test_les_colonnes_en_plus_sont_conservees_comme_detail(): void
    {
        // Une somme totale sans sa décomposition oblige à créer une sonde par
        // portefeuille, et à en payer quatre fois le coût.
        $this->detail = ['CP' => 12000, 'MC1' => 8000, 'MC2' => 3000];

        $this->tourner(23000);

        $this->assertSame(
            ['CP' => 12000, 'MC1' => 8000, 'MC2' => 3000],
            $this->fenetre->fresh()->last_detail,
        );
    }

    public function test_le_detail_ne_decide_jamais_d_un_palier(): void
    {
        // C'est ce qui garde un palier interprétable : un seuil se lit sur un
        // nombre, pas sur une décomposition.
        $this->detail = ['CP' => 100000];

        $this->tourner(2);

        $this->assertSame([], $this->alertes());
        $this->assertSame(0, $this->fenetre->fresh()->severest_tier);
    }

    public function test_sans_colonne_supplementaire_le_detail_reste_vide(): void
    {
        $this->tourner(5);

        $this->assertNull($this->fenetre->fresh()->last_detail);
    }

    public function test_une_sonde_en_echec_ne_salit_pas_les_autres(): void
    {
        // Le défaut constaté en usage : une seule sonde trop lente peignait son
        // « statement timeout » sur les dix autres cartes de la même base, qui
        // allaient parfaitement bien. On cherchait une panne partout au lieu
        // d'une requête à un seul endroit.
        $saine = MonitoringProbe::create([
            'id' => (string) Str::uuid(),
            'database_id' => $this->sonde->database_id,
            'title' => 'Celle qui va bien',
            'query' => 'select count(*) as valeur from t where d >= :depuis',
        ]);
        MonitoringProbeWindow::create([
            'id' => (string) Str::uuid(),
            'probe_id' => $saine->id,
            'hours' => 24,
            'tiers' => [3],
        ]);

        $lente = $this->sonde;
        $casse = new class extends DatabaseConnector
        {
            public function __construct() {}

            public function read(
                MonitoredDatabase $base,
                string $sql,
                array $bindings = [],
                ?int $timeoutMs = null,
            ): array {
                if (str_contains($sql, 'payment')) {
                    throw new \RuntimeException('SQLSTATE[57014]: statement timeout');
                }

                return ['valeur' => 1, 'detail' => []];
            }
        };
        $this->app->instance(DatabaseConnector::class, $casse);

        app(ProbeRunner::class)->runAll();

        $this->assertStringContainsString(
            '57014',
            $lente->fresh()->last_error ?? '',
        );
        $this->assertNull($saine->fresh()->last_error);

        // Et la base, elle, n'est pas déclarée en panne pour autant.
        $this->assertNull($lente->database->fresh()->last_error);
    }

    public function test_une_sonde_qui_se_retablit_efface_son_erreur(): void
    {
        $this->sonde->update(['last_error' => 'timeout précédent']);

        $this->tourner(1);

        $this->assertNull($this->sonde->fresh()->last_error);
    }

    // ── `hours` comme intervalle de rechargement ────────────────────────

    public function test_une_fenetre_de_periode_se_recharge_selon_ses_heures(): void
    {
        // L'idée de Daniel : pour « ce mois-ci », « cette année » et « depuis
        // toujours », la période est fixée par le mode et `hours` ne servait à
        // rien. Il devient l'intervalle de rechargement — « recompte le cumul
        // annuel toutes les 24 heures ».
        $this->fenetre->update(['mode' => 'annuelle', 'hours' => 24]);

        $this->travelTo('2026-09-02 08:00:00');
        $this->tourner(100);
        $this->assertSame(100, $this->fenetre->fresh()->last_value);

        $this->travelTo('2026-09-02 20:00:00');
        $this->tourner(999);
        $this->assertSame(100, $this->fenetre->fresh()->last_value, 'douze heures : trop tôt');

        $this->travelTo('2026-09-03 08:30:00');
        $this->tourner(999);
        $this->assertSame(999, $this->fenetre->fresh()->last_value);
    }

    public function test_les_heures_restent_la_periode_observee_en_glissant(): void
    {
        // Le champ dit toujours la même chose sous deux formes : le nombre dont
        // le mode a besoin. En glissante, c'est la période observée, et la
        // cadence continue de venir de la sonde.
        $this->fenetre->update(['mode' => 'glissante', 'hours' => 24]);
        $this->travelTo('2026-09-02 08:00:00');

        $this->tourner(1);
        $this->assertSame('2026-09-01 08:00:00', $this->dernierDepuis);

        // Vingt-quatre heures d'observation n'empêchent pas de relancer à la
        // minute suivante.
        $this->travelTo('2026-09-02 08:01:00');
        $this->tourner(5);
        $this->assertSame(5, $this->fenetre->fresh()->last_value);
    }

    public function test_deux_fenetres_d_une_sonde_ont_chacune_leur_cadence(): void
    {
        // Ce que la cadence par sonde ne savait pas faire : une même sonde
        // portant une fenêtre vive et un cumul lourd.
        $this->fenetre->update(['mode' => 'glissante', 'hours' => 1]);
        $lourde = MonitoringProbeWindow::create([
            'id' => (string) Str::uuid(),
            'probe_id' => $this->sonde->id,
            'hours' => 24,
            'mode' => 'annuelle',
            'tiers' => [1000],
        ]);

        $this->travelTo('2026-09-02 08:00:00');
        $this->tourner(10, 10);

        $this->travelTo('2026-09-02 08:05:00');
        $this->tourner(77, 77);

        $this->assertSame(77, $this->fenetre->fresh()->last_value, 'la vive suit');
        $this->assertSame(10, $lourde->fresh()->last_value, 'la lourde attend son heure');
    }

    public function test_une_sonde_dont_rien_ne_tourne_garde_son_erreur(): void
    {
        // Effacer une erreur qu'aucune exécution n'a démentie la ferait
        // disparaître de l'écran sans que rien ne l'ait résolue.
        $this->fenetre->update([
            'mode' => 'annuelle',
            'hours' => 24,
            'last_run_at' => now(),
        ]);
        $this->sonde->update(['last_error' => 'timeout de la nuit dernière']);

        app(ProbeRunner::class)->runAll();

        $this->assertSame(
            'timeout de la nuit dernière',
            $this->sonde->fresh()->last_error,
        );
    }

    // ── Incident ou jalon ───────────────────────────────────────────────

    public function test_par_defaut_une_sonde_signale_un_incident(): void
    {
        // Aucune sonde existante ne change de nature.
        $this->assertFalse($this->sonde->isMilestone());
    }

    public function test_un_jalon_franchi_n_ouvre_pas_d_incident(): void
    {
        // Le défaut que Daniel a vu : la pastille disait orange sur un
        // milliard collecté, et il a lu ce qu'elle disait — « c'est pas
        // assez ? ». Franchir un jalon n'attend rien de personne.
        $this->sonde->update(['nature' => 'jalon']);

        $this->tourner(50);

        // Le palier est bien signalé — c'est l'incident qui ne s'ouvre pas.
        $this->assertSame(40, $this->fenetre->fresh()->severest_tier);
        $this->assertFalse($this->sonde->fresh()->load('windows')->hasOpenIncident());
    }

    public function test_le_meme_franchissement_ouvre_un_incident_en_mode_incident(): void
    {
        // La preuve que c'est bien la nature qui décide, et non la valeur.
        $this->tourner(50);

        $this->assertTrue($this->sonde->fresh()->load('windows')->hasOpenIncident());
    }

    public function test_la_notification_dit_jalon_et_non_palier(): void
    {
        // « palier franchi » sur un chiffre d'affaires se lit comme un
        // avertissement. Ce n'en est pas un.
        $this->sonde->update(['nature' => 'jalon', 'unit' => 'F CFA']);
        [$user] = $this->authenticate();
        DB::table('user_capabilities')->insert([
            'user_id' => $user->id,
            'capability' => Capability::Monitoring->value,
            'granted_at' => now(),
        ]);

        $this->tourner(50);

        $corps = DB::table('notifications')
            ->where('type', 'monitoring.alert')
            ->value('body');

        $this->assertStringContainsString('jalon 40', $corps);
        $this->assertStringNotContainsString('palier', $corps);
    }

    public function test_un_jalon_signale_toujours_le_suivant(): void
    {
        // Sans acquittement possible, c'est la règle du palier strictement
        // supérieur qui doit suffire — sinon un jalon franchi rendrait la
        // sonde muette pour toujours.
        $this->sonde->update(['nature' => 'jalon']);

        $this->tourner(5, 25, 70);

        $this->assertSame([3, 20, 60], $this->alertes());
    }
}
