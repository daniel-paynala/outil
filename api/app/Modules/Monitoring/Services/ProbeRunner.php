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
            ->filter(fn (MonitoringProbe $s) => $s->database?->isUsable() ?? false)
            // Filtré ici et non dans la requête : la cadence se lit sur les
            // fenêtres déjà chargées, et interroger la base pour savoir s'il
            // faut interroger la base serait un aller-retour de trop.
            ->filter(fn (MonitoringProbe $s) => $s->isDue());

        foreach ($sondes as $sonde) {
            try {
                $this->run($sonde);
            } catch (Throwable $e) {
                // L'erreur appartient à la sonde, pas à la base.
                //
                // Constaté en usage : une seule sonde trop lente peignait son
                // « statement timeout » sur les dix autres cartes de la même
                // base, qui allaient parfaitement bien. On cherchait une panne
                // partout au lieu d'une requête à un seul endroit.
                $sonde->update(['last_error' => $e->getMessage()]);
                Log::warning('Sonde en échec', [
                    'probe' => $sonde->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function run(MonitoringProbe $sonde): void
    {
        $execute = false;

        foreach ($sonde->windows as $fenetre) {
            // La cadence se décide fenêtre par fenêtre : une même sonde peut
            // porter une fenêtre d'une heure à relancer chaque minute et un
            // cumul annuel à recharger une fois par jour.
            if (! $fenetre->isDue($sonde->interval_minutes ?? 1)) {
                continue;
            }

            $execute = true;
            $depuis = $this->countingFrom($sonde, $fenetre);

            $lu = $this->connector->read(
                $sonde->database,
                $sonde->query,
                ['depuis' => $depuis->toDateTimeString()],
                $sonde->timeout_ms,
            );
            $valeur = $lu['valeur'];

            $palier = Tiers::toRaise(
                $valeur,
                $fenetre->sortedTiers(),
                $fenetre->severest_tier,
                $fenetre->direction(),
            );

            $fenetre->update([
                'last_value' => $valeur,
                'last_detail' => $lu['detail'] === [] ? null : $lu['detail'],
                'last_run_at' => now(),
                // Mis à jour **avant** la notification : si l'envoi échoue, on
                // préfère une alerte perdue à une alerte répétée à chaque tour.
                'severest_tier' => $palier ?? $fenetre->severest_tier,
            ]);

            if ($palier === null) {
                continue;
            }

            $this->signaler($sonde, $fenetre, $palier, $valeur);
        }

        // Rien n'a tourné : on ne déclare pas pour autant que tout va bien.
        // Effacer une erreur qu'aucune exécution n'a démentie la ferait
        // disparaître de l'écran sans que rien ne l'ait résolue.
        if ($execute) {
            $sonde->update(['last_error' => null]);
        }
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

        // « Depuis toujours » ne se déplace pas. Acquitter un jalon rouvre ses
        // paliers — « oui, j'ai vu les cent millions » — mais ne peut pas
        // effacer l'historique : un cumul qui repartirait de zéro à chaque
        // accusé de réception ne mesurerait plus rien. C'est la seule fenêtre
        // où l'acquittement n'agit pas sur la valeur.
        if ($fenetre->mode === 'totale') {
            return $debutFenetre;
        }

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
        $ici = fn () => now()->timezone(config('monitoring.timezone'));

        return match ($fenetre->mode) {
            // Depuis le 1er du mois, et depuis le 1er janvier. Le mécanisme
            // des heures ne pouvait exprimer ni l'un ni l'autre : un mois n'a
            // pas une durée fixe — 720 heures ne sont pas février — et une
            // année dépasse de loin le plafond de 720 heures d'une fenêtre.
            'mensuelle' => $ici()->startOfMonth()->utc(),
            'annuelle' => $ici()->startOfYear()->utc(),

            // Depuis toujours. Une date que la production ne peut pas
            // précéder, plutôt qu'un `null` que chaque requête devrait savoir
            // interpréter.
            'totale' => Carbon::create(1970, 1, 1)->utc(),

            'calendaire' => $ici()
                ->startOfDay()
                ->subDays(max(intdiv($fenetre->hours, 24) - 1, 0))
                ->utc(),

            default => now()->subHours($fenetre->hours),
        };
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

        // « palier 10 » décrit un seuil qu'on dépasse, « plancher 50 » un seuil
        // sous lequel on tombe. Employer le même mot pour les deux ferait lire
        // « palier 50 » à quelqu'un dont la production vient de s'effondrer, et
        // il comprendrait l'inverse.
        $seuil = $fenetre->direction()->label();

        // Les montants se comptent en millions de francs. « 45000000 » ne se
        // lit pas sur un écran verrouillé ; « 45 000 000 » se lit.
        $lisible = number_format($valeur, 0, ',', ' ');
        $seuilLisible = number_format($palier, 0, ',', ' ');

        $corps = "{$lisible} {$sonde->unit} {$fenetre->periodLabel()} "
            ."({$seuil} {$seuilLisible}).";

        foreach ($this->destinataires($sonde) as $userId) {
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
     * Qui reçoit les alertes de cette sonde.
     *
     * Les porteurs du droit de supervision, et les administrateurs qui l'ont
     * implicitement. La requête est refaite à chaque alerte : un droit retiré
     * ce matin ne doit pas continuer à recevoir des alertes cet après-midi.
     *
     * ## La restriction s'applique ici aussi, et c'est le point important
     *
     * Une sonde restreinte qui notifierait tout le monde annoncerait son nom,
     * son volume et son heure à des gens qui n'ont pas le droit de la voir —
     * une porte fermée à côté d'une fenêtre ouverte. Pire : la notification
     * arrive sur un écran verrouillé, donc là où on ne contrôle plus rien.
     *
     * Les administrateurs de la supervision restent destinataires : ils
     * peuvent de toute façon lire la sonde, et une alerte qu'ils ne recevraient
     * pas serait un incident que personne ne traite un jour de congé.
     *
     * @return array<int, string>
     */
    private function destinataires(MonitoringProbe $sonde): array
    {
        $administrateursSupervision = DB::table('user_capabilities')
            ->where('capability', Capability::MonitoringAdmin->value)
            ->pluck('user_id')
            ->merge(User::where('role', 'admin')->pluck('id'))
            ->unique();

        $restreinte = DB::table('monitoring_probe_viewers')
            ->where('probe_id', $sonde->id)
            ->pluck('user_id');

        if ($restreinte->isNotEmpty()) {
            return $restreinte
                ->merge($administrateursSupervision)
                ->unique()
                ->values()
                ->all();
        }

        return DB::table('user_capabilities')
            ->where('capability', Capability::Monitoring->value)
            ->pluck('user_id')
            ->merge($administrateursSupervision)
            ->unique()
            ->values()
            ->all();
    }
}
