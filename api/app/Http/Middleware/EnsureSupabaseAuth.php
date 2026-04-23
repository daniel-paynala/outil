<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupabaseAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $decoded = JWT::decode(
                $token,
                new Key(config('supabase.jwt_secret'), 'HS256')
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $claims = (array) $decoded;
        $request->attributes->set('supabase_user', $claims);
        $request->attributes->set('supabase_user_id', $claims['sub'] ?? null);

        return $next($request);
    }
}
