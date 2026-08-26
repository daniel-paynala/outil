<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messagerie\Services\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * État du dernier envoi de notification push.
 *
 * Une notification qui n'arrive pas a trop de causes possibles pour être
 * diagnostiquée à l'aveugle : la file n'est pas consommée, la clé OneSignal
 * est refusée, le destinataire n'a pas d'abonnement, ou la notification est
 * bien partie et c'est l'appareil qui ne l'affiche pas.
 *
 * `/api/monitoring/queue` répond déjà de la première. Celle-ci répond des
 * suivantes : elle dit si OneSignal a accepté le dernier envoi, et sinon
 * pourquoi il l'a refusé — information qui ne vivait jusqu'ici que dans les
 * logs du serveur, hors de portée de l'app.
 */
class PushHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $configured = ! empty(config('onesignal.app_id'))
            && ! empty(config('onesignal.rest_key'));

        try {
            $last = Cache::get(PushSender::LAST_ATTEMPT_KEY);
        } catch (Throwable) {
            $last = null;
        }

        return response()->json([
            'configured' => $configured,
            'last_attempt' => $last,
            'status' => $this->verdict($configured, $last),
            'hint' => $this->hint($configured, $last),
        ]);
    }

    /**
     * Aucune tentative connue n'est un état neutre, pas une panne : sur une
     * équipe de cinq personnes, une journée sans message est banale.
     *
     * @param  array<string, mixed>|null  $last
     */
    private function verdict(bool $configured, ?array $last): string
    {
        if (! $configured) {
            return 'down';
        }
        if ($last === null) {
            return 'unknown';
        }

        return ($last['ok'] ?? false) ? 'ok' : 'warn';
    }

    /** @param  array<string, mixed>|null  $last */
    private function hint(bool $configured, ?array $last): ?string
    {
        if (! $configured) {
            return 'ONESIGNAL_APP_ID ou ONESIGNAL_REST_KEY absent du `.env` de '
                .'production : aucun push ne peut partir.';
        }
        if ($last === null) {
            return 'Aucun envoi depuis le dernier redémarrage — rien à signaler.';
        }
        if ($last['ok'] ?? false) {
            return null;
        }

        $error = $last['error'] ?? 'motif inconnu';

        // Le cas de loin le plus fréquent, et le seul qui ne soit pas un bug :
        // la personne visée a refusé les notifications. Le dire évite de
        // chercher une panne côté serveur.
        if (str_contains($error, 'invalid_aliases') || str_contains($error, 'no subscri')) {
            return 'OneSignal ne connaît aucun abonnement pour les '
                .'destinataires visés : les notifications sont probablement '
                .'refusées sur leur appareil (Réglages → Arche → '
                .'Notifications).';
        }

        return "Dernier envoi refusé par OneSignal : {$error}";
    }
}
