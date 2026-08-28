<?php

namespace App\Http\Middleware;

use App\Modules\Core\Services\SupabaseUserSync;
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
    public function __construct(private readonly SupabaseUserSync $sync) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $this->attach($request);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Invalid token',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 401);
        }

        return $next($request);
    }

    /**
     * Vérifie le jeton et attache l'identité à la requête.
     *
     * Séparé de `handle` pour que `ResolveSupabaseAuth` puisse reconnaître un
     * appelant sans pouvoir le refuser — voir la note de cette classe-là.
     *
     * Rend `false` quand aucun jeton n'est présenté ; lève quand un jeton est
     * présenté mais invalide, parce que ces deux situations n'appellent pas la
     * même réponse.
     */
    protected function attach(Request $request): bool
    {
        $token = $request->bearerToken();
        if (! $token) {
            return false;
        }

        $claims = (array) $this->verify($token);
        $user = $this->sync->syncFromClaims($claims);

        $request->attributes->set('supabase_user', $claims);
        $request->attributes->set('supabase_user_id', $claims['sub'] ?? null);
        $request->attributes->set('user', $user);

        return true;
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
