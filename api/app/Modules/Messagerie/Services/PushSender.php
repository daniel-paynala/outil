<?php

namespace App\Modules\Messagerie\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi de notifications push via OneSignal.
 *
 * Le ciblage se fait par `external_id` — l'identifiant Supabase de
 * l'utilisateur, que l'app déclare au SDK à la connexion. Aucun jeton
 * d'appareil n'est donc stocké de notre côté : OneSignal tient la
 * correspondance, y compris quand quelqu'un a trois appareils ou réinstalle
 * l'app. Une table maison n'apporterait que de la dérive à maintenir.
 *
 * Toute panne est absorbée : une notification perdue est un désagrément, une
 * requête en échec est un bug.
 *
 * Absorbée ne veut pas dire invisible. Un push refusé par OneSignal — clé
 * expirée, application suspendue, destinataire inconnu — ne laissait jusqu'ici
 * qu'une ligne de log sur le serveur : côté app, l'absence de notification
 * était indiscernable d'un envoi réussi que personne n'avait vu passer. Chaque
 * tentative consigne donc désormais son issue, que `/api/monitoring/push`
 * expose.
 */
class PushSender
{
    /** Issue de la dernière tentative d'envoi, lue par la sonde de monitoring. */
    public const LAST_ATTEMPT_KEY = 'arche.push.last_attempt';

    /**
     * @param  array<int, string>  $userIds  identifiants Supabase
     * @param  array<string, mixed>  $data  charge utile lue par l'app au tap
     */
    /**
     * @param  array<int, string>  $userIds  identifiants Supabase
     * @param  array<string, mixed>  $data  charge utile lue par l'app au tap
     * @param  array<string, mixed>  $options  champs OneSignal supplémentaires,
     *                                         fusionnés dans la charge envoyée
     */
    public function send(
        array $userIds,
        string $title,
        string $body,
        array $data = [],
        array $options = [],
    ): void {
        $appId = config('onesignal.app_id');
        $key = config('onesignal.rest_key');

        // Compte non configuré : on n'essaie pas. La messagerie doit
        // fonctionner sans fournisseur de push.
        if (empty($appId) || empty($key)) {
            $this->record(false, 0, null, 'OneSignal non configuré');

            return;
        }

        if (empty($userIds)) {
            return;
        }

        try {
            $response = Http::withHeaders([
                // Format courant de l'API OneSignal — « Basic » est l'ancien
                // et n'est plus accepté sur les clés récentes.
                'Authorization' => "Key {$key}",
                'Content-Type' => 'application/json',
            ])
                ->timeout(config('onesignal.timeout', 8))
                ->post(config('onesignal.endpoint'), [
                    'app_id' => $appId,
                    'target_channel' => 'push',
                    'include_aliases' => ['external_id' => array_values($userIds)],
                    'headings' => ['en' => $title],
                    'contents' => ['en' => $body],
                    'data' => $data,
                    // Le badge iOS s'incrémente ; l'app le remet à zéro à
                    // l'ouverture de la conversation.
                    'ios_badge_type' => 'Increase',
                    'ios_badge_count' => 1,
                    ...$options,
                ]);

            if ($response->failed()) {
                Log::warning('Push refusé par OneSignal', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                $this->record(
                    false,
                    count($userIds),
                    $response->status(),
                    $this->reason($response->json()),
                );

                return;
            }

            $this->record(true, count($userIds), $response->status(), null);
        } catch (Throwable $e) {
            // Réseau, DNS, délai dépassé : on note et on passe. Le message est
            // déjà en base, il sera vu à l'ouverture de l'app.
            Log::warning('Push non envoyé : '.$e->getMessage());
            $this->record(false, count($userIds), null, $e->getMessage());
        }
    }

    /**
     * Extrait le motif du refus de la réponse OneSignal.
     *
     * L'API place ses messages tantôt dans `errors` (liste), tantôt dans
     * `errors.invalid_aliases` (objet) quand aucun destinataire ne lui est
     * connu — cas fréquent et parfaitement légitime : quelqu'un qui a refusé
     * les notifications n'a pas d'abonnement. On rend le texte brut, seul
     * élément qui permette de trancher.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function reason(?array $body): string
    {
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && array_is_list($errors) && $errors !== []) {
            return (string) $errors[0];
        }
        if (is_array($errors) && $errors !== []) {
            return json_encode($errors, JSON_UNESCAPED_UNICODE) ?: 'refus sans motif';
        }

        return 'refus sans motif';
    }

    /**
     * Consigne l'issue de la dernière tentative, pour la sonde de monitoring.
     *
     * Le cache suffit : cette information n'a de valeur que fraîche, et une
     * table dédiée grossirait indéfiniment pour un diagnostic qu'on ne consulte
     * qu'après coup. La durée couvre largement le temps de remarquer qu'une
     * notification manque.
     */
    private function record(bool $ok, int $recipients, ?int $status, ?string $error): void
    {
        try {
            Cache::put(self::LAST_ATTEMPT_KEY, [
                'at' => now()->toIso8601String(),
                'ok' => $ok,
                'recipients' => $recipients,
                'status' => $status,
                'error' => $error,
            ], now()->addDays(7));
        } catch (Throwable) {
            // Le cache est indisponible : ne jamais faire échouer un envoi
            // pour une écriture de diagnostic.
        }
    }
}
