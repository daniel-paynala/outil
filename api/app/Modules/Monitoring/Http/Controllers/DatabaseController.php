<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
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
    public function __construct(
        private readonly DatabaseConnector $connector,
        private readonly ActivityLogger $activity,
    ) {}

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

        // L'hôte et l'utilisateur, jamais le mot de passe. Le journal d'audit
        // se consulte depuis l'application : y déposer des identifiants
        // ferait du registre censé surveiller les accès le meilleur endroit
        // où les voler.
        $this->activity->logGlobal(
            $this->userId($request),
            'monitoring.database.added',
            $base,
            $base->name,
            ['host' => $base->host, 'dbname' => $base->dbname, 'username' => $base->username],
        );

        return response()->json($base->fresh(), 201);
    }

    /**
     * Modifie une base sans la débrancher.
     *
     * ## Pourquoi un `PATCH` et non « supprimer puis rebrancher »
     *
     * Retirer une base emporte ses sondes par cascade, et avec elles leur
     * `counting_from` et leurs paliers déjà signalés. Renommer « Facturation »
     * en « Facturation — production » ferait alors perdre l'historique de
     * comptage et rouvrirait tous les incidents déjà traités. Un libellé ne
     * peut pas coûter ça.
     *
     * ## Deux modifications de nature différente
     *
     * Le nom n'est qu'une étiquette : il change, on enregistre, c'est tout.
     *
     * Les champs de connexion, eux, changent **quelle** base est interrogée ou
     * **avec quels droits**. Ils repassent donc par la même épreuve qu'à
     * l'ajout : on tente d'écrire, et on refuse si l'écriture passe. Sans cela,
     * une rotation de mot de passe pourrait remplacer en silence un compte de
     * lecture par un compte d'écriture sur une base de production.
     */
    public function update(Request $request, MonitoredDatabase $database): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'host' => ['sometimes', 'string', 'max:255'],
            'port' => ['sometimes', 'integer', 'between:1,65535'],
            'dbname' => ['sometimes', 'string', 'max:120'],
            'username' => ['sometimes', 'string', 'max:120'],
            'password' => ['sometimes', 'string', 'max:255'],
        ]);

        $connexion = array_intersect_key(
            $data,
            array_flip(['host', 'port', 'dbname', 'username', 'password']),
        );

        if ($connexion === []) {
            $avant = $database->name;
            $database->update(['name' => $data['name'] ?? $avant]);

            if ($database->name !== $avant) {
                $this->activity->logGlobal(
                    $this->userId($request),
                    'monitoring.database.renamed',
                    $database,
                    $database->name,
                    ['from' => $avant],
                );
            }

            return response()->json($database->fresh());
        }

        // La vérification porte sur un exemplaire de travail, jamais sur la
        // ligne enregistrée : un `update` suivi d'un refus laisserait en base
        // des identifiants qu'on vient précisément de juger inacceptables.
        $candidate = $database->replicate();
        $candidate->fill($connexion);

        $verdict = $this->connector->verifyReadOnly($candidate);
        if (! $verdict['ok']) {
            return response()->json([
                'error' => 'Modification refusée',
                'detail' => $verdict['error'],
            ], 422);
        }

        $database->update([
            ...$data,
            'read_only_verified_at' => now(),
            'last_error' => null,
        ]);

        $this->activity->logGlobal(
            $this->userId($request),
            'monitoring.database.updated',
            $database,
            $database->name,
            ['changed' => array_keys($data)],
        );

        return response()->json($database->fresh());
    }

    /**
     * Revérifie une base déjà enregistrée.
     *
     * Les droits d'un compte changent sans prévenir. Une base dont l'accès est
     * devenu inscriptible doit cesser d'être interrogée, et le dire.
     */
    public function verify(Request $request, MonitoredDatabase $database): JsonResponse
    {
        $etaitUtilisable = $database->isUsable();
        $verdict = $this->connector->verifyReadOnly($database);

        $database->update([
            'read_only_verified_at' => $verdict['ok'] ? now() : null,
            'last_error' => $verdict['error'],
        ]);

        // Seul le **changement** d'état est journalisé. La revérification est
        // aussi appelée à la main pour se rassurer : en tracer chacune
        // noierait sous des lignes identiques le jour où une base bascule.
        if ($etaitUtilisable !== $verdict['ok']) {
            $this->activity->logGlobal(
                $this->userId($request),
                $verdict['ok']
                    ? 'monitoring.database.restored'
                    : 'monitoring.database.disabled',
                $database,
                $database->name,
                ['reason' => $verdict['error']],
            );
        }

        return response()->json($database->fresh());
    }

    public function destroy(Request $request, MonitoredDatabase $database): JsonResponse
    {
        // Journalisé **avant** la suppression : après, la ligne n'existe plus
        // et il ne resterait qu'un identifiant sans nom à montrer.
        $this->activity->logGlobal(
            $this->userId($request),
            'monitoring.database.removed',
            $database,
            $database->name,
            [
                'host' => $database->host,
                'dbname' => $database->dbname,
                'probes' => $database->probes()->count(),
            ],
        );

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
