<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Monitoring\Models\MonitoredDatabase;
use App\Modules\Monitoring\Models\MonitoringAlert;
use App\Modules\Monitoring\Models\MonitoringProbe;
use App\Modules\Monitoring\Models\MonitoringProbeWindow;
use App\Modules\Monitoring\Services\DatabaseConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Les sondes et leurs incidents.
 */
class ProbeController extends Controller
{
    public function __construct(
        private readonly DatabaseConnector $connector,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * L'état de la supervision, en un appel.
     *
     * Tout tient sur un écran : chaque sonde, sa dernière valeur par fenêtre, et
     * si un incident l'attend. Découper en plusieurs requêtes ferait payer un
     * aller-retour par section, pour un écran qu'on ouvre justement parce qu'on
     * est pressé.
     */
    public function index(Request $request): JsonResponse
    {
        $moi = $this->user($request);

        $sondes = MonitoringProbe::with([
            'windows',
            'database:id,name,read_only_verified_at,last_error',
            'acknowledger:id,email,name,avatar_path',
            'viewers:id,email,name,avatar_path',
        ])
            ->orderBy('title')
            ->get()
            // Filtré ici plutôt qu'en SQL : la règle tient en une méthode du
            // modèle, et c'est la même qui décide de l'affichage, de
            // l'acquittement et des notifications. Trois formulations d'une
            // même règle finiraient par diverger, et la divergence se paierait
            // en fuite.
            ->filter(fn (MonitoringProbe $s) => $s->isVisibleTo($moi))
            ->values();

        return response()->json([
            'probes' => $sondes,
            // Ce qui attend un geste, en tête et à part : c'est la seule chose
            // qu'on vient chercher quand une alerte a sonné.
            'open_incidents' => $sondes
                ->filter(fn (MonitoringProbe $s) => $s->hasOpenIncident())
                ->pluck('id')
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->valider($request);

        $sonde = MonitoringProbe::create([
            'id' => (string) Str::uuid(),
            'database_id' => $data['database_id'],
            'title' => $data['title'],
            'unit' => $data['unit'] ?? 'événements',
            'query' => $data['query'],
            'timeout_ms' => $data['timeout_ms'] ?? 8000,
            'interval_minutes' => $data['interval_minutes'] ?? 1,
            'created_by' => $this->userId($request),
        ]);

        $this->remplacerFenetres($sonde, $data['windows']);
        $this->remplacerViewers(
            $sonde,
            $data['viewers'] ?? null,
            $this->userId($request),
        );

        $this->activity->logGlobal(
            $this->userId($request),
            'monitoring.probe.created',
            $sonde,
            $sonde->title,
            ['database' => $sonde->database?->name, 'query' => $sonde->query],
        );

        return response()->json($sonde->load(['windows', 'viewers']), 201);
    }

    public function update(Request $request, MonitoringProbe $probe): JsonResponse
    {
        $data = $this->valider($request);
        $requeteAvant = $probe->query;

        $probe->update([
            'database_id' => $data['database_id'],
            'title' => $data['title'],
            'unit' => $data['unit'] ?? $probe->unit,
            'query' => $data['query'],
            'timeout_ms' => $data['timeout_ms'] ?? $probe->timeout_ms,
            'interval_minutes' => $data['interval_minutes'] ?? $probe->interval_minutes,
        ]);

        // Les fenêtres sont remplacées, pas fusionnées : changer les paliers
        // change la signification de l'état, et garder un « plus haut palier
        // signalé » calculé sur l'ancienne échelle tairait le premier
        // franchissement de la nouvelle.
        $this->remplacerFenetres($probe, $data['windows']);
        $this->remplacerViewers(
            $probe,
            $data['viewers'] ?? null,
            $this->userId($request),
        );

        // La requête est journalisée en entier, pas seulement « modifiée ».
        // Une sonde qui cesse de signaler après une modification pose une
        // seule question — qu'est-ce qu'elle comptait avant ? — et sans le
        // texte d'avant, personne ne peut y répondre.
        $this->activity->logGlobal(
            $this->userId($request),
            'monitoring.probe.updated',
            $probe,
            $probe->title,
            ['query_before' => $requeteAvant, 'query_after' => $probe->query],
        );

        return response()->json($probe->fresh()->load(['windows', 'viewers']));
    }

    public function destroy(Request $request, MonitoringProbe $probe): JsonResponse
    {
        $this->activity->logGlobal(
            $this->userId($request),
            'monitoring.probe.deleted',
            $probe,
            $probe->title,
            ['database' => $probe->database?->name, 'query' => $probe->query],
        );

        $probe->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Essaie une requête sans l'enregistrer.
     *
     * Écrire une sonde qui ne rend pas ce qu'il faut est le défaut le plus
     * probable, et le plus silencieux : elle s'installerait et ne signalerait
     * jamais rien. On l'exécute donc pour de vrai, avec les mêmes garde-fous
     * qu'en production.
     */
    public function tryOut(Request $request): JsonResponse
    {
        $data = $request->validate([
            'database_id' => ['required', 'uuid', 'exists:monitored_databases,id'],
            'query' => ['required', 'string', 'max:4000'],

            // Huit secondes suffisent à compter sur une table indexée ; une
            // requête qui croise des centaines de milliers de lignes de
            // journal en demande davantage. Plafonné à soixante : au-delà, une
            // sonde n'est plus une sonde, c'est un rapport.
            'timeout_ms' => ['sometimes', 'integer', 'between:1000,60000'],
            'interval_minutes' => ['sometimes', 'integer', 'between:1,1440'],
            'hours' => ['sometimes', 'integer', 'between:1,720'],
            'timeout_ms' => ['sometimes', 'integer', 'between:1000,60000'],
        ]);

        $base = MonitoredDatabase::findOrFail($data['database_id']);
        if (! $base->isUsable()) {
            return response()->json([
                'error' => 'Base inutilisable',
                'detail' => 'La lecture seule n\'a pas été constatée sur cette base.',
            ], 422);
        }

        try {
            $lu = $this->connector->read(
                $base,
                $data['query'],
                ['depuis' => now()->subHours($data['hours'] ?? 24)->toDateTimeString()],
                $data['timeout_ms'] ?? null,
            );
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'value' => $lu['valeur'],
            'detail' => $lu['detail'],
        ]);
    }

    /**
     * Acquitte : l'incident est traité, on recompte à partir de maintenant.
     *
     * Les événements restent dans la base surveillée — on ne les efface pas. Ce
     * qu'on déplace est le point de départ du comptage, et c'est ce qui permet
     * de refermer un incident qui ne redescendrait jamais tout seul.
     */
    public function acknowledge(Request $request, MonitoringProbe $probe): JsonResponse
    {
        // 404 et non 403, comme le middleware : dire « interdit » confirmerait
        // que cette sonde existe, et son identifiant se devine en essayant.
        abort_unless($probe->isVisibleTo($this->user($request)), 404);

        $paliersAcquittes = $probe->windows()
            ->where('severest_tier', '>', 0)
            ->get()
            ->mapWithKeys(fn ($f) => ["{$f->hours}h" => $f->severest_tier])
            ->all();

        $probe->update([
            'counting_from' => now(),
            'acknowledged_by' => $this->userId($request),
        ]);

        // Les paliers se rouvrent tous : un nouveau franchissement doit se
        // signaler, même plus bas que celui qu'on vient de traiter.
        $probe->windows()->update(['severest_tier' => 0]);

        // Acquitter est une décision, pas une manipulation : quelqu'un déclare
        // que l'incident est traité et fait repartir le comptage. Les valeurs
        // au moment du geste sont conservées — c'est ce qui permettra de dire,
        // plus tard, si l'incident avait vraiment été traité.
        $this->activity->logGlobal(
            $this->userId($request),
            'monitoring.probe.acknowledged',
            $probe,
            $probe->title,
            [
                'database' => $probe->database?->name,
                'tiers' => $paliersAcquittes,
            ],
        );

        return response()->json($probe->fresh()->load(['windows', 'acknowledger']));
    }

    /** Les derniers franchissements des sondes qu'on a le droit de voir. */
    public function alerts(Request $request): JsonResponse
    {
        $moi = $this->user($request);

        // Sans ce filtre, l'historique dirait le nom, le volume et l'heure de
        // ce que la liste masque — une porte fermée à côté d'une fenêtre
        // ouverte.
        //
        // `select id` et non le modèle entier : on ne veut que des
        // identifiants, et charger cent sondes complètes pour n'en garder que
        // la clé se paie sur une connexion qui traverse l'Europe.
        $visibles = MonitoringProbe::select('id')
            ->with('viewers:id')
            ->get()
            ->filter(fn (MonitoringProbe $s) => $s->isVisibleTo($moi))
            ->pluck('id');

        return response()->json(
            MonitoringAlert::with('probe:id,title,database_id')
                ->whereIn('probe_id', $visibles)
                ->orderByDesc('raised_at')
                ->limit(100)
                ->get(),
        );
    }

    /** @return array<string, mixed> */
    private function valider(Request $request): array
    {
        $regles = [
            'database_id' => ['required', 'uuid', 'exists:monitored_databases,id'],
            'title' => ['required', 'string', 'max:120'],
            'unit' => ['sometimes', 'string', 'max:40'],
            'query' => ['required', 'string', 'max:4000'],

            // Au moins une fenêtre : une sonde sans fenêtre ne s'exécuterait
            // jamais et resterait à l'écran comme si elle veillait.
            'windows' => ['required', 'array', 'min:1', 'max:4'],
            'windows.*.hours' => ['required', 'integer', 'between:1,720'],
            'windows.*.mode' => [
                'sometimes',
                'in:glissante,calendaire,mensuelle,annuelle,totale',
            ],
            'windows.*.direction' => ['sometimes', 'in:croissant,decroissant'],
            'windows.*.tiers' => ['present', 'array', 'max:12'],
            'windows.*.tiers.*' => ['integer', 'min:1'],

            // Absente : la liste n'est pas touchée. Vide : la sonde redevient
            // visible de tous. La distinction compte — voir `remplacerViewers`.
            'viewers' => ['sometimes', 'array', 'max:50'],
            'viewers.*' => ['uuid', 'exists:users,id'],
        ];

        $donnees = $request->validate($regles);

        // Une fenêtre calendaire ne se découpe qu'en journées entières. « Six
        // heures depuis minuit » changerait de longueur au fil de la journée :
        // le chiffre rendu ne voudrait rien dire, et personne ne le verrait.
        foreach ($donnees['windows'] as $i => $fenetre) {
            if (($fenetre['mode'] ?? 'glissante') === 'calendaire'
                && $fenetre['hours'] % 24 !== 0) {
                throw ValidationException::withMessages([
                    "windows.{$i}.hours" => 'Une fenêtre calendaire doit couvrir '
                        .'un nombre entier de journées : 24, 48, 72…',
                ]);
            }
        }

        return $donnees;
    }

    /**
     * @param  array<int, array{
     *     hours: int, mode?: string, direction?: string, tiers: array<int, int>
     * }>  $fenetres
     */
    private function remplacerFenetres(MonitoringProbe $sonde, array $fenetres): void
    {
        $sonde->windows()->delete();

        foreach ($fenetres as $fenetre) {
            $paliers = array_values(array_unique(array_map('intval', $fenetre['tiers'])));
            sort($paliers);

            MonitoringProbeWindow::create([
                'id' => (string) Str::uuid(),
                'probe_id' => $sonde->id,
                'hours' => $fenetre['hours'],
                'mode' => $fenetre['mode'] ?? 'glissante',
                'direction' => $fenetre['direction'] ?? 'croissant',
                'tiers' => $paliers,
            ]);
        }
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }

    private function user(Request $request): User
    {
        return $request->attributes->get('user')
            ?? abort(401, 'Missing user');
    }

    /**
     * Enregistre la liste des personnes autorisées.
     *
     * Absente de la requête, la liste n'est pas touchée — modifier les paliers
     * d'une sonde ne doit pas en ouvrir l'accès par omission. Explicitement
     * vide, la sonde redevient visible de tous.
     *
     * @param  array<int, string>|null  $userIds
     */
    private function remplacerViewers(
        MonitoringProbe $sonde,
        ?array $userIds,
        string $auteur,
    ): void {
        if ($userIds === null) {
            return;
        }

        $sonde->viewers()->sync(
            collect($userIds)
                ->unique()
                ->mapWithKeys(fn (string $id) => [
                    $id => ['granted_by' => $auteur, 'granted_at' => now()],
                ])
                ->all(),
        );
    }
}
