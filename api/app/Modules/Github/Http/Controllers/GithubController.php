<?php

namespace App\Modules\Github\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Github\Models\GithubCommit;
use App\Modules\Github\Models\GithubRepo;
use App\Modules\Github\Services\GithubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class GithubController extends Controller
{
    private const PLATFORMS = ['mobile', 'web', 'api', 'other'];

    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $repos = GithubRepo::where('project_id', $project->id)
            ->with('linker:id,email,name')
            ->withCount('commits')
            ->orderBy('platform')
            ->orderBy('full_name')
            ->get();

        return response()->json($repos);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'platform' => ['required', 'string', 'in:'.implode(',', self::PLATFORMS)],
            'access_token' => ['required', 'string', 'min:10'],
            'default_branch' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $userId = $this->userId($request);

        // Verify token works against the repo before saving
        try {
            $client = new GithubClient($data['access_token']);
            $repoInfo = $client->repo($data['full_name']);
            if ($repoInfo->status() === 401) {
                abort(422, 'Token invalide ou sans accès à ce repo.');
            }
            if ($repoInfo->status() === 404) {
                abort(422, 'Repo introuvable (ou token sans accès).');
            }
            $repoInfo->throw();
            $info = $repoInfo->json();
        } catch (Throwable $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) throw $e;
            abort(422, 'Impossible de contacter GitHub : '.$e->getMessage());
        }

        $repo = GithubRepo::create([
            'project_id' => $project->id,
            'full_name' => $data['full_name'],
            'platform' => $data['platform'],
            'default_branch' => $data['default_branch'] ?? ($info['default_branch'] ?? 'main'),
            'description' => $data['description'] ?? ($info['description'] ?? null),
            'access_token' => $data['access_token'],
            'linked_by' => $userId,
        ]);

        $this->activity->log(
            $project->id,
            $userId,
            'github.repo_linked',
            $repo,
            $repo->full_name,
            ['platform' => $repo->platform],
        );

        // Fire-and-forget initial sync
        try {
            $this->doSync($repo);
        } catch (Throwable) {
            // Initial sync failure shouldn't block the link — user can retry.
        }

        $repo->load('linker:id,email,name');
        $repo->loadCount('commits');

        return response()->json($repo, 201);
    }

    public function update(Request $request, GithubRepo $repo): JsonResponse
    {
        $this->ensureMember($request, $repo->project);

        $data = $request->validate([
            'platform' => ['sometimes', 'string', 'in:'.implode(',', self::PLATFORMS)],
            'default_branch' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'access_token' => ['sometimes', 'string', 'min:10'],
        ]);

        $repo->update($data);
        $repo->load('linker:id,email,name');
        $repo->loadCount('commits');

        return response()->json($repo);
    }

    public function destroy(Request $request, GithubRepo $repo): JsonResponse
    {
        $this->ensureMember($request, $repo->project);
        $name = $repo->full_name;
        $projectId = $repo->project_id;
        $repo->delete();

        $this->activity->log($projectId, $this->userId($request), 'github.repo_unlinked', null, $name);

        return response()->json(null, 204);
    }

    public function sync(Request $request, GithubRepo $repo): JsonResponse
    {
        $this->ensureMember($request, $repo->project);

        try {
            $count = $this->doSync($repo);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Sync failed',
                'detail' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'synced' => $count,
            'last_synced_at' => $repo->fresh()->last_synced_at,
        ]);
    }

    public function commits(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $query = GithubCommit::query()
            ->whereIn('github_repo_id', GithubRepo::where('project_id', $project->id)->pluck('id'))
            ->with('repo:id,full_name,platform')
            ->orderByDesc('authored_at')
            ->limit(100);

        if ($request->filled('repo')) {
            $query->where('github_repo_id', $request->string('repo'));
        }

        return response()->json($query->get());
    }

    /**
     * Fetch latest commits from GitHub and upsert into github_commits.
     */
    private function doSync(GithubRepo $repo): int
    {
        $client = new GithubClient($repo->access_token);
        $items = $client->commits($repo->full_name, $repo->default_branch, 30);

        $count = 0;
        foreach ($items as $item) {
            $sha = $item['sha'] ?? null;
            if (! $sha) continue;

            GithubCommit::updateOrCreate(
                [
                    'github_repo_id' => $repo->id,
                    'sha' => $sha,
                ],
                [
                    'message' => $item['commit']['message'] ?? '',
                    'author_name' => $item['commit']['author']['name'] ?? null,
                    'author_email' => $item['commit']['author']['email'] ?? null,
                    'author_login' => $item['author']['login'] ?? null,
                    'author_avatar_url' => $item['author']['avatar_url'] ?? null,
                    'html_url' => $item['html_url'] ?? null,
                    'authored_at' => $item['commit']['author']['date'] ?? null,
                ],
            );
            $count++;
        }

        $repo->update(['last_synced_at' => now()]);

        return $count;
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }

    private function ensureMember(Request $request, Project $project): void
    {
        if (! $project->hasMember($this->userId($request))) {
            abort(403, 'Not a member of this project');
        }
    }
}
