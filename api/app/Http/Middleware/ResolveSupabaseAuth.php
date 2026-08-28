<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reconnaît l'appelant s'il se présente, sans jamais le refuser.
 *
 * ## À quoi ça sert
 *
 * Les sondes de supervision doivent rester consultables sans authentification
 * — c'est précisément quand l'authentification tombe qu'on a besoin de les
 * lire. Mais elles n'ont pas à livrer les mêmes détails à tout le monde :
 * l'équipe veut des chiffres, un inconnu n'a besoin que d'un état.
 *
 * D'où ce middleware, qui ne garde pas la porte mais dit qui entre. Le
 * contrôleur décide ensuite de ce qu'il montre.
 */
class ResolveSupabaseAuth extends EnsureSupabaseAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Un jeton absent, expiré ou faux ne change rien au déroulement : on
        // continue sans identité. Le refus est le métier de `supabase.auth`,
        // pas le nôtre.
        try {
            $this->attach($request);
        } catch (\Throwable) {
            // Jeton expiré ou falsifié : on poursuit en anonyme.
        }

        return $next($request);
    }
}
