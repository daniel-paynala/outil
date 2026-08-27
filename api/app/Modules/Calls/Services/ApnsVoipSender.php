<?php

namespace App\Modules\Calls\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Envoi de pushes VoIP à Apple, en direct.
 *
 * ## Pourquoi ne pas passer par OneSignal
 *
 * OneSignal sait envoyer du VoIP, mais au prix d'une **seconde application**
 * OneSignal et d'un **certificat VoIP qui expire chaque année**. Le jour de
 * l'expiration, les appels cesseraient d'arriver sans erreur ni avertissement.
 * Arche a déjà connu deux pannes de cette famille — un worker de file disparu,
 * une surveillance Gmail éteinte — et chacune a coûté des jours.
 *
 * Une clé `.p8` n'expire pas, et l'envoi direct retire un intermédiaire du
 * chemin le plus sensible à la latence de toute l'application : le temps entre
 * « j'appelle » et « ça sonne ».
 *
 * ## Ce qu'Apple exige, et qu'on ne peut pas contourner
 *
 * Tout push VoIP **doit** déclencher immédiatement un appel CallKit sur
 * l'appareil. Sinon iOS termine l'application, et après quelques récidives lui
 * retire définitivement le droit d'en recevoir. C'est pourquoi ce service
 * n'envoie de VoIP que pour un appel réel — jamais pour un test, une relance ou
 * une notification déguisée.
 */
class ApnsVoipSender
{
    /**
     * Durée de validité du jeton de fournisseur.
     *
     * Apple accepte jusqu'à une heure et **refuse** qu'on en régénère un à
     * chaque requête — au-delà d'une certaine fréquence, il répond
     * `TooManyProviderTokenUpdates`. On le met donc en cache, un peu en deçà de
     * la limite.
     */
    private const TOKEN_TTL = 3000;

    /**
     * Fait sonner un appareil.
     *
     * @param  array<string, mixed>  $payload  charge lue par PushKit
     * @return bool vrai si Apple a accepté
     */
    public function ring(string $deviceToken, array $payload): bool
    {
        try {
            $reponse = $this->post($deviceToken, $payload);
        } catch (Throwable $e) {
            Log::warning('Push VoIP impossible : '.$e->getMessage());

            return false;
        }

        if ($reponse['status'] === 200) {
            return true;
        }

        // Le motif d'Apple est rendu tel quel : `BadDeviceToken` (jeton d'une
        // installation disparue), `Unregistered` (app désinstallée) et
        // `BadTopic` (mauvais identifiant de bundle) appellent trois gestes
        // différents, et les confondre fait chercher au mauvais endroit.
        Log::warning('Push VoIP refusé par Apple', [
            'status' => $reponse['status'],
            'reason' => $reponse['reason'],
        ]);

        return false;
    }

    /**
     * Le jeton d'appareil est-il devenu inutilisable ?
     *
     * Ces deux motifs sont définitifs : l'appareil ne recevra plus jamais rien
     * sur ce jeton, et il faut l'oublier plutôt que de le réessayer à chaque
     * appel.
     */
    public function isTokenDead(string $reason): bool
    {
        return in_array($reason, ['BadDeviceToken', 'Unregistered'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, reason: string}
     */
    private function post(string $deviceToken, array $payload): array
    {
        $hote = config('apns.production')
            ? config('apns.endpoints.production')
            : config('apns.endpoints.sandbox');

        $curl = curl_init("{$hote}/3/device/{$deviceToken}");

        curl_setopt_array($curl, [
            // APNs n'accepte que HTTP/2. Sans cette ligne, la connexion est
            // refusée avant même la moindre vérification.
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'authorization: bearer '.$this->providerToken(),
                'apns-topic: '.config('apns.bundle_id').'.voip',
                'apns-push-type: voip',
                // Priorité maximale et pas de report : un appel qui arrive
                // trois minutes plus tard n'est plus un appel.
                'apns-priority: 10',
                'apns-expiration: '.(time() + 30),
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $corps = curl_exec($curl);
        $statut = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $erreur = curl_error($curl);
        curl_close($curl);

        if ($corps === false) {
            throw new RuntimeException($erreur ?: 'Aucune réponse d\'Apple');
        }

        return [
            'status' => $statut,
            'reason' => json_decode((string) $corps, true)['reason'] ?? '',
        ];
    }

    /**
     * Jeton signé qui identifie Arche auprès d'Apple.
     */
    private function providerToken(): string
    {
        return Cache::remember('arche.apns.provider_token', self::TOKEN_TTL, function () {
            $chemin = config('apns.key_path');

            if (! is_readable($chemin)) {
                throw new RuntimeException(
                    "Clé APNs introuvable ({$chemin}). Sans elle, aucun appel ne "
                    .'peut faire sonner un téléphone verrouillé.',
                );
            }

            return JWT::encode(
                ['iss' => config('apns.team_id'), 'iat' => time()],
                file_get_contents($chemin),
                'ES256',
                config('apns.key_id'),
            );
        });
    }
}
