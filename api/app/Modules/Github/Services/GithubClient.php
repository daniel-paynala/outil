<?php

namespace App\Modules\Github\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GithubClient
{
    private const BASE = 'https://api.github.com';

    public function __construct(private readonly string $token) {}

    public function repo(string $fullName): Response
    {
        return $this->http()->get(self::BASE."/repos/{$fullName}");
    }

    /**
     * Fetch the most recent commits on the default branch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function commits(string $fullName, string $branch = 'main', int $perPage = 30): array
    {
        $res = $this->http()->get(self::BASE."/repos/{$fullName}/commits", [
            'sha' => $branch,
            'per_page' => $perPage,
        ])->throw();

        return $res->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'outil-paynala',
        ])->timeout(15);
    }
}
