<?php

namespace App\Modules\Files\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class SupabaseStorage
{
    public const BUCKET = 'project-files';

    private string $url;

    private string $key;

    public function __construct()
    {
        $this->url = rtrim((string) config('supabase.url'), '/');
        $this->key = (string) config('supabase.service_role_key');
    }

    public function ensureBucket(string $bucket = self::BUCKET): void
    {
        $res = $this->client()->get("{$this->url}/storage/v1/bucket/{$bucket}");

        if ($res->status() === 404) {
            $this->client()->post("{$this->url}/storage/v1/bucket", [
                'id' => $bucket,
                'name' => $bucket,
                'public' => false,
            ])->throw();
        }
    }

    public function upload(string $path, string $contents, string $mime, string $bucket = self::BUCKET): void
    {
        $this->ensureBucket($bucket);

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
