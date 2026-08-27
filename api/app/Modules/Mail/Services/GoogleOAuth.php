<?php

namespace App\Modules\Mail\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Échange et rafraîchissement des jetons Google.
 *
 * Volontairement réduit à deux opérations. Une bibliothèque OAuth complète
 * apporterait la découverte de points d'entrée, la gestion d'états et une
 * douzaine de flux dont aucun ne nous sert : Google publie des adresses stables
 * et l'appareil a déjà fait la partie interactive.
 */
class GoogleOAuth
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Durée de conservation d'un jeton d'accès — Google en accorde 3600 s. */
    private const TOKEN_TTL = 3000;

    /**
     * Transforme le code d'autorisation rendu par l'appareil en jetons.
     *
     * ## Pourquoi l'appareil ne fait pas cet échange lui-même
     *
     * Parce que le jeton de rafraîchissement ne doit exister que là où il sert.
     * L'appareil obtient de son côté un jeton d'accès pour parler à Gmail
     * directement ; le rafraîchissement, lui, n'a d'utilité que pour la
     * surveillance côté serveur, qui doit survivre à l'app fermée.
     *
     * L'échange exige le secret client, qui n'a rien à faire dans un binaire
     * distribué — n'importe qui peut l'extraire d'un APK.
     *
     * @return array{refresh_token: string, access_token: string, scope: string}
     */
    public function exchange(string $serverAuthCode): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $serverAuthCode,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            // Les codes issus des SDK mobiles s'échangent sans URI de retour.
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json()));
        }

        $data = $response->json();

        // Google ne rend un jeton de rafraîchissement qu'à la première
        // autorisation. Sans lui, la surveillance s'éteindrait à l'expiration
        // du jeton d'accès, une heure plus tard — mieux vaut refuser tout de
        // suite et faire révoquer l'accès pour repartir proprement.
        if (empty($data['refresh_token'])) {
            throw new RuntimeException(
                "Google n'a pas rendu de jeton de rafraîchissement. L'accès a "
                .'probablement déjà été accordé : le révoquer sur '
                .'myaccount.google.com/permissions, puis reconnecter.',
            );
        }

        return [
            'refresh_token' => $data['refresh_token'],
            'access_token' => $data['access_token'] ?? '',
            'scope' => $data['scope'] ?? '',
        ];
    }

    /**
     * Obtient un jeton d'accès à partir du jeton de rafraîchissement.
     *
     * ## Pourquoi il est mis en cache
     *
     * Les boîtes sont relevées toutes les deux minutes. Sans cache, chaque
     * relève commencerait par un échange contre le point d'entrée de jetons de
     * Google — trente par heure et par personne, pour un jeton valable une
     * heure. Du gaspillage, et surtout une dépendance inutile à un service
     * dont l'indisponibilité passagère suffirait à interrompre la relève.
     *
     * Cinquante minutes : Google en accorde soixante, et la marge couvre une
     * relève commencée juste avant l'expiration.
     */
    public function accessToken(string $refreshToken): string
    {
        $cle = 'arche.google.token.'.hash('sha256', $refreshToken);

        $cache = Cache::get($cle);
        if (is_string($cache) && $cache !== '') {
            return $cache;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json()));
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Réponse de Google sans jeton d\'accès.');
        }

        Cache::put($cle, $token, self::TOKEN_TTL);

        return $token;
    }

    /**
     * Écarte le jeton mis en cache pour ce compte.
     *
     * À appeler quand Gmail refuse un appel : le jeton peut avoir été révoqué
     * avant son expiration — mot de passe changé, accès retiré depuis le compte
     * Google. Sans cet oubli, on rejouerait le même jeton mort pendant
     * cinquante minutes.
     */
    public function forgetToken(string $refreshToken): void
    {
        Cache::forget('arche.google.token.'.hash('sha256', $refreshToken));
    }

    /**
     * Révoque l'accès côté Google.
     *
     * Appelée à la déconnexion. Supprimer notre ligne sans révoquer laisserait
     * l'autorisation active dans le compte Google de la personne : elle croirait
     * avoir coupé l'accès alors qu'il suffirait de reconnecter pour le
     * retrouver.
     */
    public function revoke(string $refreshToken): void
    {
        // L'échec est ignoré volontairement : un jeton déjà révoqué renvoie une
        // erreur, et la déconnexion locale doit aboutir dans tous les cas.
        Http::asForm()->post(self::REVOKE_URL, ['token' => $refreshToken]);
    }

    /** @param  array<string, mixed>|null  $body */
    private function reason(?array $body): string
    {
        $code = $body['error'] ?? 'erreur inconnue';
        $detail = $body['error_description'] ?? null;

        return $detail ? "{$code} — {$detail}" : (string) $code;
    }

    private function clientId(): string
    {
        return config('google.client_id')
            ?: throw new RuntimeException(
                'GOOGLE_CLIENT_ID absent du `.env` : la connexion Gmail ne peut '
                .'pas fonctionner.',
            );
    }

    private function clientSecret(): string
    {
        return config('google.client_secret')
            ?: throw new RuntimeException('GOOGLE_CLIENT_SECRET absent du `.env`.');
    }
}
