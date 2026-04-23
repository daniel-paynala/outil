<?php

namespace App\Modules\Core\Services;

use App\Models\User;

class SupabaseUserSync
{
    /**
     * Ensure a local users row exists for the Supabase-authenticated caller,
     * keeping email and metadata in sync with the JWT claims.
     *
     * @param  array<string, mixed>  $claims  Decoded JWT claims.
     */
    public function syncFromClaims(array $claims): ?User
    {
        $id = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;

        if (! $id || ! $email) {
            return null;
        }

        return User::updateOrCreate(
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
    }
}
