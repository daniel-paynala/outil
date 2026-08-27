<?php

namespace App\Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Mail\Jobs\NotifyNewMail;
use App\Modules\Mail\Models\GoogleAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Réception des avis Gmail publiés par Google Pub/Sub.
 *
 * ## Pourquoi ce point d'entrée est hors authentification
 *
 * C'est Google qui appelle, pas un utilisateur : aucun jeton Supabase ne peut
 * accompagner la requête. L'accès est donc gardé par un secret partagé placé
 * en paramètre d'URL de l'abonnement push — la méthode que Google documente
 * pour ce cas. Sans lui, n'importe qui connaissant l'adresse pourrait déclencher
 * des notifications.
 *
 * ## Pourquoi il ne fait presque rien
 *
 * Pub/Sub attend une réponse rapide et **retente tout ce qui n'aboutit pas en
 * 2xx**, avec un intervalle qui s'allonge. Interroger Gmail ici — plusieurs
 * appels réseau — ferait dépasser le délai, provoquerait une nouvelle
 * publication, et la même notification partirait deux ou trois fois. On accuse
 * donc réception immédiatement et le travail part en file.
 */
class PubSubController extends Controller
{
    /**
     * Fenêtre de déduplication des avis.
     *
     * Pub/Sub garantit « au moins une fois », pas « exactement une fois » : le
     * même message peut être livré plusieurs fois même quand on répond
     * correctement. Sans ce filtre, une seule arrivée de courrier produirait
     * plusieurs notifications identiques.
     */
    private const DEDUPE_TTL = 600;

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            // 403 et non 401 : il n'y a pas d'authentification à réessayer.
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $enveloppe = $request->input('message', []);
        $identifiant = $enveloppe['messageId'] ?? $enveloppe['message_id'] ?? null;

        // Avis déjà traité : on acquitte sans rien refaire. Répondre en erreur
        // ferait retenter Google indéfiniment.
        if ($identifiant !== null
            && ! Cache::add("gmail.pubsub.{$identifiant}", true, self::DEDUPE_TTL)) {
            return response()->json(null, 204);
        }

        $charge = $this->decode($enveloppe['data'] ?? null);
        $adresse = $charge['emailAddress'] ?? null;

        if ($adresse === null) {
            // Charge illisible : on acquitte quand même. La retenter ne la
            // rendra pas lisible, et Pub/Sub insisterait pendant sept jours.
            return response()->json(null, 204);
        }

        $account = GoogleAccount::where('email', strtolower($adresse))->first();

        if ($account !== null) {
            NotifyNewMail::dispatch($account->id);
        }

        return response()->json(null, 204);
    }

    /**
     * Compare le secret partagé en temps constant.
     *
     * `hash_equals` plutôt que `===` : une comparaison qui s'arrête au premier
     * caractère différent laisse deviner le secret par la mesure du temps de
     * réponse, sur un point d'entrée public et appelable à volonté.
     */
    private function authorized(Request $request): bool
    {
        $attendu = config('google.pubsub_token');

        if (empty($attendu)) {
            return false;
        }

        return hash_equals($attendu, (string) $request->query('token', ''));
    }

    /** @return array<string, mixed> */
    private function decode(?string $data): array
    {
        if ($data === null) {
            return [];
        }

        $json = base64_decode(strtr($data, '-_', '+/'), true);

        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
