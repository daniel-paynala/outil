<?php

namespace App\Modules\Vault\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Project;
use App\Modules\Vault\Models\VaultAccessLog;
use App\Modules\Vault\Models\VaultEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VaultController extends Controller
{
    private const CATEGORIES = ['database', 'api', 'ssh', 'env', 'password', 'other'];

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $entries = VaultEntry::where('project_id', $project->id)
            ->with('updater:id,email,name', 'creator:id,email,name')
            ->orderBy('name')
            ->get();

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
        ]);

        $userId = $this->userId($request);

        $entry = VaultEntry::create([
            ...$data,
            'project_id' => $project->id,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->log($entry->id, $userId, 'created', $request);

        return response()->json($entry, 201);
    }

    /**
     * Returns metadata WITHOUT the decrypted secret.
     */
    public function show(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureMember($request, $entry->project);

        $entry->load('creator:id,email,name', 'updater:id,email,name');
        $this->log($entry->id, $this->userId($request), 'viewed', $request);

        return response()->json($entry);
    }

    /**
     * Returns the decrypted secret. Each call is logged.
     */
    public function reveal(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureMember($request, $entry->project);

        $this->log($entry->id, $this->userId($request), 'revealed', $request);

        return response()->json([
            'secret' => $entry->secret, // cast decrypts automatically
        ]);
    }

    public function update(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureMember($request, $entry->project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', self::CATEGORIES)],
            'username' => ['nullable', 'string', 'max:200'],
            'secret' => ['sometimes', 'string'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'url', 'max:500'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $userId = $this->userId($request);
        $entry->update([...$data, 'updated_by' => $userId]);

        $this->log($entry->id, $userId, 'updated', $request);

        return response()->json($entry);
    }

    public function destroy(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureMember($request, $entry->project);

        $this->log($entry->id, $this->userId($request), 'deleted', $request);
        $entry->delete();

        return response()->json(null, 204);
    }

    public function accessLog(Request $request, VaultEntry $entry): JsonResponse
    {
        $this->ensureMember($request, $entry->project);

        $logs = $entry->accessLogs()
            ->with('user:id,email,name')
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
}
