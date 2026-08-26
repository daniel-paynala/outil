<?php

namespace App\Modules\Messagerie\Services;

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
 */
class PushSender
{
    /**
     * @param  array<int, string>  $userIds  identifiants Supabase
     * @param  array<string, mixed>  $data  charge utile lue par l'app au tap
     */
    public function send(
        array $userIds,
        string $title,
        string $body,
        array $data = [],
    ): void {
        $appId = config('onesignal.app_id');
        $key = config('onesignal.rest_key');

        // Compte non configuré : on n'essaie pas. La messagerie doit
        // fonctionner sans fournisseur de push.
        if (empty($appId) || empty($key) || empty($userIds)) {
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
                ]);

            if ($response->failed()) {
                Log::warning('Push refusé par OneSignal', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }
        } catch (Throwable $e) {
            // Réseau, DNS, délai dépassé : on note et on passe. Le message est
            // déjà en base, il sera vu à l'ouverture de l'app.
            Log::warning('Push non envoyé : '.$e->getMessage());
        }
    }
}
