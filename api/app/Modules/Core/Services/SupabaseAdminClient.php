<?php

namespace App\Modules\Core\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SupabaseAdminClient
{
    private string $url;

    private string $key;

    public function __construct()
    {
        $this->url = rtrim((string) config('supabase.url'), '/');
        $this->key = (string) config('supabase.service_role_key');
    }

    /**
     * Generate an invite link without sending Supabase's default email.
     * Creates the auth user (pending confirmation) and returns the action link.
     *
     * @param  array<string, mixed>  $userMetadata  Stored on auth.users.raw_user_meta_data
     * @return array{user_id: string, action_link: string}
     */
    public function generateInviteLink(string $email, string $redirectTo, array $userMetadata = []): array
    {
        $res = $this->client()->post("{$this->url}/auth/v1/admin/generate_link", [
            'type' => 'invite',
            'email' => $email,
            'data' => $userMetadata,
            'redirect_to' => $redirectTo,
        ]);

        if (! $res->successful()) {
            $body = $res->json() ?? [];
            $msg = $body['msg'] ?? $body['message'] ?? $body['error_description'] ?? $res->body();
            throw new RuntimeException("Supabase admin generate_link failed: {$msg}", $res->status());
        }

        $json = $res->json();

        return [
            'user_id' => $json['user']['id'] ?? throw new RuntimeException('Missing user.id in Supabase response'),
            'action_link' => $json['action_link'] ?? throw new RuntimeException('Missing action_link in Supabase response'),
        ];
    }

    public function deleteUser(string $userId): void
    {
        $res = $this->client()->delete("{$this->url}/auth/v1/admin/users/{$userId}");

        // 404 = already gone, treat as success.
        if ($res->successful() || $res->status() === 404) {
            return;
        }

        $body = $res->json() ?? [];
        $msg = $body['msg'] ?? $body['message'] ?? $res->body();
        throw new RuntimeException("Supabase admin delete user failed: {$msg}", $res->status());
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'apikey' => $this->key,
            'Authorization' => "Bearer {$this->key}",
        ])->acceptJson();
    }
}
