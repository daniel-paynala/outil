<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Monitoring\Support\Capability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un droit précis, au-delà du couple membre/administrateur.
 *
 * S'emploie avec le nom du droit :
 *
 *     Route::middleware('can:monitoring')->group(…)
 *
 * ## Pourquoi 404 et non 403
 *
 * Un 403 confirme que la ressource existe et qu'on n'y a pas droit. Pour la
 * supervision, cela dirait à qui n'y a pas accès qu'Arche surveille des bases,
 * combien il y en a, et à quelles adresses répondre. On rend donc la même chose
 * qu'une route inexistante.
 *
 * Le compromis est assumé : un membre légitime privé de son droit par erreur
 * verra « introuvable » plutôt qu'« interdit », ce qui est plus déroutant. Mais
 * l'app lui masque déjà le menu entier, il ne devrait donc jamais atteindre
 * cette route.
 */
class EnsureCapability
{
    public function handle(
        Request $request,
        Closure $next,
        string $capability,
    ): Response {
        $droit = Capability::tryFrom($capability);
        $user = $request->attributes->get('user');

        if ($droit === null || ! $user instanceof User || ! $user->can($droit)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return $next($request);
    }
}
