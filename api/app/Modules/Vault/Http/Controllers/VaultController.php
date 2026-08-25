<?php

namespace App\Modules\Vault\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Vault\Models\VaultAccessLog;
use App\Modules\Vault\Models\VaultEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VaultController extends Controller
{
    private const CATEGORIES = ['database', 'api', 'ssh', 'env', 'password', 'other'];

    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);
        $userId = $this->userId($request);

        $entries = VaultEntry::where('project_id', $project->id)
            ->with(
                'updater:id,email,name,avatar_path',
                'creator:id,email,name,avatar_path',
                'allowedUsers:id,email,name,avatar_path',
            )
            ->orderBy('name')
            ->get()
            ->filter(fn (VaultEntry $e) => $e->isAccessibleBy($userId))
            ->values();

        return response()->json($entries);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'in:'.implode(',', self::CATEGORIES)],
            'username' => ['nullable', 'string', 'max:200'],
            'secret' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'url', 'max:500'],
            'expires_at' => ['nullable', 'date'],
            'visibility' => ['sometimes', 'string', 'in:all,restricted'],
            'allowed_user_ids' => ['sometimes', 'array'],
            'allowed_user_ids.*' => ['uuid', 'exists:users,id'],
        ]);

        $userId = $this->userId($request);
        $allowedIds = $data['allowed_user_ids'] ?? [];
        unset($data['allowed_user_ids']);

        $entry = VaultEntry::create([
            ...$data,
            'visibility' => $data['visibility'] ?? 'all',
            'project_id' => $project->id,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        // Sync allowed users si restricted (filtre les non-membres du projet)
        if (($entry->visibility === 'restricted') && ! empty($allowedIds)) {
            $memberIds = $project->projectMembers()->pluck('user_id')->all();
            $validIds = array_values(array_intersect($allowedIds, $memberIds));
            $entry->allowedUsers()->sync($validIds);
        }

        $this->log($entry->id, $userId, 'created', $request);
        $this->activity->log($project->id, $userId, 'vault.created', $entry, $entry->name);

        $entry->load('allowedUsers:id,email,name,avatar_path');

        return response()->json($entry, 201);
    }

    /**
     * Returns metadata WITHOUT the decrypted secret.
     */
    public function show(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureCanAccess($request, $entry);

        $entry->load(
            'creator:id,email,name,avatar_path',
            'updater:id,email,name,avatar_path',
            'allowedUsers:id,email,name,avatar_path',
        );
        $this->log($entry->id, $this->userId($request), 'viewed', $request);

        return response()->json($entry);
    }

    /**
     * Returns the decrypted secret. Each call is logged.
     */
    public function reveal(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureCanAccess($request, $entry);

        $userId = $this->userId($request);
        $this->log($entry->id, $userId, 'revealed', $request);
        $this->activity->log($entry->project_id, $userId, 'vault.revealed', $entry, $entry->name);

        return response()->json([
            'secret' => $entry->secret, // cast decrypts automatically
        ]);
    }

    public function update(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureCanAccess($request, $entry);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', self::CATEGORIES)],
            'username' => ['nullable', 'string', 'max:200'],
            'secret' => ['sometimes', 'string'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'url', 'max:500'],
            'expires_at' => ['nullable', 'date'],
            'visibility' => ['sometimes', 'string', 'in:all,restricted'],
            'allowed_user_ids' => ['sometimes', 'nullable', 'array'],
            'allowed_user_ids.*' => ['uuid', 'exists:users,id'],
        ]);

        // Modifier la visibilité ou la liste blanche : créateur OU owner du projet
        $isVisibilityChange = $request->has('visibility') || $request->has('allowed_user_ids');
        if ($isVisibilityChange) {
            $this->ensureCanManageVisibility($request, $entry);
        }

        $userId = $this->userId($request);
        $allowedIds = array_key_exists('allowed_user_ids', $data) ? ($data['allowed_user_ids'] ?? []) : null;
        unset($data['allowed_user_ids']);

        $entry->update([...$data, 'updated_by' => $userId]);

        if ($allowedIds !== null) {
            if ($entry->visibility === 'restricted') {
                $memberIds = $entry->project->projectMembers()->pluck('user_id')->all();
                $validIds = array_values(array_intersect($allowedIds, $memberIds));
                $entry->allowedUsers()->sync($validIds);
            } else {
                // En mode 'all' la liste n'a plus de sens — on la vide
                $entry->allowedUsers()->sync([]);
            }
        } elseif (isset($data['visibility']) && $data['visibility'] === 'all') {
            // Switch all sans payload allowed_user_ids → vide la liste pour cohérence
            $entry->allowedUsers()->sync([]);
        }

        $this->log($entry->id, $userId, 'updated', $request);
        $this->activity->log($entry->project_id, $userId, 'vault.updated', $entry, $entry->name);

        $entry->load('allowedUsers:id,email,name,avatar_path');

        return response()->json($entry);
    }

    public function destroy(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureCanAccess($request, $entry);

        $userId = $this->userId($request);
        $name = $entry->name;
        $projectId = $entry->project_id;

        $this->log($entry->id, $userId, 'deleted', $request);
        $entry->delete();

        $this->activity->log($projectId, $userId, 'vault.deleted', null, $name);

        return response()->json(null, 204);
    }

    public function accessLog(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureCanAccess($request, $entry);

        $logs = $entry->accessLogs()
            ->with('user:id,email,name,avatar_path')
            ->limit(200)
            ->get();

        return response()->json($logs);
    }

    private function log(string $entryId, string $userId, string $action, Request $request): void
    {
        VaultAccessLog::create([
            'entry_id' => $entryId,
            'user_id' => $userId,
            'action' => $action,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() ? substr($request->userAgent(), 0, 2000) : null,
            'created_at' => now(),
        ]);
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

    /**
     * L'utilisateur doit être membre du projet ET avoir accès à l'entry
     * (créateur, mode 'all', ou listé dans allowed_users si 'restricted').
     */
    private function ensureCanAccess(Request $request, VaultEntry $entry): void
    {
        $this->ensureMember($request, $entry->project);
        if (! $entry->isAccessibleBy($this->userId($request))) {
            abort(403, 'No access to this vault entry');
        }
    }

    /**
     * Modification de la visibilité ou de la liste blanche : seulement le
     * créateur de l'entry ou un owner du projet.
     */
    private function ensureCanManageVisibility(Request $request, VaultEntry $entry): void
    {
        $userId = $this->userId($request);
        if ($entry->created_by === $userId) {
            return;
        }

        $isOwner = $entry->project->projectMembers()
            ->where('user_id', $userId)
            ->where('role', 'owner')
            ->exists();

        if (! $isOwner) {
            abort(403, 'Only the creator or a project owner can change visibility');
        }
    }
}
