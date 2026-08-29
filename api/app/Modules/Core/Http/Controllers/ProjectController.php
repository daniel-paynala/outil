<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Core\Models\ProjectMember;
use App\Modules\Tasks\Models\Card;
use App\Modules\Tasks\Models\Column;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);

        $projects = Project::query()
            ->whereHas('projectMembers', fn ($q) => $q->where('user_id', $userId))
            ->orderBy('name')
            ->get();

        // Compute per-user task stats by column position (first = todo, last = done, middle = doing)
        $projectIds = $projects->pluck('id')->all();

        $columnsByProject = Column::whereIn('project_id', $projectIds)
            ->orderBy('position')
            ->get(['id', 'project_id', 'position', 'is_done'])
            ->groupBy('project_id');

        $cardCountsByColumn = Card::whereIn('project_id', $projectIds)
            ->where('assigned_to', $userId)
            ->whereNull('deleted_at')
            ->groupBy('column_id')
            ->select('column_id', DB::raw('count(*) as count'))
            ->pluck('count', 'column_id');

        foreach ($projects as $project) {
            $cols = $columnsByProject->get($project->id);
            $stats = ['todo' => 0, 'doing' => 0, 'done' => 0];
            if ($cols && $cols->count() > 0) {
                $firstNonDoneId = $cols->firstWhere('is_done', false)?->id;
                foreach ($cols as $col) {
                    $count = (int) ($cardCountsByColumn[$col->id] ?? 0);
                    if ($count === 0) {
                        continue;
                    }
                    if ($col->is_done) {
                        $stats['done'] += $count;
                    } elseif ($col->id === $firstNonDoneId) {
                        $stats['todo'] += $count;
                    } else {
                        $stats['doing'] += $count;
                    }
                }
            }
            $project->user_stats = $stats;
        }

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

            $defaults = [
                ['name' => 'À faire', 'position' => 0, 'is_done' => false],
                ['name' => 'En cours', 'position' => 1, 'is_done' => false],
                ['name' => 'Terminé', 'position' => 2, 'is_done' => true],
            ];
            foreach ($defaults as $c) {
                Column::create([
                    'project_id' => $project->id,
                    'name' => $c['name'],
                    'position' => $c['position'],
                    'is_done' => $c['is_done'],
                ]);
            }

            return $project;
        });

        $this->activity->log($project->id, $userId, 'project.created', $project);

        return response()->json($project, 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $project->load(['members', 'creator']);

        $userId = $this->userId($request);
        $user = $request->attributes->get('user');
        $isPlatformAdmin = $user && $user->role === 'admin';
        $isCreator = $project->created_by === $userId;

        $payload = $project->toArray();
        $payload['can_delete'] = $isPlatformAdmin || $isCreator;

        return response()->json($payload);
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

        $this->activity->log($project->id, $this->userId($request), 'project.updated', $project);

        return response()->json($project);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->ensureCanDelete($request, $project);

        $name = $project->name;
        $project->delete();

        $this->activity->logGlobal(
            $this->userId($request),
            'project.deleted',
            null,
            $name,
            ['project_id' => $project->id],
        );

        return response()->json(null, 204);
    }

    /**
     * Suppression réservée à : admin plateforme OU créateur du projet.
     * Les autres owners du projet ne peuvent pas supprimer.
     */
    private function ensureCanDelete(Request $request, Project $project): void
    {
        $userId = $this->userId($request);
        $user = $request->attributes->get('user');

        $isPlatformAdmin = $user && $user->role === 'admin';
        $isCreator = $project->created_by === $userId;

        if (! $isPlatformAdmin && ! $isCreator) {
            abort(403, 'Only platform admin or project creator can delete this project');
        }
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
