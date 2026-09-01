<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Monitoring\Models\MonitoredDatabase;
use App\Modules\Monitoring\Services\DatabaseConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Les bases surveillées.
 *
 * Consultables par qui a le droit de superviser, modifiables par qui a celui de
 * l'administrer — la distinction compte : voir qu'une base va mal et pouvoir en
 * brancher une nouvelle avec ses identifiants ne demandent pas la même
 * confiance.
 */
class DatabaseController extends Controller
{
    public function __construct(private readonly DatabaseConnector $connector) {}

    public function index(): JsonResponse
    {
        // `$hidden` exclut déjà le mot de passe ; les colonnes sont énumérées
        // quand même. Une sélection explicite ne peut pas être défaite par un
        // ajout de colonne distrait.
        return response()->json(
            MonitoredDatabase::query()
                ->select([
                    'id', 'name', 'host', 'port', 'dbname', 'username',
                    'read_only_verified_at', 'last_error', 'created_at',
                ])
                ->withCount('probes')
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * Ajoute une base, après avoir constaté qu'on ne peut pas y écrire.
     *
     * La vérification a lieu **avant** l'enregistrement : une base refusée ne
     * laisse aucune trace, et surtout pas ses identifiants.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['sometimes', 'integer', 'between:1,65535'],
            'dbname' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $base = new MonitoredDatabase([
            ...$data,
            'port' => $data['port'] ?? 5432,
            'created_by' => $this->userId($request),
        ]);
        $base->id = (string) Str::uuid();

        $verdict = $this->connector->verifyReadOnly($base);
        if (! $verdict['ok']) {
            return response()->json([
                'error' => 'Base refusée',
                'detail' => $verdict['error'],
            ], 422);
        }

        $base->read_only_verified_at = now();
        $base->save();

        return response()->json($base->fresh(), 201);
    }

    /**
     * Revérifie une base déjà enregistrée.
     *
     * Les droits d'un compte changent sans prévenir. Une base dont l'accès est
     * devenu inscriptible doit cesser d'être interrogée, et le dire.
     */
    public function verify(MonitoredDatabase $database): JsonResponse
    {
        $verdict = $this->connector->verifyReadOnly($database);

        $database->update([
            'read_only_verified_at' => $verdict['ok'] ? now() : null,
            'last_error' => $verdict['error'],
        ]);

        return response()->json($database->fresh());
    }

    public function destroy(MonitoredDatabase $database): JsonResponse
    {
        // Les sondes suivent par cascade : une sonde sans base ne peut rien
        // interroger, et la garder ne ferait qu'encombrer l'écran.
        $database->delete();

        return response()->json(['deleted' => true]);
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }
}
