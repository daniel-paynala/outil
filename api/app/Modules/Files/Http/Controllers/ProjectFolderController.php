<?php

namespace App\Modules\Files\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Models\Project;
use App\Modules\Files\Models\ProjectFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectFolderController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Liste à plat tous les dossiers du projet (le frontend reconstruit l'arbre).
     * Inclut les compteurs files_count + children_count.
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $folders = ProjectFolder::where('project_id', $project->id)
            ->withCount(['files', 'children'])
            ->orderBy('name')
            ->get();

        return response()->json($folders);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->ensureMember($request, $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'uuid', 'exists:project_folders,id'],
        ]);

        // Le parent doit appartenir au même projet
        if (! empty($data['parent_id'])) {
            $parent = ProjectFolder::find($data['parent_id']);
            if (! $parent || $parent->project_id !== $project->id) {
                abort(422, 'Invalid parent folder');
            }
        }

        $userId = $this->userId($request);
        $folder = ProjectFolder::create([
            'project_id' => $project->id,
            'parent_id' => $data['parent_id'] ?? null,
            'name' => trim($data['name']),
            'created_by' => $userId,
        ]);

        $this->activity->log(
            $project->id,
            $userId,
            'folder.created',
            $folder,
            $folder->name,
        );

        $folder->files_count = 0;
        $folder->children_count = 0;

        return response()->json($folder, 201);
    }

    public function update(Request $request, ProjectFolder $folder): JsonResponse
    {
        $this->ensureMember($request, $folder->project);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'uuid', 'exists:project_folders,id'],
        ]);

        // Vérifie qu'on ne crée pas de cycle (parent ne peut pas être un descendant)
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            if ($data['parent_id'] === $folder->id) {
                abort(422, 'A folder cannot be its own parent');
            }
            $parent = ProjectFolder::find($data['parent_id']);
            if (! $parent || $parent->project_id !== $folder->project_id) {
                abort(422, 'Invalid parent folder');
            }
            // Walk up to detect cycle
            $cursor = $parent;
            while ($cursor) {
                if ($cursor->id === $folder->id) {
                    abort(422, 'Cannot move a folder inside its own descendants');
                }
                $cursor = $cursor->parent_id ? ProjectFolder::find($cursor->parent_id) : null;
            }
        }

        $folder->update($data);

        $this->activity->log(
            $folder->project_id,
            $this->userId($request),
            'folder.updated',
            $folder,
            $folder->name,
        );

        $folder->loadCount(['files', 'children']);

        return response()->json($folder);
    }

    public function destroy(Request $request, ProjectFolder $folder): JsonResponse
    {
        $this->ensureMember($request, $folder->project);

        $userId = $this->userId($request);
        $name = $folder->name;
        $projectId = $folder->project_id;

        $folder->delete();

        $this->activity->log(
            $projectId,
            $userId,
            'folder.deleted',
            null,
            $name,
        );

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
}
