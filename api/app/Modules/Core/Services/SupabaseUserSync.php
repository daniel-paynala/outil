<?php

namespace App\Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Réplique locale des comptes Supabase.
 *
 * Supabase tient l'authentification, mais nos tables ont besoin d'une ligne
 * `users` réelle : toutes les clés étrangères d'Arche (assignation de tâche,
 * membre de projet, auteur de message) la référencent. Cette classe garantit
 * qu'elle existe et reste à jour à partir des claims du JWT présenté.
 *
 * ## Pourquoi un cache, et ce qu'il coûte vraiment
 *
 * Cette méthode s'exécute sur **chaque requête authentifiée** — c'est le
 * chemin le plus chaud de l'API. Elle n'y émettait qu'une seule requête (le
 * SELECT d'`updateOrCreate` ; l'UPDATE ne part que si quelque chose a changé,
 * ce qui n'arrive quasiment jamais). Une seule, mais mesurée à **160 ms**
 * depuis Libreville : l'API est chez OVH, la base chez Supabase Francfort, et
 * chaque aller-retour se paie au prix fort.
 *
 * Réduire le nombre de requêtes ne servait donc à rien — il fallait supprimer
 * l'aller-retour. C'est ce que fait ce cache : en régime établi, une requête
 * authentifiée ne touche plus la base du tout pour s'authentifier.
 *
 * **Le gain dépend entièrement du magasin de cache.** Avec `CACHE_STORE=redis`
 * (Redis tourne déjà dans la compose de production, sur le même hôte que
 * l'API : accès sub-milliseconde) l'aller-retour disparaît. Avec
 * `CACHE_STORE=database`, le cache *est* la base distante et l'opération reste
 * neutre — correcte, mais sans gain. Voir `deploy/docker-compose.prod.yml`.
 */
class SupabaseUserSync
{
    /**
     * Durée de validité d'un instantané de compte.
     *
     * Compromis assumé : au-delà, le rôle et les préférences d'une personne
     * pourraient être lus périmés. Une minute couvre largement la rafale
     * d'appels que déclenche l'ouverture de l'app — le cas qui compte — tout
     * en gardant une révocation de droits quasi immédiate. Les changements que
     * nous provoquons nous-mêmes n'attendent pas ce délai : ils appellent
     * `forget()`.
     */
    private const TTL_SECONDS = 60;

    /**
     * Crée ou rafraîchit la ligne `users` correspondant au porteur du jeton.
     *
     * @param  array<string, mixed>  $claims  charge utile du JWT vérifié
     */
    public function syncFromClaims(array $claims): ?User
    {
        $id = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;

        if (! $id || ! $email) {
            return null;
        }

        try {
            $cached = Cache::get(self::key($id));
        } catch (Throwable) {
            // Cache indisponible : on retombe sur la base. Une panne de cache
            // ne doit jamais déconnecter l'équipe.
            $cached = null;
        }

        // L'e-mail est la seule donnée du JWT qui puisse invalider
        // l'instantané : s'il a changé côté Supabase, il faut réécrire.
        if (is_array($cached) && ($cached['email'] ?? null) === $email) {
            return (new User)->newFromBuilder($cached);
        }

        $user = User::updateOrCreate(
            ['id' => $id],
            [
                'email' => $email,
                'metadata' => [
                    'app_metadata' => $claims['app_metadata'] ?? null,
                    'user_metadata' => $claims['user_metadata'] ?? null,
                    'aud' => $claims['aud'] ?? null,
                    'iss' => $claims['iss'] ?? null,
                ],
            ],
        );

        self::remember($user);

        return $user;
    }

    /**
     * Oublie l'instantané d'un compte.
     *
     * À appeler dès qu'Arche modifie une colonne de `users` — rôle, nom,
     * avatar, préférences de notification. Sans cela, la personne verrait son
     * propre changement ignoré pendant une minute, ce qui se lit comme un bug.
     */
    public static function forget(string $id): void
    {
        try {
            Cache::forget(self::key($id));
        } catch (Throwable) {
            // Sans cache, rien à oublier.
        }
    }

    /** Enregistre l'instantané tel qu'il sera relu par `newFromBuilder`. */
    private static function remember(User $user): void
    {
        try {
            Cache::put(self::key($user->id), $user->getRawOriginal(), self::TTL_SECONDS);
        } catch (Throwable) {
            // Écriture de cache best-effort : jamais bloquante.
        }
    }

    private static function key(string $id): string
    {
        return "arche.user.{$id}";
    }
}
