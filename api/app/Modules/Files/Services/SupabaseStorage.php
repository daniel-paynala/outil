<?php

namespace App\Modules\Files\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class SupabaseStorage
{
    public const BUCKET = 'project-files';

    public const AVATAR_BUCKET = 'avatars';

    private string $url;

    private string $key;

    public function __construct()
    {
        $this->url = rtrim((string) config('supabase.url'), '/');
        $this->key = (string) config('supabase.service_role_key');
    }

    public function ensureBucket(string $bucket = self::BUCKET, bool $public = false): void
    {
        $res = $this->client()->post("{$this->url}/storage/v1/bucket", [
            'id' => $bucket,
            'name' => $bucket,
            'public' => $public,
        ]);

        if ($res->successful()) {
            return;
        }

        // Treat "already exists" (409, or 400 w/ Duplicate) as success.
        $body = $res->json() ?? [];
        $message = strtolower(($body['message'] ?? '').' '.($body['error'] ?? ''));
        if (
            $res->status() === 409
            || str_contains($message, 'already exists')
            || str_contains($message, 'duplicate')
        ) {
            return;
        }

        $res->throw();
    }

    public function upload(string $path, string $contents, string $mime, string $bucket = self::BUCKET, bool $public = false): void
    {
        $this->ensureBucket($bucket, $public);

        $this->client()
            ->withHeaders([
                'Content-Type' => $mime,
                'x-upsert' => 'true',
            ])
            ->withBody($contents, $mime)
            ->post("{$this->url}/storage/v1/object/{$bucket}/{$path}")
            ->throw();
    }

    public function signedUrl(string $path, int $expiresIn = 3600, string $bucket = self::BUCKET): string
    {
        $json = $this->client()
            ->post("{$this->url}/storage/v1/object/sign/{$bucket}/{$path}", [
                'expiresIn' => $expiresIn,
            ])
            ->throw()
            ->json();

        return "{$this->url}/storage/v1{$json['signedURL']}";
    }

    /**
     * URL publique pour un objet d'un bucket public (pas de signature).
     */
    public function publicUrl(string $path, string $bucket = self::AVATAR_BUCKET): string
    {
        return "{$this->url}/storage/v1/object/public/{$bucket}/{$path}";
    }

    public function delete(string $path, string $bucket = self::BUCKET): void
    {
        $this->client()
            ->delete("{$this->url}/storage/v1/object/{$bucket}", [
                'prefixes' => [$path],
            ])
            ->throw();
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'apikey' => $this->key,
            'Authorization' => "Bearer {$this->key}",
        ]);
    }
}
