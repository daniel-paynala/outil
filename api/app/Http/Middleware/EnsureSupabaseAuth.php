<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            $decoded = $this->verify($token);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Invalid token',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 401);
        }

        $claims = (array) $decoded;
        $request->attributes->set('supabase_user', $claims);
        $request->attributes->set('supabase_user_id', $claims['sub'] ?? null);

        return $next($request);
    }

    private function verify(string $token): object
    {
        $alg = $this->headerAlg($token);

        if ($alg === 'HS256') {
            return JWT::decode($token, new Key(config('supabase.jwt_secret'), 'HS256'));
        }

        return JWT::decode($token, $this->jwks());
    }

    /**
     * @return array<string, Key>
     */
    private function jwks(): array
    {
        return Cache::remember('supabase.jwks', 3600, function () {
            $url = rtrim(config('supabase.url'), '/').'/auth/v1/.well-known/jwks.json';
            $payload = Http::get($url)->throw()->json();

            return JWK::parseKeySet($payload);
        });
    }

    private function headerAlg(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            throw new \RuntimeException('Malformed token');
        }

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);

        return $header['alg'] ?? 'unknown';
    }
}
