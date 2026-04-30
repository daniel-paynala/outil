<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('user');

        if (! $user instanceof User || ! $user->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
