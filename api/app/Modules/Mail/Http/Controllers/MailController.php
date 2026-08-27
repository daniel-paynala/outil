<?php

namespace App\Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Mail\Models\GoogleAccount;
use App\Modules\Mail\Services\GmailWatcher;
use App\Modules\Mail\Services\GoogleOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Rattachement d'une boîte Google Workspace.
 *
 * Trois opérations seulement : brancher, débrancher, dire où on en est. Tout
 * le reste — lire, répondre, envoyer — se fait de l'appareil à Gmail
 * directement, sans passer par ici : le courrier ne transite pas par notre
 * infrastructure et n'y laisse rien.
 */
class MailController extends Controller
{
    public function __construct(
        private readonly GoogleOAuth $oauth,
        private readonly GmailWatcher $watcher,
    ) {}

    /**
     * État de la connexion, tel que l'écran de réglages le montre.
     *
     * `watch_healthy` mérite d'exister séparément de `connected` : un compte
     * peut être rattaché alors que la surveillance s'est éteinte — jeton
     * révoqué, renouvellement en échec. Les confondre ferait dire à l'app que
     * tout va bien alors qu'aucune notification n'arrivera plus.
     */
    public function status(Request $request): JsonResponse
    {
        $account = $this->accountFor($request);

        return response()->json([
            'connected' => $account !== null,
            'email' => $account?->email,
            'connected_at' => $account?->created_at?->toIso8601String(),
            'watch_expires_at' => $account?->watch_expires_at?->toIso8601String(),
            'watch_healthy' => $account !== null
                && $account->watch_expires_at !== null
                && $account->watch_expires_at->isFuture(),
            'last_error' => $account?->last_error,
            'last_error_at' => $account?->last_error_at?->toIso8601String(),
            // L'app affiche l'écran de connexion différemment selon que le
            // serveur est prêt : sans identifiants OAuth, inutile de proposer
            // un bouton qui échouera.
            'configured' => ! empty(config('google.client_id'))
                && ! empty(config('google.client_secret')),
        ]);
    }

    /**
     * Échange le code d'autorisation de l'appareil et démarre la surveillance.
     */
    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_auth_code' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);

        $email = strtolower($data['email']);

        // Refus de toute adresse hors du domaine de l'organisation. Sans ce
        // garde-fou, quelqu'un pourrait rattacher une boîte personnelle et
        // faire surveiller son courrier privé par le serveur de l'entreprise.
        $domaine = config('google.workspace_domain');
        if (! empty($domaine) && ! str_ends_with($email, '@'.strtolower($domaine))) {
            return response()->json([
                'message' => "Seules les adresses @{$domaine} peuvent être "
                    .'rattachées à Arche.',
            ], 422);
        }

        try {
            $jetons = $this->oauth->exchange($data['server_auth_code']);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $account = GoogleAccount::updateOrCreate(
            ['user_id' => $this->userId($request)],
            [
                'email' => $email,
                'refresh_token' => $jetons['refresh_token'],
                'scopes' => $jetons['scope'],
                // Repartir de zéro : un ancien curseur d'historique appartient
                // à une surveillance qui n'existe plus.
                'history_id' => null,
                'watch_expires_at' => null,
                'last_error' => null,
                'last_error_at' => null,
            ],
        );

        try {
            $this->watcher->start($account);
        } catch (Throwable $e) {
            // Le rattachement reste valable : lire et écrire fonctionneront
            // depuis l'app. Seules les notifications manquent, et le dire vaut
            // mieux que d'annuler une connexion par ailleurs réussie.
            $account->recordError($e->getMessage());

            return response()->json([
                'email' => $account->email,
                'watch_healthy' => false,
                'warning' => 'Boîte rattachée, mais la surveillance n\'a pas '
                    .'démarré : '.$e->getMessage(),
            ], 201);
        }

        return response()->json([
            'email' => $account->email,
            'watch_healthy' => true,
            'watch_expires_at' => $account->fresh()->watch_expires_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Débranche la boîte : arrête la surveillance, révoque, oublie le jeton.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $account = $this->accountFor($request);

        if ($account === null) {
            return response()->json(null, 204);
        }

        $this->watcher->stop($account);

        // Révoquer avant de supprimer : après, on n'aurait plus le jeton, et
        // l'autorisation resterait active dans le compte Google de la personne
        // — qui croirait pourtant avoir coupé l'accès.
        $this->oauth->revoke($account->refresh_token);

        $account->delete();

        return response()->json(null, 204);
    }

    private function accountFor(Request $request): ?GoogleAccount
    {
        return GoogleAccount::where('user_id', $this->userId($request))->first();
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }
}
