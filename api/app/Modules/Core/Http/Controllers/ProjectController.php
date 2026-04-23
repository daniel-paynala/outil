<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Project;
use App\Modules\Core\Models\ProjectMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        $projects = Project::query()
            ->whereHas('projectMembers', fn ($q) => $q->where('user_id', $userId))
            ->orderBy('name')
            ->get();

        return response()->json($projects);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $userId = $this->userId($request);

        $project = DB::transaction(function () use ($data, $userId) {
            $project = Project::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'color' => $data['color'] ?? '#737375',
                'created_by' => $userId,
            ]);

            ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $userId,
                'role' => 'owner',
            ]);

            return $project;
        });

        return response()->json($project, 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $project->load(['members', 'creator']);

        return response()->json($project);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->ensureOwner($request, $project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $project->update($data);

        return response()->json($project);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->ensureOwner($request, $project);
        $project->delete();

        return response()->json(null, 204);
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

    private function ensureOwner(Request $request, Project $project): void
    {
        $isOwner = $project->projectMembers()
            ->where('user_id', $this->userId($request))
            ->where('role', 'owner')
            ->exists();

        if (! $isOwner) {
            abort(403, 'Owner role required');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'projet';
        $slug = $base;
        $i = 1;

        while (Project::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
