<?php

namespace App\Modules\Monitoring\Services;

use App\Models\User;
use App\Modules\Monitoring\Models\MonitoringAlert;
use App\Modules\Monitoring\Models\MonitoringProbe;
use App\Modules\Monitoring\Models\MonitoringProbeWindow;
use App\Modules\Monitoring\Support\Capability;
use App\Modules\Monitoring\Support\Tiers;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * L'exécution des sondes et le signalement des paliers.
 *
 * ## Ce que « compter » veut dire
 *
 * Chaque fenêtre compte les événements depuis le plus récent de deux instants :
 * le début de la fenêtre glissante, et le dernier acquittement. Acquitter ne
 * peut pas effacer des lignes d'une base de production ; ce qu'on remet à zéro
 * est le point de départ du comptage.
 */
class ProbeRunner
{
    public function __construct(
        private readonly DatabaseConnector $connector,
        private readonly NotificationService $notify,
    ) {}

    /**
     * Exécute toutes les sondes actives dont la base est utilisable.
     *
     * Chacune dans son propre `try` : une base injoignable ne doit pas priver
     * les sept autres de leur surveillance — c'est précisément quand une chose
     * casse que le reste doit continuer à parler.
     */
    public function runAll(): void
    {
        $sondes = MonitoringProbe::with(['windows', 'database'])
            ->where('enabled', true)
            ->get()
            ->filter(fn (MonitoringProbe $s) => $s->database?->isUsable() ?? false);

        foreach ($sondes as $sonde) {
            try {
                $this->run($sonde);
            } catch (Throwable $e) {
                $sonde->database->update(['last_error' => $e->getMessage()]);
                Log::warning('Sonde en échec', [
                    'probe' => $sonde->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function run(MonitoringProbe $sonde): void
    {
        foreach ($sonde->windows as $fenetre) {
            $depuis = $this->countingFrom($sonde, $fenetre);

            $valeur = $this->connector->readValue(
                $sonde->database,
                $sonde->query,
                ['depuis' => $depuis->toDateTimeString()],
            );

            $palier = Tiers::toRaise(
                $valeur,
                $fenetre->sortedTiers(),
                $fenetre->highest_tier,
            );

            $fenetre->update([
                'last_value' => $valeur,
                'last_run_at' => now(),
                // Mis à jour **avant** la notification : si l'envoi échoue, on
                // préfère une alerte perdue à une alerte répétée à chaque tour.
                'highest_tier' => $palier ?? $fenetre->highest_tier,
            ]);

            if ($palier === null) {
                continue;
            }

            $this->signaler($sonde, $fenetre, $palier, $valeur);
        }

        $sonde->database->update(['last_error' => null]);
    }

    /**
     * Depuis quand compter.
     *
     * Le plus récent des deux : le début de la fenêtre, et le dernier
     * acquittement. Sans le second, acquitter ne servirait à rien tant que les
     * événements traités restent dans la fenêtre.
     */
    private function countingFrom(
        MonitoringProbe $sonde,
        MonitoringProbeWindow $fenetre,
    ): Carbon {
        $debutFenetre = $this->windowStart($fenetre);
        $acquittement = $sonde->counting_from;

        return $acquittement !== null && $acquittement->greaterThan($debutFenetre)
            ? $acquittement
            : $debutFenetre;
    }

    /**
     * Le début de la fenêtre, selon qu'elle glisse ou suit les journées.
     *
     * ## Les deux ne se valent pas, et aucune ne gagne toujours
     *
     * **Glissante** : les N dernières heures, à tout instant. Elle attrape une
     * rafale à cheval sur minuit — deux incidents à 23 h et deux à 1 h font
     * quatre — là où le découpage par journée n'en voit que deux d'un côté et
     * deux de l'autre, et ne signale rien.
     *
     * **Calendaire** : depuis minuit. Elle dit ce que tout le monde entend par
     * « trois time-outs dans la journée », se recoupe avec les rapports, et
     * repart à zéro chaque nuit sans qu'on ait à acquitter quoi que ce soit.
     *
     * La première est meilleure pour détecter, la seconde pour décider. D'où
     * le choix par fenêtre plutôt qu'un arbitrage imposé — et le défaut reste
     * la glissante, qui ne laisse passer aucune rafale.
     *
     * Minuit est celui de Libreville, pas d'UTC : minuit UTC tombe à 1 h du
     * matin au Gabon, en pleine nuit locale, et couperait en deux les
     * incidents nocturnes qu'on veut justement voir d'un bloc.
     */
    private function windowStart(MonitoringProbeWindow $fenetre): Carbon
    {
        if (! $fenetre->isCalendar()) {
            return now()->subHours($fenetre->hours);
        }

        $jours = intdiv($fenetre->hours, 24);

        return now()
            ->timezone(config('monitoring.timezone'))
            ->startOfDay()
            ->subDays(max($jours - 1, 0))
            ->utc();
    }

    /**
     * Consigne le franchissement et prévient qui a le droit de le savoir.
     *
     * ## Pourquoi tout le monde n'est pas prévenu
     *
     * L'alerte nomme une base de production et un volume d'incidents. Elle est
     * réservée à qui a le droit de superviser — la prévenir plus largement
     * divulguerait par la notification ce que le menu masque.
     */
    private function signaler(
        MonitoringProbe $sonde,
        MonitoringProbeWindow $fenetre,
        int $palier,
        int $valeur,
    ): void {
        MonitoringAlert::create([
            'id' => (string) Str::uuid(),
            'probe_id' => $sonde->id,
            'window_hours' => $fenetre->hours,
            'tier' => $palier,
            'value' => $valeur,
            'raised_at' => now(),
        ]);

        $titre = "{$sonde->database->name} — {$sonde->title}";
        $corps = "{$valeur} {$sonde->unit} sur {$fenetre->hours} h "
            ."(palier {$palier}).";

        foreach ($this->destinataires() as $userId) {
            $this->notify->forUser(
                userId: $userId,
                type: 'monitoring.alert',
                title: $titre,
                body: $corps,
                link: '/monitoring',
                metadata: [
                    'probe_id' => $sonde->id,
                    'window_hours' => $fenetre->hours,
                    'tier' => $palier,
                    'value' => $valeur,
                ],
            );
        }
    }

    /**
     * Qui reçoit les alertes.
     *
     * Les porteurs du droit de supervision, et les administrateurs qui l'ont
     * implicitement. La requête est refaite à chaque alerte : un droit retiré
     * ce matin ne doit pas continuer à recevoir des alertes cet après-midi.
     *
     * @return array<int, string>
     */
    private function destinataires(): array
    {
        $accordes = DB::table('user_capabilities')
            ->whereIn('capability', [
                Capability::Monitoring->value,
                Capability::MonitoringAdmin->value,
            ])
            ->pluck('user_id');

        $administrateurs = User::where('role', 'admin')->pluck('id');

        return $accordes->merge($administrateurs)->unique()->values()->all();
    }
}
