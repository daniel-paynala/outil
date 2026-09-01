<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Monitoring\Models\MonitoredDatabase;
use App\Modules\Monitoring\Models\MonitoringAlert;
use App\Modules\Monitoring\Models\MonitoringProbe;
use App\Modules\Monitoring\Models\MonitoringProbeWindow;
use App\Modules\Monitoring\Services\DatabaseConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Les sondes et leurs incidents.
 */
class ProbeController extends Controller
{
    public function __construct(private readonly DatabaseConnector $connector) {}

    /**
     * L'état de la supervision, en un appel.
     *
     * Tout tient sur un écran : chaque sonde, sa dernière valeur par fenêtre, et
     * si un incident l'attend. Découper en plusieurs requêtes ferait payer un
     * aller-retour par section, pour un écran qu'on ouvre justement parce qu'on
     * est pressé.
     */
    public function index(): JsonResponse
    {
        $sondes = MonitoringProbe::with([
            'windows',
            'database:id,name,read_only_verified_at,last_error',
            'acknowledger:id,email,name,avatar_path',
        ])->orderBy('title')->get();

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
            'created_by' => $this->userId($request),
        ]);

        $this->remplacerFenetres($sonde, $data['windows']);

        return response()->json($sonde->load('windows'), 201);
    }

    public function update(Request $request, MonitoringProbe $probe): JsonResponse
    {
        $data = $this->valider($request);

        $probe->update([
            'database_id' => $data['database_id'],
            'title' => $data['title'],
            'unit' => $data['unit'] ?? $probe->unit,
            'query' => $data['query'],
        ]);

        // Les fenêtres sont remplacées, pas fusionnées : changer les paliers
        // change la signification de l'état, et garder un « plus haut palier
        // signalé » calculé sur l'ancienne échelle tairait le premier
        // franchissement de la nouvelle.
        $this->remplacerFenetres($probe, $data['windows']);

        return response()->json($probe->fresh()->load('windows'));
    }

    public function destroy(MonitoringProbe $probe): JsonResponse
    {
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
            'hours' => ['sometimes', 'integer', 'between:1,720'],
        ]);

        $base = MonitoredDatabase::findOrFail($data['database_id']);
        if (! $base->isUsable()) {
            return response()->json([
                'error' => 'Base inutilisable',
                'detail' => 'La lecture seule n\'a pas été constatée sur cette base.',
            ], 422);
        }

        try {
            $valeur = $this->connector->readValue($base, $data['query'], [
                'depuis' => now()->subHours($data['hours'] ?? 24)->toDateTimeString(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['ok' => true, 'value' => $valeur]);
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
        $probe->update([
            'counting_from' => now(),
            'acknowledged_by' => $this->userId($request),
        ]);

        // Les paliers se rouvrent tous : un nouveau franchissement doit se
        // signaler, même plus bas que celui qu'on vient de traiter.
        $probe->windows()->update(['highest_tier' => 0]);

        return response()->json($probe->fresh()->load(['windows', 'acknowledger']));
    }

    /** Les derniers franchissements, tous sondes confondues. */
    public function alerts(): JsonResponse
    {
        return response()->json(
            MonitoringAlert::with('probe:id,title,database_id')
                ->orderByDesc('raised_at')
                ->limit(100)
                ->get(),
        );
    }

    /** @return array<string, mixed> */
    private function valider(Request $request): array
    {
        return $request->validate([
            'database_id' => ['required', 'uuid', 'exists:monitored_databases,id'],
            'title' => ['required', 'string', 'max:120'],
            'unit' => ['sometimes', 'string', 'max:40'],
            'query' => ['required', 'string', 'max:4000'],

            // Au moins une fenêtre : une sonde sans fenêtre ne s'exécuterait
            // jamais et resterait à l'écran comme si elle veillait.
            'windows' => ['required', 'array', 'min:1', 'max:4'],
            'windows.*.hours' => ['required', 'integer', 'between:1,720'],
            'windows.*.tiers' => ['present', 'array', 'max:12'],
            'windows.*.tiers.*' => ['integer', 'min:1'],
        ]);
    }

    /** @param  array<int, array{hours: int, tiers: array<int, int>}>  $fenetres */
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
                'tiers' => $paliers,
            ]);
        }
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }
}
